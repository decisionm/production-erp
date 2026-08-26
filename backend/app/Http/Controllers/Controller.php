<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

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
        $needle = trim((string) $request->query($key, ''));

        return $needle === '' ? null : $needle;
    }
}
