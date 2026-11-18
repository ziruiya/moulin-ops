<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->foreignId('reopened_from_id')->nullable()->constrained('incidents')->restrictOnDelete();
            foreach (['summary', 'user_impact', 'business_impact', 'scope_description', 'detection_method', 'initial_hypothesis', 'workaround', 'recurrence_prevention', 'escalation_reason'] as $field) {
                $table->text($field)->nullable();
            }
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('started_investigation_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reopened_from_id');
            $table->dropColumn(['summary', 'user_impact', 'business_impact', 'scope_description', 'detection_method', 'initial_hypothesis', 'workaround', 'recurrence_prevention', 'escalation_reason', 'acknowledged_at', 'started_investigation_at']);
        });
    }
};
