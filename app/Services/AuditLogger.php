<?php

namespace App\Services;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    public function log(Model $model, string $event, array $old = [], array $new = [], ?int $actorId = null): void
    {
        $this->logFor($model::class, $model->getKey(), $event, $old, $new, $actorId);
    }

    public function logFor(string $type, int|string|null $id, string $event, array $old = [], array $new = [], ?int $actorId = null): void
    {
        AuditEvent::create([
            'user_id' => $actorId ?? Auth::id(),
            'auditable_type' => $type,
            'auditable_id' => $id,
            'event_type' => $event,
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'created_at' => now(),
        ]);
    }
}
