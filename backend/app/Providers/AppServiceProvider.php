<?php

namespace App\Providers;

use App\Modules\Production\Services\ShiftProductionEntryService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped so the per-request active-BOM memo in the variance
        // computation is shared across list rows (the resource resolves
        // the service per row) yet never outlives a request.
        $this->app->scoped(ShiftProductionEntryService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
