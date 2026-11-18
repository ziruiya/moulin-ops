<?php

namespace App\Providers;

use App\Models\Asset;
use App\Models\Incident;
use App\Models\UpdatePlan;
use App\Models\User;
use App\Models\VulnerabilityRecord;
use App\Policies\AssetPolicy;
use App\Policies\IncidentPolicy;
use App\Policies\UpdatePlanPolicy;
use App\Policies\VulnerabilityPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Gate::policy(Asset::class, AssetPolicy::class);
        Gate::policy(Incident::class, IncidentPolicy::class);
        Gate::policy(VulnerabilityRecord::class, VulnerabilityPolicy::class);
        Gate::policy(UpdatePlan::class, UpdatePlanPolicy::class);
        Gate::define('manage-users', fn (User $user): bool => $user->roles()->where('name', 'Admin')->exists());
    }
}
