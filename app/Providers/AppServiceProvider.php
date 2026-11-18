<?php

namespace App\Providers;

use App\Models\Asset;
use App\Models\User;
use App\Models\VulnerabilityRecord;
use App\Policies\AssetPolicy;
use App\Policies\VulnerabilityPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Gate::policy(Asset::class, AssetPolicy::class);
        Gate::policy(VulnerabilityRecord::class, VulnerabilityPolicy::class);
        Gate::define('manage-users', fn (User $user): bool => $user->roles()->where('name', 'Admin')->exists());
    }
}
