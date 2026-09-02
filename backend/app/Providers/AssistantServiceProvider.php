<?php

namespace App\Providers;

use App\Modules\Assistant\Catalogue\SchemaCatalogue;
use App\Modules\Assistant\Services\SchemaRetriever;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AssistantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            SchemaCatalogue::class,
            static fn () => SchemaCatalogue::fromDirectory((string) config('ask-erp.catalogue_path')),
        );

        $this->app->bind(
            SchemaRetriever::class,
            static fn (Application $app) => new SchemaRetriever(
                $app->make(SchemaCatalogue::class),
                (int) config('ask-erp.tables_per_question'),
            ),
        );
    }
}
