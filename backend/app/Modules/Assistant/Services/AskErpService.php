<?php

namespace App\Modules\Assistant\Services;

use App\Models\User;
use App\Modules\Assistant\Exceptions\AskErpException;
use App\Modules\Assistant\Exceptions\SqlRefusedException;
use App\Modules\Assistant\Models\AskErpConversation;
use App\Modules\Assistant\Models\AskErpMessage;
use App\Modules\Assistant\Services\Rules\RuleBook;
use Illuminate\Support\Facades\Log;

/**
 * One question, start to finish: record it, pick the tables this login may
 * see, ask the writer, guard the SQL, run it, shape the answer, record the
 * outcome. Every turn — refused or answered — is stored with who asked.
 */
class AskErpService
{
    public function __construct(
        private readonly SchemaRetriever $retriever,
        private readonly SqlWriter $writer,
        private readonly SqlGuard $guard,
        private readonly QueryRunner $runner,
    ) {}

    /** @return list<array{table: string, label: string, module: string}> */
    public function catalogueFor(User $user): array
    {
        return array_values(array_map(
            static fn ($spec) => ['table' => $spec->table, 'label' => $spec->label, 'module' => $spec->module],
            $this->retriever->allowedTables($user),
        ));
    }

    /**
     * Questions this reader can click and send, rather than the list of
     * tables they may see. The page led with all 122 table names, which named
     * no question a supervisor would ask and filled the screen for anyone
     * holding every permission.
     *
     * Drawn from the rule book on EVERY driver, not only `rules`: these are
     * good questions for a model too, and offering the same ones keeps the
     * page's suggestions honest when the driver is switched.
     *
     * @return list<string>
     */
    public function examplesFor(User $user): array
    {
        return RuleBook::examplesFor(array_keys($this->retriever->allowedTables($user)));
    }

    public function ask(User $user, AskErpConversation $conversation, string $question): AskErpMessage
    {
        $started = hrtime(true);
        $conversation->messages()->create(['role' => 'user', 'question' => $question]);

        $history = $conversation->messages()
            ->where('role', 'assistant')
            ->whereNull('error')
            ->latest('id')
            ->limit((int) config('ask-erp.history_turns'))
            ->get()
            ->reverse()
            ->map(static fn (AskErpMessage $m) => ['question' => (string) $m->question, 'sql' => $m->sql, 'answer' => $m->answer])
            ->values()
            ->all();
        $previousTables = collect($history)
            ->pluck('sql')
            ->filter()
            ->flatMap(fn (string $sql) => $this->guard->tablesIn($sql))
            ->unique()
            ->values()
            ->all();

        try {
            $specs = $this->retriever->forQuestion($user, $question, $previousTables);
            if ($specs === []) {
                throw new AskErpException('That question matches none of the tables you can see. Try naming what you want counted.', 422);
            }

            $hidden = [];
            $rendered = [];
            foreach ($specs as $spec) {
                $hidden[$spec->table] = $this->retriever->hiddenColumns($user, $spec);
                $rendered[] = $spec->render($hidden[$spec->table]);
            }

            $draft = $this->writer->write(new SqlRequest(
                question: $question,
                tableSpecs: $rendered,
                history: $history,
                today: now(config('tally-sync.factory_timezone', 'Asia/Kolkata'))->toDateString(),
            ));

            try {
                $sql = $this->guard->check(
                    $draft->sql,
                    array_keys($this->retriever->allowedTables($user)),
                    $hidden,
                    (int) config('ask-erp.row_limit'),
                );
            } catch (SqlRefusedException $e) {
                throw new AskErpException($e->getMessage(), 422);
            }

            $result = $this->runner->run($sql, (int) config('ask-erp.row_limit'));
        } catch (AskErpException $e) {
            $conversation->messages()->create([
                'role' => 'assistant',
                'question' => $question,
                'error' => $e->getMessage(),
                'duration_ms' => (int) ((hrtime(true) - $started) / 1e6),
            ]);
            Log::info('ask-erp refused', ['user' => $user->id, 'question' => $question, 'reason' => $e->getMessage()]);

            throw $e;
        }

        $message = $conversation->messages()->create([
            'role' => 'assistant',
            'question' => $question,
            'sql' => $sql,
            'answer' => AnswerTemplate::render($draft->answerTemplate, $result['columns'], $result['rows']),
            'tables_used' => $this->guard->tablesIn($sql),
            'row_count' => count($result['rows']),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1e6),
        ]);
        $message->result = [...$result, 'chart' => ChartSuggestion::for($result['columns'], $result['rows'], $draft->chartHint)];
        $conversation->touch();

        return $message;
    }
}
