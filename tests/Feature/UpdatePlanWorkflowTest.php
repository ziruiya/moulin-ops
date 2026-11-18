<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetComponent;
use App\Models\ChecklistItem;
use App\Models\Role;
use App\Models\UpdatePlan;
use App\Models\User;
use App\Services\UpdatePlanTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UpdatePlanWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => 'Operator']));

        return $user;
    }

    private function plan(User $user): UpdatePlan
    {
        $asset = Asset::create(['asset_code' => 'ASSET-1', 'name' => 'Demo', 'asset_type' => 'Server']);
        $plan = UpdatePlan::create(['plan_code' => 'CHG-TEST', 'title' => 'Test patch', 'impact' => '調査済', 'created_by' => $user->id]);
        $plan->assets()->attach($asset->id);

        return $plan;
    }

    public function test_failed_checklist_item_cannot_be_directly_passed(): void
    {
        $user = $this->operator();
        $plan = $this->plan($user);
        $item = ChecklistItem::create(['update_plan_id' => $plan->id, 'phase' => '2 Pre-change Validation', 'title' => 'test', 'status' => 'Failed']);
        $this->actingAs($user)->postJson(route('checklist-items.status', $item), ['status' => 'Passed', 'evidence' => 'patched'])->assertUnprocessable();
        $this->assertDatabaseHas('update_checklist_items', ['id' => $item->id, 'status' => 'Failed']);
    }

    public function test_blocked_plan_records_and_only_resumes_to_prior_state(): void
    {
        $user = $this->operator();
        $plan = $this->plan($user);
        $this->actingAs($user);
        app(UpdatePlanTransition::class)->transition($plan, 'ASSESSMENT');
        $blocked = app(UpdatePlanTransition::class)->transition($plan, 'BLOCKED');
        $this->assertSame('ASSESSMENT', $blocked->blocked_from_status);
        try {
            app(UpdatePlanTransition::class)->transition($blocked, 'TESTING');
            $this->fail('Blocked status must not jump to a different state.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
        $resumed = app(UpdatePlanTransition::class)->transition($blocked, 'ASSESSMENT');
        $this->assertSame('ASSESSMENT', $resumed->status);
        $this->assertNull($resumed->blocked_from_status);
    }

    public function test_component_from_another_asset_is_rejected_without_creating_a_plan(): void
    {
        $user = $this->operator();
        $asset = Asset::create(['asset_code' => 'ASSET-A', 'name' => 'A', 'asset_type' => 'Server']);
        $other = Asset::create(['asset_code' => 'ASSET-B', 'name' => 'B', 'asset_type' => 'Server']);
        $component = AssetComponent::create(['asset_id' => $other->id, 'component_type' => 'Runtime', 'product_name' => 'PHP', 'version' => '8.1']);

        $this->actingAs($user)->postJson(route('update-plans.store'), [
            'title' => 'Cross asset test', 'impact' => '調査済', 'asset_ids' => [$asset->id], 'component_ids' => [$component->id],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('update_plans', 0);
        $this->assertDatabaseCount('update_plan_components', 0);
    }

    public function test_rollback_failure_and_incident_creation_share_a_transaction(): void
    {
        $user = $this->operator();
        $plan = $this->plan($user);
        $plan->forceFill(['status' => 'ROLLING_BACK'])->save();
        $this->actingAs($user);

        app(UpdatePlanTransition::class)->transition($plan, 'ROLLBACK_FAILED', [
            'incident' => ['title' => 'Rollback failed', 'description' => 'Failure'],
        ]);

        $this->assertDatabaseHas('update_plans', ['id' => $plan->id, 'status' => 'ROLLBACK_FAILED']);
        $this->assertDatabaseHas('incidents', ['title' => 'Rollback failed', 'status' => 'NEW']);
        $this->assertDatabaseHas('incident_update_plans', ['update_plan_id' => $plan->id]);
    }

    public function test_incident_insert_failure_rolls_back_plan_transition_and_records(): void
    {
        $user = $this->operator();
        $plan = $this->plan($user);
        $plan->forceFill(['status' => 'ROLLING_BACK'])->save();
        $this->actingAs($user);
        DB::connection()->beforeExecuting(function (string $query): void {
            if (str_starts_with(strtolower(ltrim($query)), 'insert into `incidents`')) {
                throw new \RuntimeException('Injected incident creation failure.');
            }
        });

        try {
            app(UpdatePlanTransition::class)->transition($plan, 'ROLLBACK_FAILED', [
                'incident' => ['title' => 'Rollback failed', 'description' => 'Failure'],
            ]);
            $this->fail('Incident insert failure should abort the whole transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected incident creation failure.', $exception->getMessage());
        }

        $this->assertDatabaseHas('update_plans', ['id' => $plan->id, 'status' => 'ROLLING_BACK']);
        $this->assertDatabaseCount('incidents', 0);
        $this->assertDatabaseMissing('audit_events', ['auditable_type' => UpdatePlan::class, 'auditable_id' => $plan->id, 'event_type' => 'status_transition']);
    }
}
