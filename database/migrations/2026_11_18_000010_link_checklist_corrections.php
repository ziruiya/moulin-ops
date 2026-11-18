<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('update_checklist_items', function (Blueprint $table): void {
            $table->foreignId('correction_of_id')->nullable()->constrained('update_checklist_items')->restrictOnDelete();
        });

        DB::table('audit_events')
            ->where('auditable_type', 'App\Models\ChecklistItem')
            ->where('event_type', 'checklist_correction_recorded')
            ->select(['id', 'auditable_id', 'old_values'])
            ->chunkById(200, function ($events): void {
                foreach ($events as $event) {
                    $failedId = json_decode($event->old_values ?? '{}', true)['failed_item_id'] ?? null;
                    $failed = DB::table('update_checklist_items')->where('id', $failedId)->where('status', 'Failed')->first();
                    if ($failed) {
                        DB::table('update_checklist_items')->where('id', $event->auditable_id)
                            ->where('update_plan_id', $failed->update_plan_id)->where('phase', $failed->phase)
                            ->update(['correction_of_id' => $failed->id]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('update_checklist_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('correction_of_id');
        });
    }
};
