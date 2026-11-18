<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_user', function (Blueprint $table): void {
            $table->foreignId('granted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('grant_reason')->nullable();
            $table->string('grant_scope')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('role_user', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('granted_by');
            $table->dropColumn(['grant_reason', 'grant_scope']);
        });
    }
};
