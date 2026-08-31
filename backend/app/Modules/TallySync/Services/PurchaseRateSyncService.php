<?php

namespace App\Modules\TallySync\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\TallySync\Models\TallyPurchaseRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * WHAT THE AGENT READ OUT OF THE FACTORY'S DAY BOOK, written down.
 *
 * The inbound half of the purchase-rate lookup. The agent exports the Day Book
 * for a date range, keeps only Purchase Order and Purchase vouchers, drops the
 * ones Tally itself marks cancelled/deleted/optional, and posts the lines here.
 * This service upserts them and nothing else. It posts no voucher, touches no
 * stock, and changes no master.
 *
 * IDEMPOTENT ON (voucher_guid, line_index), because a Day Book pull overlaps
 * the last one by design — a voucher altered after it was first read must
 * update rather than duplicate. A voucher that came back with FEWER lines than
 * it had before has its tail rows deleted, so a line removed in Tally stops
 * being quotable here; leaving it would let a withdrawn rate go on suggesting
 * itself.
 *
 * IT DOES NOT DECIDE WHAT A RATE MEANS. The rate arrives with the unit Tally
 * quoted it per, and both are stored as given. Whether that rate may prefill an
 * ERP line is PurchaseRateLookup's question, and the answer turns on the unit.
 */
class PurchaseRateSyncService
{
    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{created: int, updated: int, deleted: int, total: int}
     */
    public function sync(array $lines, ?string $company = null): array
    {
        $created = 0;
        $updated = 0;
        $deleted = 0;

        // One stamp for the pull, so every line read together reports the same
        // "synced at" — see LedgerSyncService for the same reasoning.
        $syncedAt = Carbon::now();

        // Resolve Tally stock item names to the ERP's mirrored items once,
        // rather than per line. A name with no mirrored item resolves to null
        // and stays null: the lookup can still match on the name, and
        // inventing a link would be worse than not having one.
        $itemGuidsByName = Item::query()
            ->whereNotNull('tally_stock_item_guid')
            ->pluck('tally_stock_item_guid', 'name');

        DB::transaction(function () use ($lines, $company, $syncedAt, $itemGuidsByName, &$created, &$updated, &$deleted): void {
            $seen = [];

            foreach ($lines as $line) {
                $guid = trim((string) ($line['voucher_guid'] ?? ''));
                $type = (string) ($line['voucher_type'] ?? '');

                // A line with no voucher identity, no item, no rate or an
                // unknown kind cannot be quoted from and is dropped rather
                // than stored half-formed.
                if ($guid === '' || ! in_array($type, TallyPurchaseRate::TYPES, true)) {
                    continue;
                }

                $itemName = trim((string) ($line['stock_item_name'] ?? ''));
                $rate = $line['rate_value'] ?? null;
                $date = trim((string) ($line['voucher_date'] ?? ''));

                if ($itemName === '' || $rate === null || $date === '') {
                    continue;
                }

                $index = (int) ($line['line_index'] ?? 0);
                $seen[$guid][] = $index;

                $attributes = [
                    'voucher_type' => $type,
                    'voucher_number' => self::text($line['voucher_number'] ?? null),
                    'voucher_reference' => self::text($line['voucher_reference'] ?? null),
                    'voucher_date' => $date,
                    'party_ledger_name' => (string) ($line['party_ledger_name'] ?? ''),
                    'party_gstin' => self::text($line['party_gstin'] ?? null),
                    'stock_item_name' => $itemName,
                    'tally_stock_item_guid' => $itemGuidsByName[$itemName] ?? null,
                    'rate_value' => $rate,
                    'rate_unit' => self::text($line['rate_unit'] ?? null),
                    'quantity' => $line['quantity'] ?? null,
                    'quantity_unit' => self::text($line['quantity_unit'] ?? null),
                    'amount' => $line['amount'] ?? null,
                    'cgst_rate' => $line['cgst_rate'] ?? null,
                    'sgst_rate' => $line['sgst_rate'] ?? null,
                    'igst_rate' => $line['igst_rate'] ?? null,
                    'cess_rate' => $line['cess_rate'] ?? null,
                    'hsn_code' => self::text($line['hsn_code'] ?? null),
                    'purchase_ledger_name' => self::text($line['purchase_ledger_name'] ?? null),
                    'tally_company' => $company,
                    'tally_synced_at' => $syncedAt,
                ];

                $existing = TallyPurchaseRate::where('voucher_guid', $guid)->where('line_index', $index)->first();

                if ($existing !== null) {
                    $existing->fill($attributes)->save();
                    $updated++;

                    continue;
                }

                TallyPurchaseRate::create(['voucher_guid' => $guid, 'line_index' => $index, ...$attributes]);
                $created++;
            }

            // A voucher this pull carried is now fully described by what it
            // carried. Any line beyond that is one Tally no longer has.
            foreach ($seen as $guid => $indexes) {
                $deleted += TallyPurchaseRate::where('voucher_guid', $guid)
                    ->whereNotIn('line_index', $indexes)
                    ->delete();
            }
        });

        return ['created' => $created, 'updated' => $updated, 'deleted' => $deleted, 'total' => count($lines)];
    }

    private static function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }
}
