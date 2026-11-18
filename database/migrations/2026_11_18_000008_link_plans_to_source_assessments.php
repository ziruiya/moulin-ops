<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('update_plans', function (Blueprint $table): void {
            $table->foreignId('source_assessment_id')->nullable()
                ->constrained('vulnerability_component_assessments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('update_plans', fn (Blueprint $table) => $table->dropConstrainedForeignId('source_assessment_id'));
    }
};
