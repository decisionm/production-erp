<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Services\TallyOutstandingExportParser;
use Tests\TestCase;

/**
 * THE SHAPE THIS FACTORY'S TALLY ACTUALLY RETURNS.
 *
 * Measured 03-Sep-2026 from a Group Outstandings -> Sundry Debtors -> Pending
 * Bills export of the live company: 621 bills across 135 parties, every one
 * carrying a due date, reconciling to Tally's own footer total of
 * 50,523,510.696 Dr exactly.
 *
 * Every fixture below is SYNTHETIC (FC-06) — invented parties, invented bill
 * references, round numbers — but STRUCTURALLY identical to that export,
 * including the party header row, the flat sibling values, the subtotal
 * separator and the LEDBILL* totals. What a named client owes is
 * Owner/Accounts data and does not belong in a repository.
 *
 * This is the PHP half of an algorithm that also exists in
 * tally-sync-agent/src/tally/receivables.ts. The two must not drift.
 */
class TallyOutstandingExportParserTest extends TestCase
{
    private function parser(): TallyOutstandingExportParser
    {
        return new TallyOutstandingExportParser;
    }

    private function export(): string
    {
        return <<<'XML'
        <ENVELOPE>
            <BILLFIXED><BILLDATE></BILLDATE><BILLREF></BILLREF><BILLPARTY>Northwind Traders</BILLPARTY></BILLFIXED>
            <BILLOP></BILLOP><BILLCL></BILLCL><BILLDUE></BILLDUE><BILLOVERDUE></BILLOVERDUE>
            <BILLFIXED><BILLDATE>3-Aug-26</BILLDATE><BILLREF>567</BILLREF><BILLPARTY></BILLPARTY></BILLFIXED>
            <BILLOP>10000.000</BILLOP><BILLCL>10000.000</BILLCL><BILLDUE>3-Aug-26</BILLDUE><BILLOVERDUE>29</BILLOVERDUE>
            <BILLVCHTYPE>Receipt</BILLVCHTYPE>
            <BILLFIXED><BILLDATE>4-Aug-26</BILLDATE><BILLREF>714/26-27</BILLREF><BILLPARTY></BILLPARTY></BILLFIXED>
            <BILLOP>-25000.000</BILLOP><BILLCL>-25000.000</BILLCL><BILLDUE>3-Sep-26</BILLDUE><BILLOVERDUE>0</BILLOVERDUE>
            <BILLFIXED><BILLDATE></BILLDATE><BILLREF></BILLREF><BILLPARTY></BILLPARTY></BILLFIXED>
            <LEDBILLOP>-15000.000</LEDBILLOP><LEDBILLCL>-15000.000</LEDBILLCL>
            <BILLFIXED><BILLDATE></BILLDATE><BILLREF></BILLREF><BILLPARTY>Southgate Polymers</BILLPARTY></BILLFIXED>
            <BILLOP></BILLOP><BILLCL></BILLCL><BILLDUE></BILLDUE>
            <BILLFIXED><BILLDATE>17-Jul-26</BILLDATE><BILLREF>610/26-27</BILLREF><BILLPARTY></BILLPARTY></BILLFIXED>
            <BILLOP>-40000.000</BILLOP><BILLCL>-40000.000</BILLCL><BILLDUE>6-Aug-26</BILLDUE><BILLOVERDUE>26</BILLOVERDUE>
            <BILLFIXED><BILLDATE></BILLDATE><BILLREF></BILLREF><BILLPARTY></BILLPARTY></BILLFIXED>
            <LEDBILLOP>-40000.000</LEDBILLOP><LEDBILLCL>-40000.000</LEDBILLCL>
        </ENVELOPE>
        XML;
    }

    public function test_the_flat_stream_is_read_as_real_bills(): void
    {
        // THE REGRESSION THAT MATTERS. The values are SIBLINGS of BILLFIXED,
        // not children. A reader that looks for BILLCL as a child finds none,
        // returns zero, and the page stays empty while Tally is answering with
        // hundreds of bills.
        $this->assertCount(3, $this->parser()->parse($this->export()));
    }

    public function test_the_party_on_a_header_row_is_carried_down_to_its_bills(): void
    {
        $bills = $this->parser()->parse($this->export());

        $this->assertSame('Northwind Traders', $bills[0]['party_ledger_name']);
        $this->assertSame('Northwind Traders', $bills[1]['party_ledger_name']);
        $this->assertSame('Southgate Polymers', $bills[2]['party_ledger_name']);
    }

    public function test_each_bill_takes_the_values_that_follow_it(): void
    {
        $bills = $this->parser()->parse($this->export());

        // Order is the only thing associating a value with its bill, so an
        // off-by-one puts one client's money on another client's name.
        $this->assertSame('714/26-27', $bills[1]['bill_reference']);
        $this->assertSame('2026-08-04', $bills[1]['bill_date']);
        $this->assertSame('2026-09-03', $bills[1]['due_date']);
        $this->assertSame('25000.0000', $bills[1]['closing_amount']);
    }

    public function test_tallys_own_date_form_is_a_real_date(): void
    {
        $bills = $this->parser()->parse($this->export());

        // Without this every due date is null and the ageing spine stays empty
        // even though Tally stated the date plainly.
        $this->assertSame('2026-08-03', $bills[0]['due_date']);
        $this->assertSame('2026-08-06', $bills[2]['due_date']);
    }

    public function test_header_rows_and_subtotal_separators_are_not_bills(): void
    {
        foreach ($this->parser()->parse($this->export()) as $bill) {
            $this->assertNotNull($bill['bill_reference']);
            $this->assertNotNull($bill['closing_amount']);
        }
    }

    public function test_tallys_ledger_subtotals_are_ignored_so_no_party_is_counted_twice(): void
    {
        $bills = $this->parser()->parse($this->export());

        $net = '0';

        foreach ($bills as $bill) {
            $net = bcadd($net, (string) $bill['closing_amount'], 4);
        }

        // +10000 Cr, -25000 Dr, -40000 Dr in Tally's signs => 55000 net owed.
        // Counting the LEDBILL* rows as well would report 110000.
        $this->assertSame('55000.0000', $net);
    }

    public function test_the_sign_crosses_exactly_once(): void
    {
        $bills = $this->parser()->parse($this->export());

        // A Receipt stated Cr is a client CREDIT. Flipping it would show a
        // client who has paid as a debtor.
        $this->assertSame('-10000.0000', $bills[0]['closing_amount']);
        $this->assertSame('40000.0000', $bills[2]['closing_amount']);
    }

    public function test_a_bill_before_any_party_header_is_dropped_not_misattributed(): void
    {
        $orphan = <<<'XML'
        <ENVELOPE>
            <BILLFIXED><BILLDATE>3-Aug-26</BILLDATE><BILLREF>999</BILLREF><BILLPARTY></BILLPARTY></BILLFIXED>
            <BILLCL>-1000.000</BILLCL><BILLDUE>3-Aug-26</BILLDUE>
        </ENVELOPE>
        XML;

        $this->assertSame([], $this->parser()->parse($orphan));
    }

    public function test_a_utf16_export_is_read_rather_than_rejected(): void
    {
        // Tally's desktop export is UTF-16. Read as UTF-8 it is a wall of NUL
        // bytes and loadXML refuses it — which would reject the very file the
        // owner actually has.
        $utf16 = "\xFF\xFE".mb_convert_encoding($this->export(), 'UTF-16LE', 'UTF-8');

        $this->assertCount(3, $this->parser()->parse($utf16));
    }

    public function test_a_document_with_nothing_in_it_yields_nothing_rather_than_throwing(): void
    {
        $this->assertSame([], $this->parser()->parse(''));
        $this->assertSame([], $this->parser()->parse('not xml at all'));
        $this->assertSame([], $this->parser()->parse('<ENVELOPE></ENVELOPE>'));
    }

    public function test_a_wrapped_export_is_found_without_following_a_path(): void
    {
        // A live gateway export wraps the same stream in BODY/EXPORTDATA. #66:
        // a reader that follows a path reads the saved file perfectly and
        // returns zero against the real Tally.
        $wrapped = '<ENVELOPE><BODY><EXPORTDATA>'
            .'<BILLFIXED><BILLDATE></BILLDATE><BILLREF></BILLREF><BILLPARTY>Northwind Traders</BILLPARTY></BILLFIXED>'
            .'<BILLFIXED><BILLDATE>3-Aug-26</BILLDATE><BILLREF>567</BILLREF><BILLPARTY></BILLPARTY></BILLFIXED>'
            .'<BILLCL>-1000.000</BILLCL><BILLDUE>3-Aug-26</BILLDUE>'
            .'</EXPORTDATA></BODY></ENVELOPE>';

        $bills = $this->parser()->parse($wrapped);

        $this->assertCount(1, $bills);
        $this->assertSame('Northwind Traders', $bills[0]['party_ledger_name']);
        $this->assertSame('1000.0000', $bills[0]['closing_amount']);
    }
}
