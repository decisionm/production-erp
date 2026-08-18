<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * assertSame over JSON-shaped data whose OBJECT keys are compared by name,
     * never by position.
     *
     * WHY. The suite runs on two drivers (ci.yml: in-memory sqlite as the fast
     * leg, MySQL 8 as the parity leg — the factory's live instance is MySQL).
     * sqlite stores a `json` column as the text Laravel encoded, so a payload
     * row reads back with its keys in insertion order; MySQL's native JSON
     * type normalises objects and hands the keys back in ITS order (by
     * length, then bytes) — `{"item","quantity","godown"}` comes back as
     * `{"item","godown","quantity"}`. Nothing that reads a payload — the
     * agent, the preview, the presenters — depends on key position, so an
     * assertSame that does is pinning the driver, not the contract.
     *
     * WHAT IT KEEPS. Everything else assertSame checks: every key present on
     * both sides and no others, every value identical in type and content,
     * and the ORDER of lists (rows of a payload are a list; their order is
     * the builder's contract and is still asserted). Only associative keys
     * are sorted, on both sides, before the comparison. This is deliberately
     * NOT assertEqualsCanonicalizing, which sort()s associative arrays and
     * loses which value sat under which key.
     */
    public static function assertSameJson(mixed $expected, mixed $actual, string $message = ''): void
    {
        static::assertSame(static::canonicalJson($expected), static::canonicalJson($actual), $message);
    }

    /** Recursively ksort associative arrays; lists (and scalars) are returned as they are. */
    public static function canonicalJson(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = static::canonicalJson($item);
        }

        if (! array_is_list($out)) {
            ksort($out, SORT_STRING);
        }

        return $out;
    }
}
