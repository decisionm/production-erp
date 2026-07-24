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
        return min(max($request->integer('per_page', $default), 1), $max);
    }
}
