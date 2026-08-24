<?php

namespace App\Providers;

use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\AmneziaWg\Versions\AwgVersionRegistry;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AwgVersionRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        try {
            if (Schema::hasTable('settings')) {
                $awg = app(AmneziaWgService::class);
                $awg->applyTimezone();

                $domains = $awg->resolveSanctumStatefulDomains();
                if ($domains !== []) {
                    config(['sanctum.stateful' => $domains]);
                }
            }
        } catch (\Throwable) {
            // DB may be unavailable during early bootstrap.
        }

        // Always accept whatever Host the browser uses (SPA is same-origin via Caddy).
        // Covers stale SANCTUM_STATEFUL_DOMAINS after updates and missing settings table.
        if (class_exists(\Laravel\Sanctum\Sanctum::class)) {
            $stateful = array_values(array_unique(array_filter(array_merge(
                config('sanctum.stateful', []),
                [\Laravel\Sanctum\Sanctum::$currentRequestHostPlaceholder]
            ))));
            config(['sanctum.stateful' => $stateful]);
        }
    }
}
