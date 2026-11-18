<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\ChecklistItem;
use App\Models\Role;
use App\Models\UpdatePlan;
use App\Models\User;
use App\Models\WorkRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    public static function roles(): array
    {
        return [
            'Admin' => ['Admin', 302, 403, 422],
            'Operator' => ['Operator', 302, 403, 302],
            'Reviewer' => ['Reviewer', 403, 302, 403],
            'Viewer' => ['Viewer', 403, 403, 403],
        ];
    }

    #[DataProvider('roles')]
    public function test_roles_can_read_and_only_authorized_roles_can_write_changes_or_work(
        string $role, int $write, int $approve, int $execute,
    ): void {
        $actor = $this->user($role);
        $initiator = $this->user('Operator');
        $asset = Asset::create(['asset_code' => 'MATRIX-ASSET', 'name' => 'Inventory', 'asset_type' => 'Server', 'environment' => 'Production']);
        $plan = $this->plan($asset, $initiator, ['operator_id' => $initiator->id]);
        $this->actingAs($actor);
        foreach (['assets.index', 'vulnerabilities.index', 'audit-events.index', 'work-records.index', 'update-plans.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('assets.show', $asset))->assertOk()->assertSee($asset->asset_code);
        $this->get(route('update-plans.create'))->assertStatus($write === 302 ? 200 : 403);
        $this->post(route('update-plans.store'), ['title' => 'Authorized entry', 'impact' => 'Scope', 'asset_ids' => [$asset->id]])
            ->assertStatus($write);
        $this->assertSame($write === 302, UpdatePlan::where('title', 'Authorized entry')->exists());
        $this->put(route('update-plans.update', $plan), ['impact' => 'Revised scope', 'created_by' => $actor->id])->assertStatus($write);
        $this->assertSame($write === 302 ? 'Revised scope' : 'Scope', $plan->fresh()->impact);
        $this->assertSame($initiator->id, $plan->fresh()->created_by);
        $this->post(route('update-plans.approve', $plan), ['decision' => 'APPROVED', 'approver_id' => $initiator->id])
            ->assertStatus($approve);
        $this->assertSame($approve === 302, $plan->approvals()->where('approver_id', $actor->id)->exists());

        $scheduled = $this->plan($asset, $initiator, [
            'status' => 'SCHEDULED', 'operator_id' => $actor->id, 'backup_reference' => 'DEMO-BACKUP',
            'backup_verified' => true, 'restore_test_passed' => true, 'planned_start_at' => now(), 'planned_end_at' => now()->addHour(),
        ]);
        $this->postJson(route('update-plans.transition', $scheduled), ['status' => 'EXECUTING', 'operator_id' => $initiator->id])
            ->assertStatus($execute);
        $this->assertSame($execute === 302 ? 'EXECUTING' : 'SCHEDULED', $scheduled->fresh()->status);
        $this->post(route('work-records.store'), [
            'record_type' => 'Verification', 'asset_id' => $asset->id, 'summary' => 'Manual evidence', 'action_summary' => 'Reviewed inventory',
            'environment' => 'DEMO', 'result' => 'Success', 'started_at' => now()->toDateTimeString(), 'performed_at' => now()->toDateTimeString(),
        ])->assertStatus($write);
        $this->assertSame($write === 302, WorkRecord::where('asset_id', $asset->id)->where('summary', 'Manual evidence')->exists());
    }

    public function test_admin_can_write_inventory_and_only_the_assigned_operator_can_start_a_high_risk_change(): void
    {
        $admin = $this->user('Admin');
        $this->actingAs($admin)->post(route('assets.store'), [
            'asset_code' => 'ADMIN-NEW', 'name' => 'Admin entry', 'asset_type' => 'Server', 'operational_status' => 'Active',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $asset = Asset::where('asset_code', 'ADMIN-NEW')->sole();
        $this->put(route('assets.update', $asset), [
            'asset_code' => $asset->asset_code, 'name' => 'Corrected asset', 'asset_type' => 'Server', 'operational_status' => 'Active',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Corrected asset', $asset->fresh()->name);

        $plan = $this->plan($asset, $admin, [
            'status' => 'SCHEDULED', 'operator_id' => $admin->id, 'backup_reference' => 'DEMO-BACKUP', 'backup_verified' => true,
            'restore_test_passed' => true, 'planned_start_at' => now(), 'planned_end_at' => now()->addHour(),
        ]);
        $execute = route('update-plans.transition', $plan);
        $this->actingAs($this->user('Operator'))->postJson($execute, ['status' => 'EXECUTING'])->assertUnprocessable();
        $this->assertNull($plan->fresh()->actual_start_at);
        $this->actingAs($admin)->put(route('settings.users.roles.update', $admin), ['roles' => ['Admin', 'Operator']])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->post($execute, ['status' => 'EXECUTING'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('update_plans', ['id' => $plan->id, 'status' => 'EXECUTING', 'operator_id' => $admin->id]);
    }

    public function test_dual_role_needs_a_scoped_admin_grant_and_cannot_approve_its_own_execution(): void
    {
        $admin = $this->user('Admin');
        $operator = $this->user('Operator');
        $reviewer = $this->user('Reviewer');
        $reviewer->roles()->attach(Role::whereName('Operator')->sole());
        $asset = Asset::create(['asset_code' => 'GRANT', 'name' => 'Demo', 'asset_type' => 'Server']);
        $plan = $this->plan($asset, $operator, ['operator_id' => $operator->id]);
        $approve = route('update-plans.approve', $plan);
        $roles = route('settings.users.roles.update', $reviewer);
        $decision = ['decision' => 'APPROVED', 'grant_scope' => User::CHANGE_APPROVAL_GRANT, 'granted_by' => $admin->id];

        $this->actingAs($reviewer)->postJson($approve, $decision)->assertForbidden();
        $this->actingAs($admin)->putJson($roles, ['roles' => ['Operator', 'Reviewer']])->assertUnprocessable()->assertJsonValidationErrors('grant_reason');
        $this->put($roles, ['roles' => ['Operator', 'Reviewer'], 'grant_reason' => 'Delegated change approval', 'granted_by' => $operator->id])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('role_user', [
            'user_id' => $reviewer->id, 'role_id' => Role::whereName('Reviewer')->sole()->id,
            'granted_by' => $admin->id, 'grant_reason' => 'Delegated change approval', 'grant_scope' => User::CHANGE_APPROVAL_GRANT,
        ]);
        $this->assertDatabaseHas('audit_events', ['user_id' => $admin->id, 'auditable_id' => $reviewer->id, 'event_type' => 'roles_updated']);
        $this->actingAs($reviewer)->post($approve, $decision)->assertRedirect()->assertSessionHasNoErrors();
        $selfExecution = $this->plan($asset, $operator, ['operator_id' => $reviewer->id]);
        $this->postJson(route('update-plans.approve', $selfExecution), $decision)->assertForbidden();
        $this->assertDatabaseCount('change_approvals', 1);
    }

    public function test_reviewer_can_record_a_scoped_waiver_but_cannot_modify_execution_evidence(): void
    {
        $operator = $this->user('Operator');
        $reviewer = $this->user('Reviewer');
        $asset = Asset::create(['asset_code' => 'WAIVER', 'name' => 'Demo', 'asset_type' => 'Server']);
        $plan = $this->plan($asset, $operator, ['status' => 'ASSESSMENT']);
        $pending = $plan->checklistItems()->create(['phase' => ChecklistItem::PHASES[1], 'title' => 'Exception', 'status' => 'Pending']);
        $passed = $plan->checklistItems()->create([
            'phase' => ChecklistItem::PHASES[1], 'title' => 'Inspected', 'status' => 'Passed', 'evidence' => 'Original', 'completed_by' => $operator->id,
        ]);
        $other = $plan->checklistItems()->create(['phase' => ChecklistItem::PHASES[1], 'title' => 'Still needs review']);
        $url = fn (ChecklistItem $item) => route('checklist-items.status', $item);
        $waiver = ['status' => 'Waived', 'exception_reason' => 'Inspected separately', 'waiver_scope' => 'item', 'evidence' => 'Untrusted payload'];

        $this->actingAs($operator)->postJson($url($pending), $waiver)->assertForbidden();
        $this->actingAs($reviewer)->postJson($url($passed), ['status' => 'Passed', 'evidence' => 'Changed'])->assertForbidden();
        $this->postJson($url($passed), $waiver)->assertForbidden();
        $this->assertSame('Original', $passed->fresh()->evidence);
        $this->postJson($url($pending), ['status' => 'Waived'])->assertUnprocessable()->assertJsonValidationErrors(['exception_reason', 'waiver_scope']);
        $this->postJson($url($pending), ['waiver_scope' => 'environment'] + $waiver)->assertUnprocessable()->assertJsonValidationErrors('waiver_scope');
        $this->post($url($pending), $waiver)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('update_checklist_items', [
            'id' => $pending->id, 'status' => 'Waived', 'waiver_scope' => 'item', 'waived_by' => $reviewer->id, 'evidence' => null, 'completed_by' => null,
        ]);
        $this->actingAs($operator)->postJson(route('update-plans.transition', $plan), ['status' => 'TESTING'])
            ->assertUnprocessable()->assertJsonValidationErrors('checklist');
        $this->post($url($other), ['status' => 'Passed', 'evidence' => 'Verified'])->assertRedirect();
        $this->post(route('update-plans.transition', $plan), ['status' => 'TESTING'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('TESTING', $plan->fresh()->status);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => $role]));

        return $user;
    }

    private function plan(Asset $asset, User $initiator, array $fields = []): UpdatePlan
    {
        $plan = UpdatePlan::create($fields + [
            'plan_code' => 'PLAN-'.bin2hex(random_bytes(4)), 'title' => 'High-risk change', 'risk_level' => 'High',
            'status' => 'PENDING_APPROVAL', 'created_by' => $initiator->id, 'impact' => 'Scope',
        ]);
        $plan->assets()->attach($asset->id);

        return $plan;
    }
}
