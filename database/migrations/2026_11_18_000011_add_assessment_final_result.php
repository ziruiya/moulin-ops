<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vulnerability_component_assessments', function (Blueprint $table): void {
            $table->text('final_result')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vulnerability_component_assessments', function (Blueprint $table): void {
            $table->dropColumn('final_result');
        });
    }
};
