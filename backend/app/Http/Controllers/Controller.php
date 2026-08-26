<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

abstract class Controller
{
    /**
     * Page size for a paginated list endpoint, from the `per_page` query
     * param, clamped to a sane range. Lets reference-data pickers request the
     * full list (e.g. all 642 items for a shop-floor Select) instead of the
     * default 20 — otherwise a type-to-search Select only ever sees the first
     * page and most options are unselectable.
     */
    protected function perPage(Request $request, int $default = 20, int $max = 1000): int
    {
        // is_numeric FIRST. `$request->integer()` runs the value through
        // intval, and (int) 'abc' is 0, which the clamp below turned into ONE
        // ROW PER PAGE rather than the documented default — a list that looks
        // empty for a typo in the query string. CustomerController had already
        // hand-rolled this guard for the same reason; the fix belongs here,
        // where every list reads it.
        $raw = $request->query('per_page');

        if (! is_numeric($raw)) {
            return $default;
        }

        return min(max((int) $raw, 1), $max);
    }

    /**
     * The `search` needle for a list endpoint — trimmed, and null rather than
     * an empty string so a service can `when()` on it directly and an empty
     * box narrows nothing.
     */
    protected function searchTerm(Request $request, string $key = 'search'): ?string
    {
        $needle = trim((string) $this->scalarQuery($request, $key, ''));

        return $needle === '' ? null : $needle;
    }

    /**
     * The `item_id`-style filter on a list endpoint. `?:` keeps the tolerance
     * every caller already had — `item_id=0` and `item_id=abc` both mean "no
     * filter", as they always did — and only the array case is new.
     */
    protected function filterId(Request $request, string $key): ?int
    {
        return ((int) $this->scalarQuery($request, $key, 0)) ?: null;
    }

    /**
     * A QUERY PARAMETER THE LAYER BELOW CAN ACTUALLY REPRESENT.
     *
     * A query string carries arrays: `?search[]=RM` and `?search[a]=RM` both
     * arrive here as a PHP array. `(string) []` raises "Array to string
     * conversion", which HandleExceptions turns into a 500 — a crash on a
     * plain authenticated GET that any client reaches by repeating a
     * parameter. `(int) ['5']` is quieter and worse: it is 1, with no warning
     * at all, so `?item_id[]=5` answered with item 1's rows and said nothing.
     *
     * REFUSED RATHER THAN IGNORED, because of the DIRECTION a filter fails in.
     * Reading `?code[]=x` as "no code" turns a scanner's exact-match question
     * into page one of everything, which reads on screen as a successful scan
     * of the wrong thing — a filter that fails open returns MORE than was
     * asked for. `perPage()` above neutralises instead, and that is not the
     * same policy applied twice differently: falling back to the documented
     * default serves the ordinary page, never a wider one.
     *
     * Same rule as a malformed FIGURE on a write door (App\Rules\PlainDecimal):
     * input that cannot be represented is answered at the door, in the
     * request's own words, never by a stack trace.
     */
    private function scalarQuery(Request $request, string $key, string|int $default): string|int
    {
        $raw = $request->query($key, $default);

        if (! is_scalar($raw)) {
            throw ValidationException::withMessages([
                $key => "The {$key} filter takes a single value, not a list.",
            ]);
        }

        return is_int($raw) ? $raw : (string) $raw;
    }
}
