<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $t) {
            $t->string('vendor')->nullable();
            $t->string('support_contact')->nullable();
            $t->string('vendor_ticket_ref')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $t) {
            $t->dropColumn(['vendor', 'support_contact', 'vendor_ticket_ref']);
        });
    }
};
