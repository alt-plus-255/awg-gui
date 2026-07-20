<?php

namespace App\Providers;

use App\Services\AmneziaWg\AmneziaWgService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }

            $awg = app(AmneziaWgService::class);
            $awg->applyTimezone();

            $domains = $awg->resolveSanctumStatefulDomains();
            if ($domains !== []) {
                config(['sanctum.stateful' => $domains]);
            }
        } catch (\Throwable) {
            // DB may be unavailable during early bootstrap.
        }
    }
}
