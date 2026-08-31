<?php

namespace App\Support\Tally;

/**
 * WHAT A TALLY FIELD ACTUALLY CONTAINS, once its export artefacts are removed.
 *
 * Tally embeds NUMERIC CHARACTER REFERENCES in exported text — `&#13;&#10;` on
 * the end of a value someone pressed Enter in, `&#4;` in front of its reserved
 * words ("&#4; Not Applicable"). The agent already strips CONTROL CHARACTERS,
 * which is the right instinct and does nothing here: `fast-xml-parser` with
 * `parseTagValue: false` does not decode those references, so what arrives is
 * the LITERAL ten characters `&#13;&#10;`, every one of them printable.
 *
 * That is not a hypothetical. Three of this factory's 1742 ledgers carry a
 * perfectly good GSTIN with `&#13;&#10;` stuck to the end of it — 25 characters
 * where the column holds 15 — and on 31-Aug-2026 they took the entire masters
 * pull down with a 422 (see the sibling change to SyncMastersRequest).
 *
 * DECODE, DO NOT DISCARD. `34AAAFR5202M1ZD&#13;&#10;` is a real GSTIN with
 * rubbish on the end; throwing it away would lose a fact the factory has.
 * Decoding recovers it exactly, which is why this runs on the CLOUD side too
 * and not only in the agent — an agent already installed keeps sending the raw
 * form until it is replaced, and the data should be right before then.
 */
final class TallyText
{
    /**
     * Decode numeric character references, drop control characters, trim.
     *
     * Returns null for anything that is empty afterwards, because a blank is
     * not a value — the callers all treat null as "Tally has nothing here".
     */
    public static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        // &#13; / &#xD; → the character, so the control-character strip below
        // can see it. Bounded to what a character reference can be, so nothing
        // else in the string is touched.
        $decoded = preg_replace_callback(
            '/&#(x[0-9a-f]{1,6}|\d{1,7});/i',
            static function (array $m): string {
                $code = str_starts_with(strtolower($m[1]), 'x')
                    ? (int) hexdec(substr($m[1], 1))
                    : (int) $m[1];

                // Out-of-range or NUL is dropped rather than turned into a
                // replacement character that would then look like content.
                return $code > 0 && $code <= 0x10FFFF ? (mb_chr($code, 'UTF-8') ?: '') : '';
            },
            $value,
        ) ?? $value;

        // Every C0 control plus DEL. The agent strips the same set; doing it
        // here as well is deliberate belt-and-braces, not duplication — this
        // side must be correct for an agent version nobody has upgraded yet.
        $stripped = preg_replace('/[\x00-\x1F\x7F]/u', '', $decoded) ?? $decoded;

        $trimmed = trim($stripped);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * A GSTIN, or null — never a truncation.
     *
     * A GSTIN is fifteen characters by definition. Something longer after
     * cleaning is not a GSTIN with extra on the end, it is a field somebody
     * typed two things into, and cutting it to fit would mint a plausible
     * looking number that identifies nobody. Null says "not known", which is
     * true, and the vendor review then simply raises nothing for it.
     */
    public static function gstin(mixed $value): ?string
    {
        $clean = self::clean($value);

        return $clean !== null && mb_strlen($clean) === 15 ? $clean : null;
    }

    /**
     * A value that must fit a column, or null.
     *
     * Same reasoning as gstin(): a truncated phone number is a WRONG phone
     * number, and a wrong one is worse than none. Length is measured in
     * characters, and the check is `mb_strlen` because the column limit is
     * characters too.
     */
    public static function fitting(mixed $value, int $maxLength): ?string
    {
        $clean = self::clean($value);

        return $clean !== null && mb_strlen($clean) <= $maxLength ? $clean : null;
    }
}
