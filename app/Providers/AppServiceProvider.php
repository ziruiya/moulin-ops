<?php

namespace App\Providers;

use App\Models\Asset;
use App\Policies\AssetPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Gate::policy(Asset::class, AssetPolicy::class);
    }
}
