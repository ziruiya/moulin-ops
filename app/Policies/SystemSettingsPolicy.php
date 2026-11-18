<?php

namespace App\Policies;

use App\Models\User;

class SystemSettingsPolicy
{
    public function manage(User $user): bool
    {
        return $user->roles()->where('name', 'Admin')->exists();
    }
}
