<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_records', function (Blueprint $table) {
            $table->foreignId('correction_of_id')->nullable()->after('incident_id')->constrained('work_records')->restrictOnDelete();
            $table->text('correction_reason')->nullable()->after('correction_of_id');
        });
    }

    public function down(): void
    {
        Schema::table('work_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('correction_of_id');
            $table->dropColumn('correction_reason');
        });
    }
};
