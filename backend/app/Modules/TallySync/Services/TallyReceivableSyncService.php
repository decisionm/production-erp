<?php

namespace App\Modules\TallySync\Services;

use App\Modules\TallySync\Models\TallyPendingSalesOrder;
use App\Modules\TallySync\Models\TallyReceivableBill;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * WHAT THE AGENT READ OUT OF TALLY'S OUTSTANDINGS, written down.
 *
 * The inbound half of the CRM's client-outstanding page. The agent exports
 * Bills Receivable and Sales Order Outstanding for the bound company and posts
 * the rows here. This service writes them and nothing else: it posts no
 * voucher, touches no stock, changes no master, and creates no customer.
 *
 * A PULL REPLACES THE COMPANY'S SET. Both reports are closing positions as at a
 * date, so a bill that has since been settled is simply ABSENT from the next
 * export. Upserting on a bill identity would leave every settled bill sitting
 * here for ever, still counted — an outstanding total that only ever grows.
 * Delete-then-insert says exactly what Tally said. (PurchaseRateSyncService
 * upserts instead, and is right to: a Day Book pull is a window over an
 * append-only history, where absence means "outside the window".)
 *
 * THE REPLACE IS SCOPED TO THE COMPANY AND RUNS IN ONE TRANSACTION, so a
 * failed pull leaves the previous position standing rather than an empty page.
 *
 * AN EMPTY EXPORT IS REFUSED, NOT APPLIED. "Tally answered with nothing" and
 * "this factory is owed nothing" are different facts, and on the purchase-rate
 * path they were indistinguishable until a parser bug had already reported
 * zero against a live Tally (#64, #66). Wiping a real position to zero on the
 * strength of an answer we may simply have failed to parse is the destructive
 * version of that same mistake, so a pull carrying no rows AT ALL leaves the
 * table untouched and says so in its summary. A pull that genuinely finds
 * nothing outstanding is reported as `skipped_empty` and an operator can clear
 * the position deliberately.
 */
class TallyReceivableSyncService
{
    /**
     * @param  array<int, array<string, mixed>>  $bills
     * @param  array<int, array<string, mixed>>  $orders
     * @return array{bills: int, orders: int, parties: int, as_of: string, skipped_empty: bool}
     */
    public function sync(array $bills, array $orders, string $asOf, ?string $company = null): array
    {
        $syncedAt = Carbon::now();

        $billRows = $this->billRows($bills, $asOf, $company, $syncedAt);
        $orderRows = $this->orderRows($orders, $asOf, $company, $syncedAt);

        // See the class docblock: nothing at all is treated as "we did not
        // understand the answer", never as "the factory is owed nothing".
        if ($billRows === [] && $orderRows === []) {
            return [
                'bills' => 0,
                'orders' => 0,
                'parties' => 0,
                'as_of' => $asOf,
                'skipped_empty' => true,
            ];
        }

        DB::transaction(function () use ($billRows, $orderRows, $company): void {
            TallyReceivableBill::query()->where('tally_company', $company)->delete();
            TallyPendingSalesOrder::query()->where('tally_company', $company)->delete();

            // Chunked because a factory's full receivables position is
            // thousands of rows and a single insert of that width exceeds
            // MySQL's placeholder limit.
            foreach (array_chunk($billRows, 500) as $chunk) {
                TallyReceivableBill::query()->insert($chunk);
            }

            foreach (array_chunk($orderRows, 500) as $chunk) {
                TallyPendingSalesOrder::query()->insert($chunk);
            }
        });

        $parties = collect($billRows)->pluck('party_ledger_name')
            ->merge(collect($orderRows)->pluck('party_ledger_name'))
            ->unique()
            ->count();

        return [
            'bills' => count($billRows),
            'orders' => count($orderRows),
            'parties' => $parties,
            'as_of' => $asOf,
            'skipped_empty' => false,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function billRows(array $bills, string $asOf, ?string $company, Carbon $syncedAt): array
    {
        $rows = [];

        foreach ($bills as $bill) {
            $party = trim((string) ($bill['party_ledger_name'] ?? ''));
            $amount = $bill['closing_amount'] ?? null;

            // A bill with no party cannot be chased and a bill with no closing
            // amount is not an outstanding. Neither is stored half-formed.
            if ($party === '' || $amount === null || ! is_numeric($amount)) {
                continue;
            }

            $rows[] = [
                'party_ledger_name' => $party,
                'party_ledger_guid' => self::text($bill['party_ledger_guid'] ?? null),
                'bill_reference' => self::text($bill['bill_reference'] ?? null),
                'bill_date' => self::date($bill['bill_date'] ?? null),
                'due_date' => self::date($bill['due_date'] ?? null),
                'closing_amount' => $amount,
                'opening_amount' => is_numeric($bill['opening_amount'] ?? null) ? $bill['opening_amount'] : null,
                'as_of' => $asOf,
                'tally_company' => $company,
                'tally_synced_at' => $syncedAt,
                'created_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ];
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function orderRows(array $orders, string $asOf, ?string $company, Carbon $syncedAt): array
    {
        $rows = [];

        foreach ($orders as $order) {
            $party = trim((string) ($order['party_ledger_name'] ?? ''));

            if ($party === '') {
                continue;
            }

            $quantity = $order['pending_quantity'] ?? null;
            $amount = $order['pending_amount'] ?? null;

            $rows[] = [
                'party_ledger_name' => $party,
                'party_ledger_guid' => self::text($order['party_ledger_guid'] ?? null),
                'order_reference' => self::text($order['order_reference'] ?? null),
                'order_date' => self::date($order['order_date'] ?? null),
                'due_date' => self::date($order['due_date'] ?? null),
                'stock_item_name' => self::text($order['stock_item_name'] ?? null),
                'pending_quantity' => is_numeric($quantity) ? $quantity : null,
                'quantity_unit' => self::text($order['quantity_unit'] ?? null),
                'pending_amount' => is_numeric($amount) ? $amount : null,
                'as_of' => $asOf,
                'tally_company' => $company,
                'tally_synced_at' => $syncedAt,
                'created_at' => $syncedAt,
                'updated_at' => $syncedAt,
            ];
        }

        return $rows;
    }

    /** An empty string is absence, not a value — never stored as one. */
    private static function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    /** Only a real ISO date survives; anything else is absence. */
    private static function date(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1 ? $text : null;
    }
}
