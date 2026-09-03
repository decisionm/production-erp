<?php

namespace App\Providers;

use App\Modules\Assistant\Catalogue\SchemaCatalogue;
use App\Modules\Assistant\Services\AnthropicSqlWriter;
use App\Modules\Assistant\Services\OpenAiSqlWriter;
use App\Modules\Assistant\Services\SchemaRetriever;
use App\Modules\Assistant\Services\SqlWriter;
use App\Modules\Assistant\Services\UnconfiguredSqlWriter;
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
        //
        // The refusal is a WRITER, not a throw from this closure. SqlWriter
        // is resolved while the container builds AskErpController, which is
        // before that controller's try block exists — throwing here would
        // turn a misconfiguration into a 500 with a stack trace instead of
        // the 503 and the sentence the reader is promised.
        $this->app->bind(SqlWriter::class, static function (): SqlWriter {
            $driver = (string) config('ask-erp.driver');

            return match ($driver) {
                'anthropic' => new AnthropicSqlWriter,
                'openai' => new OpenAiSqlWriter,
                default => new UnconfiguredSqlWriter($driver),
            };
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
