<?php

namespace App\Modules\Core\Providers;

use App\Modules\Core\Exports\ExportKind;
use App\Modules\Core\Exports\ExportRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Download / Export Center: ONE ExportRegistry for the request,
 * filled from config('exports.kinds') — the ONE place a module lists its
 * kinds. Filled lazily, when the registry is first asked for, so a request
 * that never exports never constructs a kind (and its services).
 */
class ExportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ExportRegistry::class, function ($app): ExportRegistry {
            $registry = new ExportRegistry;

            foreach ((array) config('exports.kinds', []) as $class) {
                $kind = $app->make($class);
                if (! $kind instanceof ExportKind) {
                    throw new \LogicException("config('exports.kinds') lists {$class}, which is not an ExportKind.");
                }
                $registry->register($kind);
            }

            return $registry;
        });
    }
}
