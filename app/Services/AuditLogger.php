<?php

namespace App\Services;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    public function log(Model $model, string $event, array $old = [], array $new = []): void
    {
        AuditEvent::create(['user_id' => Auth::id(), 'auditable_type' => $model::class, 'auditable_id' => $model->getKey(), 'event_type' => $event, 'old_values' => $old ?: null, 'new_values' => $new ?: null]);
    }
}
