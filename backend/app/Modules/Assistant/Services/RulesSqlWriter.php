<?php

namespace App\Modules\Assistant\Services;

use App\Modules\Assistant\Exceptions\AskErpException;
use App\Modules\Assistant\Services\Rules\QuestionRule;
use App\Modules\Assistant\Services\Rules\RuleBook;

/**
 * Answers a question with no model, no API key and no bill: the reference the
 * owner pointed at (s4_agentic) works this way too, mapping keywords onto
 * fixed queries rather than generating any.
 *
 * WHAT IT TRADES. It answers only what someone wrote a rule for, and it says
 * so plainly when it cannot — which is the honest failure. A model would
 * attempt anything and occasionally be confidently wrong; this refuses and
 * lists what it does know. On a factory floor that is the better error.
 *
 * WHAT IT DOES NOT CHANGE. Everything around it is identical to the model
 * path: the same permission filter chose which tables exist for this reader,
 * the same SqlGuard checks the SQL against that reader's allowed set before a
 * row is read, the same runner caps and times it out, and the same template
 * fills the sentence from the rows. This class only replaces "ask a model to
 * write SELECT" with "look one up".
 */
class RulesSqlWriter implements SqlWriter
{
    public function write(SqlRequest $request): SqlDraft
    {
        $rule = self::match($request->question);

        if ($rule === null) {
            throw new AskErpException(self::refusal(), 422);
        }

        return new SqlDraft(
            sql: $rule->sqlFor($request->today),
            answerTemplate: $rule->answerTemplate,
            chartHint: $rule->chartHint,
        );
    }

    /**
     * The best-scoring rule, or null when nothing matched at all.
     *
     * Ties go to the earlier rule, which is why RuleBook lists the specific
     * before the general: "lumps by machine" and "output by machine" both see
     * "by machine", and the caller who typed "lumps" meant the first.
     */
    public static function match(string $question): ?QuestionRule
    {
        $lower = mb_strtolower(trim($question));
        $best = null;
        $bestScore = 0;

        foreach (RuleBook::all() as $rule) {
            $score = $rule->score($lower);
            if ($score > $bestScore) {
                $best = $rule;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * The refusal names what CAN be asked. A bare "I don't know" leaves the
     * user guessing at the vocabulary of a closed rule set, which is the one
     * failure mode this driver has and the one thing that makes it usable
     * anyway.
     */
    private static function refusal(): string
    {
        $labels = array_map(static fn (QuestionRule $r) => $r->label, RuleBook::all());

        return 'I can only answer set questions on this server. Try one of: '
            .implode('; ', array_slice($labels, 0, 8)).'.';
    }
}
