<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\User;

class AssetPolicy
{
    private function has(User $u, string ...$roles): bool
    {
        return $u->roles()->whereIn('name', $roles)->exists();
    }

    public function viewAny(User $u): bool
    {
        return $this->has($u, 'Admin', 'Operator', 'Reviewer', 'Viewer');
    }

    public function view(User $u, Asset $a): bool
    {
        return $this->viewAny($u);
    }

    public function create(User $u): bool
    {
        return $this->has($u, 'Admin', 'Operator');
    }

    public function update(User $u, Asset $a): bool
    {
        return $this->has($u, 'Admin', 'Operator');
    }

    public function delete(User $u, Asset $a): bool
    {
        return $this->has($u,'Admin');
    }
}
