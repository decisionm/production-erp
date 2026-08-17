<?php

namespace Tests\Unit\Core;

use App\Modules\Core\Exports\CsvStreamer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CsvStreamer::csvLine / escapeCell are pure, and pinned here byte for
 * byte against frontend/src/lib/csv.ts's escapeCell — the two must never
 * disagree about what a spreadsheet would execute or how a comma is quoted.
 */
class CsvStreamerTest extends TestCase
{
    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function cells(): iterable
    {
        // Plain values pass through.
        yield 'plain text' => ['hello', 'hello'];
        yield 'integer' => [42, '42'];
        yield 'float' => [0.5, '0.5'];
        yield 'null is empty' => [null, ''];
        yield 'empty string' => ['', ''];
        yield 'true' => [true, 'true'];
        yield 'false' => [false, 'false'];

        // The formula guard: = + - @ tab CR starts get a leading apostrophe…
        yield 'equals formula' => ['=SUM(A1:A9)', "'=SUM(A1:A9)"];
        yield 'plus prefix' => ['+1234', "'+1234"];
        yield 'minus text' => ['-abc', "'-abc"];
        yield 'at prefix' => ['@cmd', "'@cmd"];
        yield 'tab prefix' => ["\tx", "'\tx"];
        yield 'cr prefix quoted too' => ["\rx", "\"'\rx\""];

        // …but a plain number — negative, decimal — is left exactly as it is.
        yield 'negative decimal' => ['-0.4', '-0.4'];
        yield 'negative decimal float' => [-0.4, '-0.4'];
        yield 'negative integer' => ['-12', '-12'];
        yield 'negative with four decimals' => ['-0.4000', '-0.4000'];
        // A number followed by a newline is NOT a number (JS's $ has no
        // trailing-newline leniency; \z keeps PHP honest about it).
        yield 'negative number then newline' => ["-1\n", "\"'-1\n\""];
        yield 'plus sign alone' => ['+', "'+"];

        // RFC-4180 quoting: commas, quotes, line breaks; quotes doubled.
        yield 'comma' => ['a,b', '"a,b"'];
        yield 'quote' => ['say "hi"', '"say ""hi"""'];
        yield 'newline' => ["line1\nline2", "\"line1\nline2\""];
        yield 'crlf' => ["l1\r\nl2", "\"l1\r\nl2\""];
        yield 'formula with comma' => ['=1,2', "\"'=1,2\""];
        yield 'unicode untouched' => ['ரிலையன்ஸ்', 'ரிலையன்ஸ்'];
        yield 'nested array as json' => [['a' => 1], '"{""a"":1}"'];
    }

    #[DataProvider('cells')]
    public function test_a_cell_is_escaped_exactly_as_the_frontend_would(mixed $value, string $expected): void
    {
        $this->assertSame($expected, CsvStreamer::escapeCell($value));
    }

    public function test_a_line_joins_cells_with_commas_and_ends_with_crlf(): void
    {
        $this->assertSame("id,name,note\r\n", CsvStreamer::csvLine(['id', 'name', 'note']));
        $this->assertSame("1,\"Reliance, Ltd\",'=x\r\n", CsvStreamer::csvLine([1, 'Reliance, Ltd', '=x']));
        $this->assertSame("\r\n", CsvStreamer::csvLine([]));
        $this->assertSame(",,\r\n", CsvStreamer::csvLine([null, null, null]));
    }
}
