<?php

namespace App\Policies;

use App\Models\Incident;
use App\Models\User;

class IncidentPolicy
{
    private function canWrite(User $user): bool
    {
        return $user->roles()->whereIn('name', ['Admin', 'Operator', 'Reviewer'])->exists();
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Incident $incident): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user);
    }

    public function update(User $user, Incident $incident): bool
    {
        return $this->canWrite($user) && $incident->status !== 'CLOSED';
    }

    public function transition(User $user, Incident $incident): bool
    {
        return $this->canWrite($user);
    }
}
