<?php

namespace App\Policies;

use App\Models\UpdatePlan;
use App\Models\User;

class UpdatePlanPolicy
{
    private function has(User $user, string $role): bool
    {
        return $user->roles()->where('name', $role)->exists();
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, UpdatePlan $plan): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->has($user, 'Operator') || $this->has($user, 'Admin');
    }

    public function update(User $user, UpdatePlan $plan): bool
    {
        return $this->create($user) && ! in_array($plan->status, UpdatePlan::FINAL_STATES, true);
    }

    public function transition(User $user, UpdatePlan $plan): bool
    {
        return $this->has($user, 'Operator') || $this->has($user, 'Admin');
    }

    public function approve(User $user, UpdatePlan $plan): bool
    {
        if (in_array($plan->status, UpdatePlan::FINAL_STATES, true)
            || $user->id === $plan->created_by || $user->id === $plan->operator_id) {
            return false;
        }
        $reviewer = $user->roles()->where('name', 'Reviewer');
        if ($this->has($user, 'Operator')) {
            $reviewer->wherePivot('grant_scope', User::CHANGE_APPROVAL_GRANT)
                ->wherePivotNotNull('granted_by')->wherePivot('grant_reason', '<>', '');
        }

        return $reviewer->exists();
    }
}
