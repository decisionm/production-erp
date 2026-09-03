<?php

namespace App\Modules\Assistant\Services\Rules;

/**
 * One question this ERP can answer without asking a model anything.
 *
 * A rule is a keyword set and the query it stands for. Nothing is generated:
 * the SQL was written by hand against the schema catalogue, so it cannot
 * invent a column, and the worst a bad match can do is answer a question the
 * user did not ask — visibly, with the SQL on screen.
 */
final class QuestionRule
{
    /**
     * @param  list<string>  $keywords  what the question is ABOUT — lower-case
     * @param  list<string>  $tables  every table the SQL touches, for the permission summary
     * @param  list<string>  $hints  how it is CUT ("by machine") — worth far less than the topic
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly array $keywords,
        public readonly array $tables,
        public readonly string $sql,
        public readonly string $answerTemplate,
        public readonly string $chartHint = 'none',
        public readonly array $hints = [],
    ) {}

    /**
     * How well the question matches.
     *
     * THE TOPIC MUST OUTWEIGH THE DIMENSION, and this is not a detail: "lumps
     * by machine" and "rejection by machine" both contain "by machine", which
     * is also how the plain output-per-machine question is phrased. Score them
     * evenly and the generic rule wins every specific question, which is
     * exactly what happened before this split. So a topic hit is worth three
     * times a hint, and a longer phrase beats a shorter one inside each.
     */
    public function score(string $lowerQuestion): int
    {
        $score = 0;

        foreach ($this->keywords as $keyword) {
            if (str_contains($lowerQuestion, $keyword)) {
                $score += 3 * (1 + substr_count($keyword, ' '));
            }
        }

        foreach ($this->hints as $hint) {
            if (str_contains($lowerQuestion, $hint)) {
                $score += 1;
            }
        }

        return $score;
    }

    /**
     * The SQL with the factory's own date substituted. `{{today}}` rather
     * than CURDATE() because the server runs in UTC while the factory day is
     * IST — CURDATE() is the wrong day for several hours every night, which
     * is exactly the window the night shift works in.
     */
    public function sqlFor(string $today): string
    {
        return str_replace('{{today}}', $today, $this->sql);
    }
}
