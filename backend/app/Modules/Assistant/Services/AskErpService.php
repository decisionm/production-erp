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

    /**
     * The refusal for a question nothing matched, carrying a few questions
     * that would have. An empty "I don't know" leaves the reader guessing at
     * a vocabulary they cannot see.
     *
     * @param  list<string>  $examples
     */
    private static function nothingMatched(array $examples): string
    {
        if ($examples === []) {
            return 'That question matches none of the tables you can see. Try naming what you want counted.';
        }

        return 'That question matches none of the tables you can see. Try one of: '
            .implode('; ', array_slice($examples, 0, 4)).'.';
    }

    /**
     * Run a past question's SQL again and hand back its rows.
     *
     * WHY THIS EXISTS. A stored turn keeps its SQL and its row count but not
     * its rows — deliberately, because they are re-runnable and a result set
     * frozen in a database row is a second, staler copy of the truth. Nothing
     * re-ran them, so reopening yesterday's conversation showed a sentence
     * over an empty space. This is the missing half of that decision.
     *
     * IT RE-GUARDS, IT DOES NOT REPLAY. The stored SQL is treated as
     * untrusted input and goes through SqlGuard against the CURRENT reader's
     * permissions. Anything else would make a saved message a way to keep
     * reading a table after access to it was taken away — the asker may not
     * even be the reader, since a conversation outlives a role change.
     *
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, truncated: bool, chart: mixed}
     */
    public function rerun(User $user, AskErpMessage $message): array
    {
        $sql = trim((string) $message->sql);

        if ($sql === '') {
            throw new AskErpException('That answer has no query to run again.', 422);
        }

        try {
            $checked = $this->guard->check(
                $sql,
                array_keys($this->retriever->allowedTables($user)),
                $this->hiddenFor($user, $this->guard->tablesIn($sql)),
                (int) config('ask-erp.row_limit'),
            );
        } catch (SqlRefusedException $e) {
            throw new AskErpException($e->getMessage(), 422);
        }

        $result = $this->runner->run($checked, (int) config('ask-erp.row_limit'));

        return [...$result, 'chart' => ChartSuggestion::for($result['columns'], $result['rows'], 'none')];
    }

    /**
     * The columns this reader may not see, for exactly the tables named.
     *
     * ask() builds this from the tables the RETRIEVER ranked into the
     * question, which is a narrower set than the SQL may touch — a masked
     * column on a table that never ranked would not be masked. Keying it off
     * the SQL's own tables closes that, and is why the same helper is used on
     * both paths.
     *
     * @param  list<string>  $tables
     * @return array<string, list<string>>
     */
    private function hiddenFor(User $user, array $tables): array
    {
        $allowed = $this->retriever->allowedTables($user);
        $hidden = [];

        foreach ($tables as $table) {
            if (isset($allowed[$table])) {
                $hidden[$table] = $this->retriever->hiddenColumns($user, $allowed[$table]);
            }
        }

        return $hidden;
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
                // A WORD MATCHER MUST NOT DECIDE WHAT A MODEL CANNOT ANSWER.
                // "today productivity?" was refused here on the live floor
                // without Claude ever seeing it: no table is called
                // productivity, so the lexical rank found nothing and the
                // gate closed. The gate was built for the rules driver,
                // where matching nothing genuinely means answering nothing.
                //
                // For a model, matching nothing means only that the question
                // used different words. So it now falls back to the tables
                // this factory's own rule book cares about — a curated set,
                // already permission-filtered, and small enough to stay cheap
                // — and lets the model say it cannot rather than saying so on
                // its behalf. The rules driver keeps the old behaviour: it
                // has no use for tables no rule names.
                $specs = config('ask-erp.driver') === 'rules'
                    ? []
                    : $this->retriever->defaultTables($user, (int) config('ask-erp.tables_per_question'));
            }

            if ($specs === []) {
                // Naming what CAN be asked matters more now that the page
                // shows its suggestions only on an empty thread: a reader
                // three questions in has nothing on screen to copy from.
                throw new AskErpException(self::nothingMatched($this->examplesFor($user)), 422);
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
                    // Keyed off the SQL's own tables, not only the ranked
                    // specs: a writer may touch a table the retriever never
                    // ranked, and a masked column there would otherwise go
                    // unmasked. Same helper as rerun(), same guarantee.
                    $this->hiddenFor($user, $this->guard->tablesIn($draft->sql)) + $hidden,
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
