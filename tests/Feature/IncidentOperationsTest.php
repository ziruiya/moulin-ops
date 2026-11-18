<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AuditEvent;
use App\Models\Incident;
use App\Models\Role;
use App\Models\UpdatePlan;
use App\Models\User;
use App\Models\WorkRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => $role]));

        return $user;
    }

    private function incident(User $reporter, array $attrs = []): Incident
    {
        return Incident::create($attrs + ['incident_code' => 'INC-'.uniqid(), 'title' => 'Test incident', 'category' => 'AVAILABILITY', 'severity' => 'HIGH', 'priority' => 'P4', 'reported_by' => $reporter->id, 'status' => 'NEW']);
    }

    public function test_severity_and_priority_are_independent_and_findings_are_separate(): void
    {
        $operator = $this->user('Operator');
        $this->actingAs($operator)->post(route('incidents.store'), ['title' => 'High low', 'category' => 'DATABASE', 'severity' => 'CRITICAL', 'priority' => 'P4', 'web_findings' => 'web', 'application_findings' => 'app', 'database_findings' => 'db', 'network_findings' => 'net'] + $this->registration())->assertRedirect();
        $first = Incident::firstOrFail();
        self::assertSame('CRITICAL', $first->severity);
        self::assertSame('P4', $first->priority);
        self::assertSame(['web', 'app', 'db', 'net'], [$first->web_findings, $first->application_findings, $first->database_findings, $first->network_findings]);
        $this->get(route('incidents.create'))->assertOk();
        $this->get(route('incidents.show', $first))->assertOk()->assertSee('AI調査テンプレート（DEMO）')->assertSee('重大')->assertSee('低 (P4)');
        $this->actingAs($operator)->post(route('incidents.store'), ['title' => 'Low high', 'category' => 'OTHER', 'severity' => 'LOW', 'priority' => 'P1'] + $this->registration())->assertRedirect();
        self::assertDatabaseHas('incidents', ['severity' => 'LOW', 'priority' => 'P1']);
    }

    public function test_viewer_cannot_write_and_reviewer_can_create_incidents(): void
    {
        $viewer = $this->user('Viewer');
        $this->actingAs($viewer)->get(route('incidents.create'))->assertForbidden();
        $this->post(route('incidents.store'), ['title' => 'Denied', 'category' => 'OTHER', 'severity' => 'LOW', 'priority' => 'P4'] + $this->registration())->assertForbidden();
        $reviewer = $this->user('Reviewer');
        $this->actingAs($reviewer)->post(route('incidents.store'), ['title' => 'Allowed', 'category' => 'OTHER', 'severity' => 'LOW', 'priority' => 'P4'] + $this->registration())->assertRedirect();
    }

    public function test_close_checks_and_independent_verifier_are_enforced_and_low_risk_self_verification_is_audited(): void
    {
        $operator = $this->user('Operator');
        $reviewer = $this->user('Reviewer');
        $incident = $this->incident($operator, ['status' => 'RESOLVED', 'root_cause' => 'cause', 'resolution' => 'restored', 'resolution_verification' => 'verified', 'severity' => 'HIGH']);
        $this->actingAs($operator)->post(route('incidents.transition', $incident), ['version' => 1, 'status' => 'CLOSED'])->assertSessionHasErrors();
        WorkRecord::create(['record_code' => 'WR-'.uniqid(), 'record_type' => 'Incident', 'asset_id' => $this->asset()->id, 'incident_id' => $incident->id, 'user_id' => $operator->id, 'summary' => 'record', 'action_summary' => 'action', 'environment' => 'DEMO', 'result' => 'Success', 'started_at' => now(), 'performed_at' => now()]);
        $checks = ['version' => 1, 'status' => 'CLOSED', 'closure_reason' => 'done', 'check_restore' => '1', 'check_verification' => '1', 'check_work_record' => '1', 'check_closure_reason' => '1', 'check_follow_up' => '1'];
        $this->post(route('incidents.transition', $incident), $checks + ['resolution_verified_by' => $operator->id])->assertSessionHasErrors('resolution_verified_by');
        $this->post(route('incidents.transition', $incident), $checks + ['resolution_verified_by' => $reviewer->id])->assertRedirect();
        self::assertSame('CLOSED', $incident->fresh()->status);
        self::assertNotNull($incident->fresh()->resolution_verified_at);

        $low = $this->incident($operator, ['status' => 'RESOLVED', 'root_cause' => 'cause', 'resolution' => 'restored', 'resolution_verification' => 'verified', 'severity' => 'LOW']);
        WorkRecord::create(['record_code' => 'WR-'.uniqid(), 'record_type' => 'Incident', 'asset_id' => $this->asset()->id, 'incident_id' => $low->id, 'user_id' => $operator->id, 'summary' => 'record', 'action_summary' => 'action', 'environment' => 'DEMO', 'result' => 'Success', 'started_at' => now(), 'performed_at' => now()]);
        $this->post(route('incidents.transition', $low), $checks + ['version' => 1])->assertRedirect();
        self::assertTrue($low->fresh()->self_verified);
        self::assertDatabaseHas('audit_events', ['auditable_type' => Incident::class, 'auditable_id' => $low->id, 'event_type' => 'incident_status_changed']);
    }

    public function test_closed_incident_cannot_be_updated_and_reopen_is_audited(): void
    {
        $user = $this->user('Operator');
        $incident = $this->incident($user, ['status' => 'CLOSED']);
        $before = $incident->fresh()->getAttributes();
        $asset = $this->asset();
        $incident->assets()->attach($asset->id);
        $this->actingAs($user)->put(route('incidents.update', $incident), ['version' => 1, 'title' => 'overwrite', 'category' => 'OTHER', 'severity' => 'LOW', 'priority' => 'P4'] + $this->registration())->assertForbidden();
        $this->post(route('incidents.transition', $incident), ['version' => 1, 'status' => 'REOPENED', 'reopen_reason' => 'new symptoms'])->assertRedirect()->assertSessionHasNoErrors();
        self::assertSame($before, $incident->fresh()->getAttributes());
        self::assertSame('CLOSED', $incident->fresh()->status);
        $reopened = Incident::where('reopened_from_id', $incident->id)->firstOrFail();
        self::assertSame('REOPENED', $reopened->status);
        self::assertSame([$asset->id], $reopened->assets->modelKeys());
        $this->get(route('incidents.show', $reopened))->assertSee($incident->incident_code)->assertSee('再開元');
        $this->get(route('incidents.show', $incident))->assertSee($reopened->incident_code);
        self::assertDatabaseHas('audit_events', ['auditable_type' => Incident::class, 'auditable_id' => $incident->id, 'event_type' => 'incident_reopened']);
    }

    public function test_stale_version_is_rejected_and_work_record_corrections_append(): void
    {
        $user = $this->user('Operator');
        $incident = $this->incident($user);
        $this->actingAs($user)->put(route('incidents.update', $incident), ['version' => 1, 'title' => 'updated', 'category' => 'OTHER', 'severity' => 'LOW', 'priority' => 'P4'] + $this->registration())->assertRedirect();
        $this->put(route('incidents.update', $incident), ['version' => 1, 'title' => 'stale', 'category' => 'OTHER', 'severity' => 'LOW', 'priority' => 'P4'] + $this->registration())->assertStatus(409);
        $asset = $this->asset();
        $original = WorkRecord::create(['record_code' => 'WR-ORIGINAL', 'record_type' => 'Incident', 'asset_id' => $asset->id, 'user_id' => $user->id, 'summary' => 'original', 'action_summary' => 'original action', 'environment' => 'DEMO', 'result' => 'Success', 'started_at' => now(), 'performed_at' => now()]);
        $this->post(route('work-records.store'), ['record_type' => 'Incident', 'asset_id' => $asset->id, 'summary' => 'correction', 'action_summary' => 'corrected action', 'environment' => 'DEMO', 'result' => 'Success', 'started_at' => now()->toDateTimeString(), 'performed_at' => now()->toDateTimeString(), 'correction_of_id' => $original->id, 'correction_reason' => 'typo'])->assertRedirect();
        self::assertSame('original', $original->fresh()->summary);
        self::assertDatabaseHas('work_records', ['correction_of_id' => $original->id, 'correction_reason' => 'typo']);
    }

    public function test_audit_can_be_queried_by_object_type_id_and_time_and_events_are_immutable(): void
    {
        $user = $this->user('Operator');
        $incident = $this->incident($user);
        $event = AuditEvent::create(['user_id' => $user->id, 'auditable_type' => Incident::class, 'auditable_id' => $incident->id, 'event_type' => 'incident_created', 'created_at' => now()]);
        $other = $this->incident($user);
        AuditEvent::create(['user_id' => $user->id, 'auditable_type' => Incident::class, 'auditable_id' => $other->id, 'event_type' => 'incident_created', 'created_at' => now()]);
        $this->actingAs($user)->get(route('audit-events.index', ['type' => Incident::class, 'id' => $incident->id, 'from' => now()->toDateString(), 'to' => now()->toDateString()]))->assertOk()->assertSee('incident_created')->assertSee('Incident #'.$incident->id)->assertDontSee('Incident #'.$other->id);
        try {
            $event->update(['event_type' => 'rewritten']);
            self::fail('Audit updates must throw.');
        } catch (\LogicException) {
        }
        try {
            $event->delete();
            self::fail('Audit deletes must throw.');
        } catch (\LogicException) {
        }
    }

    private function asset(): Asset
    {
        return Asset::create(['asset_code' => 'AST-'.uniqid(), 'name' => 'Demo asset', 'asset_type' => 'Server', 'operational_status' => 'Active']);
    }

    private function registration(): array
    {
        return ['assigned_to' => User::firstOrFail()->id, 'source' => 'Manual DEMO', 'detected_at' => now()->toDateTimeString(), 'summary' => 'DEMO incident summary', 'symptoms' => 'DEMO symptoms', 'user_impact' => 'DEMO users', 'business_impact' => 'DEMO business', 'scope_description' => 'DEMO scope'];
    }

    private function closePayload(): array
    {
        return ['version' => 1, 'status' => 'CLOSED', 'closure_reason' => 'done', 'check_restore' => '1', 'check_verification' => '1', 'check_work_record' => '1', 'check_closure_reason' => '1', 'check_follow_up' => '1'];
    }

    private function resolved(User $user): Incident
    {
        $incident = $this->incident($user, ['status' => 'RESOLVED', 'severity' => 'LOW', 'assigned_to' => $user->id, 'root_cause' => 'cause', 'resolution' => 'restored', 'resolution_verification' => 'verified']);
        WorkRecord::create(['record_code' => 'WR-'.uniqid(), 'record_type' => 'Incident', 'asset_id' => $this->asset()->id, 'incident_id' => $incident->id, 'user_id' => $user->id, 'summary' => 'record', 'action_summary' => 'action', 'environment' => 'DEMO', 'result' => 'Success', 'started_at' => now(), 'performed_at' => now()]);

        return $incident;
    }

    public function test_each_close_check_is_individually_required(): void
    {
        $user = $this->user('Operator');
        $incident = $this->resolved($user);
        $this->actingAs($user);
        foreach (['check_restore', 'check_verification', 'check_work_record', 'check_closure_reason', 'check_follow_up'] as $field) {
            $payload = $this->closePayload();
            unset($payload[$field]);
            $this->postJson(route('incidents.transition', $incident), $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
            self::assertSame('RESOLVED', $incident->fresh()->status);
            self::assertSame(1, $incident->fresh()->version);
        }
        self::assertSame(0, AuditEvent::count());
    }

    public function test_close_requires_actual_evidence_reason_and_follow_up_plan(): void
    {
        $user = $this->user('Operator');
        $this->actingAs($user);
        foreach (['root_cause', 'resolution', 'resolution_verification'] as $field) {
            $incident = $this->resolved($user);
            $incident->update([$field => null]);
            $this->postJson(route('incidents.transition', $incident), $this->closePayload())->assertUnprocessable();
            self::assertSame('RESOLVED', $incident->fresh()->status);
        }
        $incident = $this->resolved($user);
        $this->postJson(route('incidents.transition', $incident), array_replace($this->closePayload(), ['closure_reason' => '']))->assertUnprocessable();
        $this->postJson(route('incidents.transition', $incident), $this->closePayload() + ['follow_up_required' => true])->assertUnprocessable()->assertJsonValidationErrors('plan_ids');
        $noRecord = $this->incident($user, ['status' => 'RESOLVED', 'severity' => 'LOW', 'root_cause' => 'cause', 'resolution' => 'restored', 'resolution_verification' => 'verified']);
        $this->postJson(route('incidents.transition', $noRecord), $this->closePayload())->assertUnprocessable();
    }

    public function test_high_risk_close_rejects_missing_and_resolver_verifiers_and_stamps_both_fields(): void
    {
        $user = $this->user('Operator');
        $resolver = $this->user('Operator');
        $verifier = $this->user('Reviewer');
        $incident = $this->resolved($user);
        $incident->update(['severity' => 'HIGH', 'assigned_to' => $resolver->id]);
        $this->actingAs($user);
        foreach ([null, $user->id, $resolver->id] as $id) {
            $this->postJson(route('incidents.transition', $incident), $this->closePayload() + ['resolution_verified_by' => $id])->assertUnprocessable()->assertJsonValidationErrors('resolution_verified_by');
            self::assertNull($incident->fresh()->resolution_verified_at);
        }
        $this->post(route('incidents.transition', $incident), $this->closePayload() + ['resolution_verified_by' => $verifier->id])->assertSessionHasNoErrors();
        self::assertSame($verifier->id, $incident->fresh()->resolution_verified_by);
        self::assertNotNull($incident->fresh()->resolution_verified_at);
        self::assertFalse($incident->fresh()->self_verified);
    }

    public function test_all_incident_role_matrix_cells_are_enforced_by_direct_requests(): void
    {
        foreach (['Admin', 'Operator', 'Reviewer', 'Viewer'] as $role) {
            $user = $this->user($role);
            $incident = $this->incident($user);
            $payload = ['title' => 'Matrix '.$role, 'category' => 'OTHER', 'severity' => 'LOW', 'priority' => 'P1', 'version' => 1] + $this->registration();
            $this->actingAs($user)->get(route('incidents.index'))->assertOk();
            $this->get(route('incidents.show', $incident))->assertOk();
            if ($role === 'Viewer') {
                $this->post(route('incidents.store'), $payload)->assertForbidden();
                $this->put(route('incidents.update', $incident), $payload)->assertForbidden();
                $this->post(route('incidents.transition', $incident), ['version' => 1, 'status' => 'TRIAGE'])->assertForbidden();
                self::assertSame('NEW', $incident->fresh()->status);
            } else {
                $this->post(route('incidents.store'), $payload)->assertSessionHasNoErrors()->assertRedirect();
                $this->put(route('incidents.update', $incident), $payload)->assertSessionHasNoErrors()->assertRedirect();
                self::assertSame('Matrix '.$role, $incident->fresh()->title);
                $this->post(route('incidents.transition', $incident), ['version' => 2, 'status' => 'TRIAGE'])->assertSessionHasNoErrors();
                self::assertSame('TRIAGE', $incident->fresh()->status);
            }
        }
    }

    public function test_low_severity_high_priority_displays_and_findings_updates_are_isolated(): void
    {
        $user = $this->user('Operator');
        $payload = ['title' => 'Independent', 'category' => 'OTHER', 'severity' => 'LOW', 'priority' => 'P1', 'web_findings' => 'web', 'application_findings' => 'app', 'database_findings' => 'db', 'network_findings' => 'net'] + $this->registration();
        $this->actingAs($user)->post(route('incidents.store'), $payload)->assertSessionHasNoErrors();
        $incident = Incident::firstOrFail();
        $this->get(route('incidents.show', $incident))->assertSee('最優先 (P1)')->assertSee('低');
        foreach (['web_findings', 'application_findings', 'database_findings', 'network_findings'] as $field) {
            $payload[$field] .= ' updated';
            $this->put(route('incidents.update', $incident), $payload + ['version' => $incident->fresh()->version])->assertSessionHasNoErrors();
            foreach (['web_findings', 'application_findings', 'database_findings', 'network_findings'] as $check) {
                self::assertSame($payload[$check], $incident->fresh()->getAttribute($check));
            }
        }
    }

    public function test_registration_requires_owner_and_specification_details(): void
    {
        $user = $this->user('Operator');
        $payload = ['title' => 'Required', 'category' => 'OTHER', 'severity' => 'LOW', 'priority' => 'P4'] + $this->registration();
        foreach (array_keys($this->registration()) as $field) {
            $missing = $payload;
            unset($missing[$field]);
            $this->actingAs($user)->postJson(route('incidents.store'), $missing)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        self::assertSame(0, Incident::count());
    }

    public function test_closed_incident_is_protected_at_model_layer(): void
    {
        $incident = $this->incident($this->user('Operator'), ['status' => 'CLOSED']);
        $this->expectException(\LogicException::class);
        $incident->update(['title' => 'overwrite']);
    }

    public function test_incident_scope_relations_are_saved_and_cross_asset_components_are_rejected(): void
    {
        $user = $this->user('Operator');
        $asset = $this->asset();
        $other = $this->asset();
        $component = $asset->components()->create(['component_type' => 'runtime', 'product_name' => 'DEMO runtime', 'support_phase' => 'Unknown']);
        $plan = UpdatePlan::create(['plan_code' => 'CHG-B5', 'title' => 'DEMO follow-up', 'created_by' => $user->id]);
        $payload = ['title' => 'Scope', 'category' => 'OTHER', 'severity' => 'LOW', 'priority' => 'P4', 'asset_ids' => [$asset->id], 'component_ids' => [$component->id], 'plan_ids' => [$plan->id]] + $this->registration();
        $this->actingAs($user)->post(route('incidents.store'), $payload)->assertSessionHasNoErrors();
        $incident = Incident::firstOrFail();
        self::assertSame([$asset->id], $incident->assets->modelKeys());
        self::assertSame([$component->id], $incident->components->modelKeys());
        self::assertSame([$plan->id], $incident->updatePlans->modelKeys());
        $this->get(route('incidents.show', $incident))->assertSee($asset->asset_code)->assertSee($component->product_name)->assertSee($plan->plan_code);
        $this->postJson(route('incidents.store'), array_replace($payload, ['asset_ids' => [$other->id]]))->assertUnprocessable();
        self::assertSame(1, Incident::count());
        $resolved = $this->resolved($user);
        $this->post(route('incidents.transition', $resolved), $this->closePayload() + ['follow_up_required' => true, 'plan_ids' => [$plan->id]])->assertSessionHasNoErrors();
        self::assertSame([$plan->id], $resolved->updatePlans->modelKeys());
    }

    public function test_correction_preserves_original_and_scope_and_is_visible_in_filtered_timeline(): void
    {
        $user = $this->user('Operator');
        $incident = $this->resolved($user);
        $original = $incident->workRecords()->firstOrFail();
        $before = $original->getAttributes();
        $payload = ['record_type' => 'Incident', 'asset_id' => $original->asset_id, 'summary' => 'CORRECTION-DEMO', 'action_summary' => 'corrected', 'environment' => 'DEMO', 'result' => 'Success', 'started_at' => now()->toDateTimeString(), 'performed_at' => now()->toDateTimeString(), 'correction_of_id' => $original->id, 'correction_reason' => 'typo', 'before_state' => 'before', 'after_state' => 'after', 'evidence_url' => 'https://example.test/evidence'];
        $this->actingAs($user)->post(route('work-records.store'), $payload)->assertSessionHasNoErrors();
        $correction = WorkRecord::where('correction_of_id', $original->id)->firstOrFail();
        self::assertSame($before, $original->fresh()->getAttributes());
        self::assertSame($incident->id, $correction->incident_id);
        self::assertSame($original->asset_id, $correction->asset_id);
        $this->get(route('work-records.index', ['incident_id' => $incident->id]))->assertOk()->assertSee('CORRECTION-DEMO')->assertSee($original->record_code)->assertSee('の更正');
        $this->get(route('work-records.index', ['asset_id' => $this->asset()->id]))->assertOk()->assertDontSee('CORRECTION-DEMO');
        $this->get(route('work-records.show', $original))->assertOk()->assertSee($correction->record_code);
        $this->get(route('work-records.show', $correction))->assertOk()->assertSee($original->record_code)->assertSee('before')->assertSee('after')->assertSee('https://example.test/evidence');
        foreach (['Reviewer', 'Viewer'] as $role) {
            $this->actingAs($this->user($role))->post(route('work-records.store'), $payload)->assertForbidden();
        }
        $this->put(route('work-records.show', $original), ['summary' => 'overwrite'])->assertStatus(405);
        $this->delete(route('work-records.show', $original))->assertStatus(405);
    }

    public function test_ai_demo_contains_template_output_and_manual_review_without_submission(): void
    {
        $user = $this->user('Viewer');
        $incident = $this->incident($user);
        $this->actingAs($user)->get(route('incidents.show', $incident))->assertOk()->assertSee('AI調査テンプレート（DEMO）')->assertSee('DEMO ONLY')->assertSee('調査質問の例')->assertSee('出力形式')->assertSee('人が内容を確認')->assertSee('<dialog id="ai-demo">', false)->assertDontSee('name="web_findings"', false);
        self::assertSame(0, AuditEvent::count());
    }

    public function test_work_record_types_and_plan_filter_and_audit_time_boundaries(): void
    {
        $user = $this->user('Operator');
        $asset = $this->asset();
        $plan = UpdatePlan::create(['plan_code' => 'CHG-TIMELINE', 'title' => 'Timeline plan', 'created_by' => $user->id]);
        $this->actingAs($user);
        foreach (['Update', 'Backup', 'Restore', 'Incident', 'Troubleshooting', 'Configuration', 'Verification', 'Vendor Communication'] as $type) {
            $this->post(route('work-records.store'), ['record_type' => $type, 'asset_id' => $asset->id, 'update_plan_id' => $plan->id, 'summary' => 'DEMO '.$type, 'action_summary' => 'action', 'environment' => 'DEMO', 'result' => 'Success', 'started_at' => now()->toDateTimeString(), 'performed_at' => now()->toDateTimeString()])->assertSessionHasNoErrors()->assertRedirect();
            self::assertDatabaseHas('work_records', ['record_type' => $type, 'update_plan_id' => $plan->id]);
        }
        $this->get(route('work-records.index', ['update_plan_id' => $plan->id]))->assertOk()->assertSee('DEMO Vendor Communication')->assertSee('DEMO Restore');
        $otherPlan = UpdatePlan::create(['plan_code' => 'CHG-OTHER', 'title' => 'Other plan', 'created_by' => $user->id]);
        $this->get(route('work-records.index', ['update_plan_id' => $otherPlan->id]))->assertOk()->assertDontSee('DEMO Vendor Communication');
        $incident = $this->incident($user);
        foreach ([['auditable_type' => Incident::class, 'event_type' => 'IN-WINDOW', 'created_at' => '2026-11-18 23:59:59'], ['auditable_type' => Incident::class, 'event_type' => 'OUT-OF-WINDOW', 'created_at' => '2026-11-17 23:59:59'], ['auditable_type' => Asset::class, 'event_type' => 'OTHER-TYPE', 'created_at' => '2026-11-18 12:00:00']] as $attrs) {
            AuditEvent::create($attrs + ['auditable_id' => $incident->id, 'user_id' => $user->id]);
        }
        $this->get(route('audit-events.index', ['type' => Incident::class, 'id' => $incident->id, 'from' => '2026-11-18', 'to' => '2026-11-18']))->assertOk()->assertSee('IN-WINDOW')->assertDontSee('OUT-OF-WINDOW')->assertDontSee('OTHER-TYPE');
        $this->getJson(route('audit-events.index', ['from' => 'bad-date']))->assertUnprocessable();
    }

    public function test_full_normal_flow_and_reopen_leave_audit_history_and_timestamps(): void
    {
        $user = $this->user('Operator');
        $incident = $this->resolved($user);
        $incident->update(['status' => 'NEW']);
        $this->actingAs($user);
        foreach (['TRIAGE', 'INVESTIGATING', 'MITIGATING', 'MONITORING', 'RESOLVED', 'CLOSED'] as $target) {
            $this->post(route('incidents.transition', $incident), array_replace($this->closePayload(), ['version' => $incident->fresh()->version, 'status' => $target]))->assertSessionHasNoErrors();
            self::assertSame($target, $incident->fresh()->status);
        }
        foreach (['acknowledged_at', 'started_investigation_at', 'started_at', 'mitigated_at', 'resolved_at', 'closed_at'] as $field) {
            self::assertNotNull($incident->fresh()->getAttribute($field));
        }
        $event = AuditEvent::where('auditable_id', $incident->id)->latest('id')->firstOrFail();
        self::assertTrue($event->new_values['self_verified']);
        self::assertSame($user->id, $event->new_values['resolution_verified_by']);
        self::assertSame(6, AuditEvent::where('auditable_id', $incident->id)->count());
        $this->post(route('incidents.transition', $incident), ['version' => $incident->fresh()->version, 'status' => 'REOPENED', 'reopen_reason' => 'recurrence'])->assertSessionHasNoErrors();
        $event = AuditEvent::where('auditable_type', Incident::class)->where('auditable_id', $incident->id)->latest('id')->firstOrFail();
        self::assertSame('CLOSED', $event->old_values['status']);
        self::assertSame('recurrence', $event->new_values['reason']);
        $reopened = Incident::where('reopened_from_id', $incident->id)->firstOrFail();
        self::assertSame('CLOSED', $incident->fresh()->status);
        self::assertSame('REOPENED', $reopened->status);
        self::assertNull($reopened->resolution_verified_at);
        self::assertSame('restored', $incident->fresh()->resolution);
        $this->post(route('incidents.transition', $reopened), ['version' => 1, 'status' => 'INVESTIGATING'])->assertSessionHasNoErrors();
        self::assertSame('INVESTIGATING', $reopened->fresh()->status);
        $viewer = $this->user('Viewer');
        $this->actingAs($viewer)->get(route('incidents.show', $incident))->assertSee('restored')->assertSee('verified')->assertSee('cause');
    }
}
