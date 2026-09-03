<?php

namespace App\Providers;

use App\Modules\Assistant\Catalogue\SchemaCatalogue;
use App\Modules\Assistant\Exceptions\AskErpException;
use App\Modules\Assistant\Services\AnthropicSqlWriter;
use App\Modules\Assistant\Services\OpenAiSqlWriter;
use App\Modules\Assistant\Services\SchemaRetriever;
use App\Modules\Assistant\Services\SqlWriter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AssistantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Which provider writes the SQL. A name that is neither REFUSES
        // rather than quietly falling back to Anthropic: a typo in the live
        // .env would otherwise spend money on a provider the administrator
        // believed they had switched away from.
        $this->app->bind(SqlWriter::class, static fn (): SqlWriter => match ((string) config('ask-erp.driver')) {
            'anthropic' => new AnthropicSqlWriter,
            'openai' => new OpenAiSqlWriter,
            default => throw new AskErpException('Ask ERP is not configured on this server.', 503),
        });

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
