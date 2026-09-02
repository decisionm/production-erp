<?php

namespace App\Providers;

use App\Modules\Assistant\Catalogue\SchemaCatalogue;
use Illuminate\Support\ServiceProvider;

class AssistantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            SchemaCatalogue::class,
            static fn () => SchemaCatalogue::fromDirectory((string) config('ask-erp.catalogue_path')),
        );
    }
}
