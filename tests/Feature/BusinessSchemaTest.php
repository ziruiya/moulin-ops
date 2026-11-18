<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BusinessSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_twenty_one_business_tables_exist(): void
    {
        $tables = ['users', 'roles', 'role_user', 'assets', 'asset_components', 'asset_relationships', 'component_lifecycle_records', 'vulnerability_records', 'vulnerability_affected_ranges', 'vulnerability_component_assessments', 'update_plans', 'update_plan_assets', 'update_plan_components', 'update_checklist_items', 'change_approvals', 'incidents', 'incident_assets', 'incident_components', 'incident_update_plans', 'work_records', 'audit_events'];
        foreach ($tables as $table) {
            self::assertTrue(Schema::hasTable($table), "Missing {$table}");
        }
        self::assertSame(21, count($tables));
    }

    public function test_change_approval_rejects_self_approval(): void
    {
        $initiator = $this->user('initiator');
        DB::table('update_plans')->insert(['plan_code' => 'T-1', 'title' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        $plan = DB::table('update_plans')->where('plan_code', 'T-1')->value('id');
        $this->expectException(QueryException::class);
        DB::table('change_approvals')->insert(['update_plan_id' => $plan, 'initiated_by' => $initiator, 'approver_id' => $initiator, 'decision' => 'APPROVED', 'decided_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_lifecycle_verification_is_unique_per_component(): void
    {
        $assetId = DB::table('assets')->insertGetId(['asset_code' => 'A-1', 'name' => 'A', 'asset_type' => 'server', 'created_at' => now(), 'updated_at' => now()]);
        $componentId = DB::table('asset_components')->insertGetId(['asset_id' => $assetId, 'component_type' => 'runtime', 'product_name' => 'PHP', 'created_at' => now(), 'updated_at' => now()]);
        $row = ['component_id' => $componentId, 'product_name' => 'PHP', 'support_phase' => 'Unknown', 'source_name' => 'Manual', 'source_url' => 'https://example.test', 'verified_at' => '2026-01-01 00:00:00', 'date_precision' => 'DAY', 'data_source_type' => 'OFFICIAL_MANUAL_SEED', 'created_at' => now(), 'updated_at' => now()];
        DB::table('component_lifecycle_records')->insert($row);
        $this->expectException(QueryException::class);
        DB::table('component_lifecycle_records')->insert($row);
    }

    public function test_user_foreign_keys_restrict_deletion(): void
    {
        $user = $this->user('referenced');
        DB::table('assets')->insert(['asset_code' => 'A-2', 'name' => 'A', 'asset_type' => 'server', 'created_at' => now(), 'updated_at' => now()]);
        $assetId = DB::table('assets')->where('asset_code', 'A-2')->value('id');
        DB::table('work_records')->insert(['record_code' => 'WR-1', 'record_type' => 'Update', 'asset_id' => $assetId, 'user_id' => $user, 'summary' => 'test', 'action_summary' => 'test', 'environment' => 'demo', 'result' => 'Success', 'started_at' => now(), 'performed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $user)->delete();
    }

    private function user(string $suffix): int
    {
        return (int) DB::table('users')->insertGetId(['name' => $suffix, 'username' => $suffix, 'email' => $suffix.'@example.test', 'password' => 'not-a-real-password', 'created_at' => now(), 'updated_at' => now()]);
    }
}
