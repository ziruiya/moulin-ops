<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->string('inventory_source')->nullable();
            $table->string('inventory_status')->default('Unverified');
            $table->timestamp('last_verified_at')->nullable();
            $table->boolean('internet_exposed')->default(false);
        });
        Schema::table('asset_components', function (Blueprint $table): void {
            $table->string('version_source')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('asset_components', fn (Blueprint $table) => $table->dropColumn('version_source'));
        Schema::table('assets', fn (Blueprint $table) => $table->dropColumn(['inventory_source', 'inventory_status', 'last_verified_at', 'internet_exposed']));
    }
};
