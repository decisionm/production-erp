<?php

namespace App\Modules\Finance\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * READS A TALLY "GROUP OUTSTANDINGS" EXPORT FILE — the same position the agent
 * normally mirrors, taken by hand when the factory PC cannot deliver one.
 *
 * WHY THIS EXISTS AT ALL. The client-outstanding page is fed by the local Tally
 * agent, and that path has two single points of failure a person in the office
 * cannot do anything about: the factory PC must be on, and its Tally must be
 * reachable on the XML gateway. On 03-Sep-2026 both were down for an afternoon
 * and Accounts had no way to see what anybody owed, while a perfectly good
 * export of exactly that position sat on the owner's laptop. This turns that
 * file into the page.
 *
 * IT IS THE SAME SHAPE, PARSED THE SAME WAY. The agent's reader
 * (tally-sync-agent/src/tally/receivables.ts, parseGroupOutstandings) is the
 * other implementation of this algorithm, and the two must not drift. Both were
 * measured against the same 03-Sep-2026 export of the live company and both
 * reconcile to Tally's own stated footer total. If you change one, change the
 * other, and reconcile again.
 *
 * THE SHAPE: A FLAT, ORDERED STREAM, NOT A TREE. Every value belongs to the
 * BILLFIXED that PRECEDES it, as a SIBLING:
 *
 *     BILLFIXED  date=""          ref=""      party="A. ABUSHAHIR"   <- party header
 *     BILLOP ""  BILLCL ""  BILLDUE ""  BILLOVERDUE ""               <- header has no values
 *     BILLFIXED  date="3-Aug-26"  ref="567"   party=""               <- a bill
 *     BILLOP 13977.000  BILLCL 13977.000  BILLDUE 3-Aug-26           <- ITS values
 *     BILLFIXED  date=""          ref=""      party=""               <- subtotal separator
 *     LEDBILLOP ...  LEDBILLCL ...                                   <- Tally's party totals
 *
 * ORDER IS THE ONLY THING BINDING A VALUE TO ITS BILL, which is why this walks
 * the document rather than collecting tags by name. The measured export holds
 * 891 BILLFIXED against 756 BILLCL — collecting each tag into its own list and
 * pairing them by index would attach one client's money to another client's
 * name.
 *
 * WHAT IT REFUSES:
 *   - a bill appearing before any party header (it would have to be guessed at)
 *   - header rows and subtotal separators (they carry no closing amount)
 *   - LEDBILL* totals, which are Tally's own per-party sums; counting them
 *     would double every balance
 *
 * IT WRITES NOTHING. It returns rows in the same wire shape the agent posts, and
 * the caller hands those to TallyReceivableSyncService, which owns every
 * decision about what reaches the database — including its refusal to wipe a
 * standing position on an empty read.
 */
class TallyOutstandingExportParser
{
    private const MONTHS = [
        'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04',
        'may' => '05', 'jun' => '06', 'jul' => '07', 'aug' => '08',
        'sep' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12',
    ];

    /**
     * Every outstanding bill in the export, in the shape
     * TallyReceivableSyncService::sync() accepts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $contents): array
    {
        $xml = $this->toUtf8($contents);

        if (trim($xml) === '') {
            return [];
        }

        $dom = new DOMDocument;

        // Tally writes plenty this parser does not care about, and a stray
        // entity or an unknown tag must not turn a real position into an
        // exception. Warnings are collected rather than emitted.
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false || $dom->documentElement === null) {
            return [];
        }

        $bills = [];
        $party = null;
        $current = null;

        foreach ($this->orderedElements($dom->documentElement) as $element) {
            if ($element->nodeName === 'BILLFIXED') {
                $this->flush($bills, $current);

                $fields = $this->childText($element);

                // A header names the party for everything beneath it, and is
                // not itself a bill.
                if (($fields['BILLPARTY'] ?? '') !== '') {
                    $party = $fields['BILLPARTY'];

                    continue;
                }

                // No party, no reference and no date: the separator that
                // precedes a party's LEDBILL* subtotals.
                if (($fields['BILLREF'] ?? '') === '' && ($fields['BILLDATE'] ?? '') === '') {
                    continue;
                }

                $current = [
                    'party_ledger_name' => $party ?? '',
                    'party_ledger_guid' => null,
                    'bill_reference' => ($fields['BILLREF'] ?? '') !== '' ? $fields['BILLREF'] : null,
                    'bill_date' => $this->date($fields['BILLDATE'] ?? ''),
                    'due_date' => null,
                    'closing_amount' => null,
                    'opening_amount' => null,
                ];

                continue;
            }

            if ($current === null) {
                continue;
            }

            $value = trim($element->textContent);

            // Tally states a receivable DEBIT as negative here. The page
            // contract is positive-means-owed, so the sign crosses exactly
            // once, at this boundary — the same place the agent crosses it.
            if ($element->nodeName === 'BILLCL') {
                $current['closing_amount'] = $this->receivableAmount($value);
            }

            if ($element->nodeName === 'BILLOP') {
                $current['opening_amount'] = $this->receivableAmount($value);
            }

            if ($element->nodeName === 'BILLDUE') {
                $current['due_date'] = $this->date($value);
            }
        }

        $this->flush($bills, $current);

        // A bill nobody can be chased for is not reportable. It is dropped
        // rather than attached to whichever party happened to come next.
        return array_values(array_filter(
            $bills,
            fn (array $bill): bool => $bill['party_ledger_name'] !== ''
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $bills
     * @param  array<string, mixed>|null  $current
     */
    private function flush(array &$bills, ?array &$current): void
    {
        // A row Tally gave no closing amount for is a header or a separator,
        // never an outstanding, and never a zero.
        if ($current !== null && $current['closing_amount'] !== null) {
            $bills[] = $current;
        }

        $current = null;
    }

    /**
     * Every element in the document, in document order.
     *
     * BILLFIXED is yielded but NOT descended into: its children are the bill's
     * own identity fields and are read by the caller. Everything else may be a
     * wrapper — ENVELOPE, BODY, an export container — whose children are the
     * real stream.
     *
     * @return array<int, DOMElement>
     */
    private function orderedElements(DOMNode $node, int $depth = 0): array
    {
        if ($depth > 14) {
            return [];
        }

        $out = [];

        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $out[] = $child;

            if ($child->nodeName !== 'BILLFIXED') {
                foreach ($this->orderedElements($child, $depth + 1) as $descendant) {
                    $out[] = $descendant;
                }
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    private function childText(DOMElement $element): array
    {
        $fields = [];

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $fields[$child->nodeName] = trim($child->textContent);
            }
        }

        return $fields;
    }

    /**
     * Tally exports this report as UTF-16 from the desktop and UTF-8 over the
     * HTTP gateway. Read as UTF-8 the UTF-16 form is a wall of NUL bytes and
     * loadXML refuses it, so the file the owner actually has would be rejected.
     */
    private function toUtf8(string $contents): string
    {
        if (str_starts_with($contents, "\xFF\xFE")) {
            return (string) mb_convert_encoding(substr($contents, 2), 'UTF-8', 'UTF-16LE');
        }

        if (str_starts_with($contents, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($contents, 2), 'UTF-8', 'UTF-16BE');
        }

        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            return substr($contents, 3);
        }

        return $contents;
    }

    /**
     * A Tally amount, with its sign crossed to the page contract.
     *
     * Null — never 0 — when the field is absent or unreadable: a 0 outstanding
     * is a settled bill, and stating one the factory never wrote down takes a
     * real debt off somebody's collection list.
     */
    private function receivableAmount(string $raw): ?string
    {
        $text = str_replace([',', ' ', "\u{20B9}", '$'], '', $raw);

        if ($text === '' || ! is_numeric($text)) {
            return null;
        }

        // Kept as a string: these are money, and bcmath downstream expects
        // decimal text rather than a float that has already lost paise.
        return bcmul($text, '-1', 4);
    }

    /**
     * A date Tally stated, in any of the forms it uses.
     *
     * `3-Aug-26` is what this report writes and is the whole reason this
     * exists — without it every due date is null and the page's ageing
     * columns stay empty even though Tally stated the date plainly. Two-digit
     * years window to 2000-2099.
     */
    private function date(string $raw): ?string
    {
        $text = trim($raw);

        if (preg_match('/^\d{8}$/', $text) === 1) {
            return substr($text, 0, 4).'-'.substr($text, 4, 2).'-'.substr($text, 6, 2);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            return $text;
        }

        if (preg_match('/^(\d{1,2})-([A-Za-z]{3})-(\d{2}|\d{4})$/', $text, $m) !== 1) {
            return null;
        }

        $month = self::MONTHS[strtolower($m[2])] ?? null;

        if ($month === null) {
            return null;
        }

        $year = strlen($m[3]) === 2 ? '20'.$m[3] : $m[3];

        return $year.'-'.$month.'-'.str_pad($m[1], 2, '0', STR_PAD_LEFT);
    }
}
