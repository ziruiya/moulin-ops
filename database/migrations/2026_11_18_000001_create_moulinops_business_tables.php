<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('username')->unique();
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });
        Schema::create('role_user', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->primary(['user_id', 'role_id']);
        });
        Schema::create('assets', function (Blueprint $t) {
            $t->id();
            $t->string('asset_code')->unique();
            $t->string('name');
            $t->string('asset_type');
            $t->string('environment')->nullable();
            $t->string('operational_status')->default('Active');
            $t->string('criticality')->nullable();
            $t->text('description')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('asset_components', function (Blueprint $t) {
            $t->id();
            $t->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $t->string('component_type');
            $t->string('product_name');
            $t->string('vendor_name')->nullable();
            $t->string('version')->nullable();
            $t->string('release_cycle')->nullable();
            $t->string('package_name')->nullable();
            $t->string('support_phase')->default('Unknown');
            $t->string('environment')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['id', 'asset_id']);
        });
        Schema::create('asset_relationships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('source_asset_id')->constrained('assets')->cascadeOnDelete();
            $t->foreignId('target_asset_id')->constrained('assets')->cascadeOnDelete();
            $t->string('relationship_type');
            $t->text('notes')->nullable();
            $t->string('source')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
            $t->unique(['source_asset_id', 'target_asset_id', 'relationship_type'], 'asset_rel_unique');
        });
        Schema::create('component_lifecycle_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('component_id')->constrained('asset_components')->cascadeOnDelete();
            $t->string('product_name');
            $t->string('version')->nullable();
            $t->date('active_support_end')->nullable();
            $t->date('security_support_end')->nullable();
            $t->date('extended_support_end')->nullable();
            $t->date('eol_date')->nullable();
            $t->string('support_phase');
            $t->string('lifecycle_status')->default('UNKNOWN');
            $t->string('source_name');
            $t->text('source_url');
            $t->timestamp('verified_at');
            $t->string('date_precision')->default('DAY');
            $t->string('data_source_type')->default('OFFICIAL_MANUAL_SEED');
            $t->timestamp('last_observed_at')->nullable();
            $t->timestamps();
            $t->unique(['component_id', 'verified_at']);
        });
        Schema::create('vulnerability_records', function (Blueprint $t) {
            $t->id();
            $t->string('vulnerability_code')->unique();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('source_name')->nullable();
            $t->text('source_url')->nullable();
            $t->decimal('cvss_score', 4, 1)->nullable();
            $t->string('severity')->nullable();
            $t->string('exploited_status')->default('UNKNOWN');
            $t->string('handling_status')->default('OPEN');
            $t->foreignId('reported_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });
        Schema::create('vulnerability_affected_ranges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vulnerability_record_id')->constrained()->cascadeOnDelete();
            $t->string('product_name');
            $t->string('version_scheme')->nullable();
            $t->text('affected_version_expression');
            $t->text('fixed_version')->nullable();
            $t->text('source_url')->nullable();
            $t->timestamps();
        });
        Schema::create('vulnerability_component_assessments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vulnerability_record_id')->constrained('vulnerability_records', 'id', 'vca_vuln_fk')->cascadeOnDelete();
            $t->foreignId('matched_range_id')->nullable()->constrained('vulnerability_affected_ranges', 'id', 'vca_range_fk')->nullOnDelete();
            $t->unsignedBigInteger('component_id');
            $t->unsignedBigInteger('asset_id');
            $t->foreign(['component_id', 'asset_id'], 'vca_component_asset_fk')->references(['id', 'asset_id'])->on('asset_components')->cascadeOnDelete();
            $t->string('match_status')->default('NOT_ASSESSED');
            $t->string('handling_status')->default('OPEN');
            $t->string('assessed_version')->nullable();
            $t->string('installed_version_snapshot');
            $t->string('assessment_method');
            $t->text('assessment_evidence');
            $t->text('assessment_reason');
            $t->text('resolution_note')->nullable();
            $t->foreignId('assessed_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('assessed_at');
            $t->timestamp('last_reviewed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('update_plans', function (Blueprint $t) {
            $t->id();
            $t->string('plan_code')->unique();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('change_type')->nullable();
            $t->string('reason_type')->nullable();
            $t->text('reason_detail')->nullable();
            $t->string('status')->default('DRAFT');
            $t->string('blocked_from_status')->nullable();
            $t->string('impact')->nullable();
            $t->string('impact_level')->nullable();
            $t->string('urgency')->nullable();
            $t->string('risk')->nullable();
            $t->string('risk_level')->nullable();
            $t->text('risk_reason')->nullable();
            $t->string('current_version')->nullable();
            $t->string('target_version')->nullable();
            $t->text('patch_source_url')->nullable();
            $t->text('compatibility_notes')->nullable();
            foreach (['test_plan', 'implementation_plan', 'verification_plan', 'rollback_plan', 'rollback_trigger'] as $f) {
                $t->longText($f)->nullable();
            } $t->string('backup_reference')->nullable();
            $t->boolean('backup_verified')->default(false);
            $t->boolean('restore_test_passed')->default(false);
            $t->timestamp('planned_start_at')->nullable();
            $t->timestamp('planned_end_at')->nullable();
            $t->timestamp('actual_start_at')->nullable();
            $t->timestamp('actual_end_at')->nullable();
            foreach (['operator_id', 'reviewer_id', 'approver_id', 'created_by'] as $f) {
                $t->foreignId($f)->nullable()->constrained('users')->restrictOnDelete();
            } $t->string('vendor_ticket_ref')->nullable();
            $t->text('completion_result')->nullable();
            $t->timestamps();
        });
        Schema::create('update_plan_assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('update_plan_id')->constrained()->cascadeOnDelete();
            $t->foreignId('asset_id')->constrained()->restrictOnDelete();
            $t->timestamps();
            $t->unique(['update_plan_id', 'asset_id']);
        });
        Schema::create('update_plan_components', function (Blueprint $t) {
            $t->id();
            $t->foreignId('update_plan_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('component_id');
            $t->unsignedBigInteger('asset_id');
            $t->foreign(['component_id', 'asset_id'], 'upc_component_asset_fk')->references(['id', 'asset_id'])->on('asset_components')->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['update_plan_id', 'component_id', 'asset_id'], 'upc_plan_component_unique');
        });
        Schema::create('update_checklist_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('update_plan_id')->constrained()->cascadeOnDelete();
            $t->string('phase');
            $t->unsignedSmallInteger('sequence')->default(1);
            $t->string('title');
            $t->text('description')->nullable();
            $t->text('instruction')->nullable();
            $t->text('expected_result')->nullable();
            $t->boolean('is_required')->default(true);
            $t->string('status')->default('Pending');
            $t->string('waiver_scope')->nullable();
            $t->text('evidence')->nullable();
            $t->text('exception_reason')->nullable();
            $t->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('completed_at')->nullable();
            $t->foreignId('waived_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('waived_at')->nullable();
            $t->timestamps();
        });
        Schema::create('change_approvals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('update_plan_id')->constrained()->cascadeOnDelete();
            $t->foreignId('initiated_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approver_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('executed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('decision');
            $t->text('comments')->nullable();
            $t->timestamp('decided_at');
            $t->timestamps();
        });
        DB::statement('ALTER TABLE change_approvals ADD CONSTRAINT chk_change_approvals_independent CHECK (approver_id <> initiated_by AND (executed_by IS NULL OR approver_id <> executed_by))');
        Schema::create('incidents', function (Blueprint $t) {
            $t->id();
            $t->string('incident_code')->unique();
            $t->string('title');
            $t->string('category');
            $t->string('severity');
            $t->string('priority');
            $t->string('status')->default('NEW');
            $t->foreignId('related_vulnerability_id')->nullable()->constrained('vulnerability_records', 'id', 'incident_vuln_fk')->nullOnDelete();
            $t->text('description')->nullable();
            $t->string('source')->nullable();
            $t->text('symptoms')->nullable();
            $t->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            foreach (['detected_at', 'reported_at', 'started_at', 'mitigated_at', 'resolved_at', 'closed_at'] as $f) {
                $t->timestamp($f)->nullable();
            } foreach (['web_findings', 'application_findings', 'database_findings', 'network_findings', 'root_cause', 'resolution', 'resolution_verification', 'closure_reason'] as $f) {
                $t->longText($f)->nullable();
            } $t->foreignId('resolution_verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('resolution_verified_at')->nullable();
            $t->boolean('self_verified')->default(false);
            $t->string('vendor_ticket_ref')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('incident_assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $t->foreignId('asset_id')->constrained()->restrictOnDelete();
            $t->timestamps();
            $t->unique(['incident_id', 'asset_id']);
        });
        Schema::create('incident_components', function (Blueprint $t) {
            $t->id();
            $t->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('component_id');
            $t->unsignedBigInteger('asset_id');
            $t->foreign(['component_id', 'asset_id'], 'ic_component_asset_fk')->references(['id', 'asset_id'])->on('asset_components')->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['incident_id', 'component_id', 'asset_id'], 'ic_inc_comp_asset_unique');
        });
        Schema::create('incident_update_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $t->foreignId('update_plan_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['incident_id', 'update_plan_id']);
        });
        Schema::create('work_records', function (Blueprint $t) {
            $t->id();
            $t->string('record_code')->unique();
            $t->string('record_type');
            $t->foreignId('asset_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('update_plan_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('incident_id')->nullable()->constrained()->restrictOnDelete();
            $t->text('summary');
            $t->text('action_summary');
            $t->longText('details')->nullable();
            $t->text('command_summary')->nullable();
            $t->string('environment');
            $t->longText('before_state')->nullable();
            $t->longText('after_state')->nullable();
            $t->string('result');
            $t->text('error_detail')->nullable();
            $t->text('evidence_url')->nullable();
            $t->text('next_action')->nullable();
            $t->timestamp('started_at');
            $t->timestamp('ended_at')->nullable();
            $t->timestamp('performed_at');
            $t->timestamps();
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('auditable_type');
            $t->unsignedBigInteger('auditable_id');
            $t->string('event_type');
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->text('description')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['auditable_type', 'auditable_id', 'created_at'], 'audit_events_object_created_idx');
        });
        $enumChecks = [
            "ALTER TABLE asset_components ADD CONSTRAINT chk_components_support CHECK (support_phase IN ('Active','Security Only','Extended','EOL','Unknown'))",
            "ALTER TABLE assets ADD CONSTRAINT chk_assets_operational CHECK (operational_status IN ('Active','Degraded','Maintenance','Retired'))",
            "ALTER TABLE component_lifecycle_records ADD CONSTRAINT chk_lifecycle_support CHECK (support_phase IN ('Active','Security Only','Extended','EOL','Unknown'))",
            "ALTER TABLE component_lifecycle_records ADD CONSTRAINT chk_lifecycle_status CHECK (lifecycle_status IN ('ACTIVE','SECURITY_ONLY','EXTENDED_SUPPORT','EOL','UNKNOWN','STALE','CONFLICT'))",
            "ALTER TABLE component_lifecycle_records ADD CONSTRAINT chk_lifecycle_date_precision CHECK (date_precision IN ('DAY','MONTH','QUARTER','UNKNOWN'))",
            "ALTER TABLE vulnerability_component_assessments ADD CONSTRAINT chk_assessment_match CHECK (match_status IN ('NOT_ASSESSED','CONFIRMED_AFFECTED','CONFIRMED_NOT_AFFECTED','POTENTIALLY_AFFECTED','UNKNOWN_PRODUCT','UNKNOWN_VERSION','RANGE_UNRESOLVED','NOT_APPLICABLE','SOURCE_CONFLICT'))",
            "ALTER TABLE vulnerability_component_assessments ADD CONSTRAINT chk_assessment_handling CHECK (handling_status IN ('OPEN','ASSESSING','ACTION_REQUIRED','REMEDIATION_PLANNED','IN_REMEDIATION','FIX_PENDING_VERIFICATION','MITIGATED','CLOSED','RISK_ACCEPTED','FALSE_POSITIVE'))",
            "ALTER TABLE vulnerability_records ADD CONSTRAINT chk_vuln_exploited CHECK (exploited_status IN ('CONFIRMED','NOT_KNOWN','UNKNOWN'))",
            "ALTER TABLE vulnerability_records ADD CONSTRAINT chk_vuln_severity CHECK (severity IS NULL OR severity IN ('CRITICAL','HIGH','MEDIUM','LOW'))",
            "ALTER TABLE incidents ADD CONSTRAINT chk_incident_category CHECK (category IN ('AVAILABILITY','PERFORMANCE','SECURITY','DATABASE','APPLICATION','WEB_SERVER','NETWORK','BACKUP','DEPLOYMENT','CONFIGURATION','DATA_INTEGRITY','THIRD_PARTY','OTHER'))",
            "ALTER TABLE incidents ADD CONSTRAINT chk_incident_severity CHECK (severity IN ('CRITICAL','HIGH','MEDIUM','LOW'))",
            "ALTER TABLE incidents ADD CONSTRAINT chk_incident_priority CHECK (priority IN ('P1','P2','P3','P4'))",
            "ALTER TABLE incidents ADD CONSTRAINT chk_incident_status CHECK (status IN ('NEW','TRIAGE','INVESTIGATING','MITIGATING','MONITORING','RESOLVED','CLOSED','ON_HOLD','REOPENED','CANCELLED'))",
            "ALTER TABLE update_checklist_items ADD CONSTRAINT chk_checklist_status CHECK (status IN ('Pending','Passed','Failed','Blocked','Not Applicable','Waived'))",
            "ALTER TABLE update_checklist_items ADD CONSTRAINT chk_checklist_phase CHECK (phase IN ('1 Impact Assessment','2 Pre-change Validation','3 Backup and Rollback Preparation','4 Implementation','5 Post-change Verification'))",
            "ALTER TABLE work_records ADD CONSTRAINT chk_work_result CHECK (result IN ('Success','Failed','Partial','Pending'))",
            "ALTER TABLE work_records ADD CONSTRAINT chk_work_type CHECK (record_type IN ('Update','Backup','Restore','Incident','Troubleshooting','Configuration','Verification','Vendor Communication'))",
            "ALTER TABLE vulnerability_component_assessments ADD CONSTRAINT chk_assessment_method CHECK (assessment_method IN ('MANUAL_VERIFIED','VERSION_RANGE_RULE','VENDOR_CONFIRMED'))",
            "ALTER TABLE update_plans ADD CONSTRAINT chk_plan_change_type CHECK (change_type IS NULL OR change_type IN ('Standard','Normal','Emergency'))",
            "ALTER TABLE update_plans ADD CONSTRAINT chk_plan_reason_type CHECK (reason_type IS NULL OR reason_type IN ('EOL','Security','CVE','Maintenance','Incident'))",
            "ALTER TABLE update_plans ADD CONSTRAINT chk_plan_status CHECK (status IN ('DRAFT','ASSESSMENT','TESTING','PENDING_APPROVAL','APPROVED','SCHEDULED','EXECUTING','VERIFYING','COMPLETED','REJECTED','BLOCKED','CANCELLED','FAILED','ROLLBACK_REQUIRED','ROLLING_BACK','ROLLED_BACK','ROLLBACK_FAILED','EMERGENCY_REVIEW'))",
        ];
        foreach ($enumChecks as $sql) {
            DB::statement($sql);
        }
    }

    public function down(): void
    {
        foreach (['audit_events', 'work_records', 'incident_update_plans', 'incident_components', 'incident_assets', 'incidents', 'change_approvals', 'update_checklist_items', 'update_plan_components', 'update_plan_assets', 'update_plans', 'vulnerability_component_assessments', 'vulnerability_affected_ranges', 'vulnerability_records', 'component_lifecycle_records', 'asset_relationships', 'asset_components', 'assets', 'role_user', 'roles'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropUnique(['username']);
            $t->dropColumn('username');
        });
    }
};
