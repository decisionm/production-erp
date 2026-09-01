<?php

namespace App\Modules\TallySync\Services;

use App\Modules\TallySync\Exceptions\TallyXmlUnreadable;

/**
 * READS a Tally XML export. Never writes one.
 *
 * ENCODING IS THE WHOLE PROBLEM, and it is not theoretical. Tally's own
 * "Export → XML" writes UTF-16LE with a BOM and no <?xml?> declaration, so the
 * bytes handed to us begin 0xFF 0xFE and every ASCII character is followed by a
 * NUL. PHP's XML parsers read that as garbage or as an empty document — quietly,
 * with no error worth the name. Every real export in the factory's own evidence
 * (55 Sales vouchers, 34 Sales Order vouchers, read 31-Aug-2026) is in exactly
 * that encoding, so detecting it is the normal path, not a fallback.
 *
 * We therefore sniff the BOM ourselves and transcode to UTF-8 before parsing.
 * A file with no BOM is assumed UTF-8, which is what a hand-saved or
 * re-encoded export looks like — both are accepted.
 *
 * WHAT THIS CLASS DOES NOT DO: it does not interpret. It hands back the
 * voucher elements, and the importer decides what a voucher means. Keeping the
 * two apart is why the encoding logic is testable without a database.
 */
final class TallyVoucherXmlReader
{
    /**
     * Every <VOUCHER> element in the document whose VCHTYPE matches, as
     * SimpleXMLElements.
     *
     * @return list<\SimpleXMLElement>
     */
    public function vouchers(string $raw, string $voucherType): array
    {
        $xml = $this->parse($raw);

        $out = [];
        foreach ($xml->xpath('//VOUCHER') ?: [] as $voucher) {
            if ((string) ($voucher['VCHTYPE'] ?? '') === $voucherType) {
                $out[] = $voucher;
            }
        }

        return $out;
    }

    public function parse(string $raw): \SimpleXMLElement
    {
        $utf8 = $this->toUtf8($raw);

        $utf8 = $this->stripIllegalControlCharacters($utf8);

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $xml = simplexml_load_string($utf8, options: LIBXML_NOCDATA | LIBXML_COMPACT);

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            $first = $errors[0]->message ?? 'no parser detail';

            throw new TallyXmlUnreadable('Tally XML could not be parsed: '.trim($first));
        }

        return $xml;
    }

    /**
     * Remove the control characters Tally writes that XML 1.0 forbids.
     *
     * THIS IS NOT DEFENSIVE PADDING — the factory's own export contains them.
     * ORDERNO on a referenceless Sales voucher reads "\x05 Not Applicable"
     * with a literal 0x05 byte, and libxml answers "PCDATA invalid Char value
     * 5" and abandons the ENTIRE document. One stray byte in a 5 MB export
     * would otherwise cost every voucher in it.
     *
     * Both spellings occur, so both are handled: the raw byte, and the numeric
     * entity (&#4;) that a re-export can turn it into. Tab, newline and
     * carriage return are the three C0 characters XML permits and are kept.
     *
     * Nothing legible is discarded — these bytes carry no meaning, and the
     * fields the importer actually reads are untouched.
     */
    private function stripIllegalControlCharacters(string $utf8): string
    {
        $utf8 = preg_replace('/&#(?:0*(?:[0-8]|1[1-2]|1[4-9]|2\d|3[01]));/i', '', $utf8) ?? $utf8;

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $utf8) ?? $utf8;
    }

    /**
     * Transcode to UTF-8 from whatever Tally actually wrote, by BOM.
     */
    private function toUtf8(string $raw): string
    {
        if ($raw === '') {
            throw new TallyXmlUnreadable('Tally XML is empty.');
        }

        $from = match (true) {
            str_starts_with($raw, "\xFF\xFE") => 'UTF-16LE',
            str_starts_with($raw, "\xFE\xFF") => 'UTF-16BE',
            str_starts_with($raw, "\xEF\xBB\xBF") => 'UTF-8',
            default => null,
        };

        if ($from === 'UTF-8' || $from === null) {
            return ltrim($raw, "\xEF\xBB\xBF");
        }

        $converted = mb_convert_encoding(substr($raw, 2), 'UTF-8', $from);

        if ($converted === false || $converted === '') {
            throw new TallyXmlUnreadable("Tally XML declared {$from} by BOM but could not be transcoded.");
        }

        return $converted;
    }
}
