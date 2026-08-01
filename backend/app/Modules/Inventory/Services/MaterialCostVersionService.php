<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Enums\MaterialCostVersionKind;
use App\Modules\Inventory\Models\MaterialCostVersion;
use App\Modules\Inventory\Models\MaterialLot;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of material_cost_versions.
 *
 * Every method here APPENDS. There is deliberately no update() and no
 * delete() — not "none exposed on a route", none at all — because the value
 * of a cost history is that yesterday's number is still readable after
 * today's correction. A revision is a new row whose supersedes_id points at
 * the row it replaces.
 *
 * NOTHING in this class touches stock. No stock_movement, no
 * stock_balance, no batch re-pricing, no Tally voucher. The moving-average
 * valuation the Accounts team approved is not this layer's business, and a
 * closed batch is never silently rewritten because a freight bill turned
 * up. Cost versions are facts on the record; who chooses to read them, and
 * when, is a separate decision the factory has not yet made.
 */
class MaterialCostVersionService
{
    /**
     * Said out loud on every response from the cost-version endpoints, so
     * nobody has to guess whether filing an invoice rate just moved their
     * stock or re-costed last week's production. It did not.
     */
    public const EFFECT_NOTE = 'Recorded for the record only. A cost version does not change stock balances, '
        .'stock movements, completed batches or Tally — it is a rate fact that later readers may consult.';

    /**
     * A lot's full cost history, oldest first, with who recorded each.
     *
     * @return Collection<int, MaterialCostVersion>
     */
    public function forLot(MaterialLot $lot): Collection
    {
        return $lot->costVersions()->with('createdBy')->get();
    }

    /**
     * The system's own statement of what the lot cost on arrival. Called
     * once, at lot creation, and never again for that lot: this is the
     * original rate, and the original rate is not a thing anyone re-files.
     *
     * Returns null when the lot arrived with no known rate (opening stock),
     * because a receipt version with no receipt rate would be a fiction.
     */
    public function recordReceipt(MaterialLot $lot, ?int $createdBy, ?string $note = null): ?MaterialCostVersion
    {
        $rate = $lot->receiptRatePerKg();

        if ($rate === null) {
            return null;
        }

        return $lot->costVersions()->create([
            'rate_per_kg' => $rate,
            'kind' => MaterialCostVersionKind::Receipt,
            'note' => $note,
            'created_by' => $createdBy,
            'supersedes_id' => null,
        ]);
    }

    /**
     * Append a revision — an invoice rate, a landed cost, a correction.
     *
     * The lot row is locked for the read-then-write so two people filing at
     * once cannot both read the same head and produce two versions claiming
     * to supersede it. The lot itself is NOT modified: receipt_rate_per_kg
     * stays exactly as it was stamped, which is the rule that makes the
     * original rate trustworthy.
     *
     * @param  array{rate_per_kg: string|float, kind: string, note: string}  $data
     */
    public function append(MaterialLot $lot, array $data, ?int $createdBy): MaterialCostVersion
    {
        return DB::transaction(function () use ($lot, $data, $createdBy) {
            // Serialises concurrent appends on this lot; the lot row is read
            // only, never written — see the class note on the original rate.
            MaterialLot::query()->whereKey($lot->getKey())->lockForUpdate()->first();

            $head = $lot->costVersions()->reorder()->orderByDesc('id')->lockForUpdate()->first();

            $version = $lot->costVersions()->create([
                'rate_per_kg' => bcadd((string) $data['rate_per_kg'], '0', 4),
                'kind' => MaterialCostVersionKind::from($data['kind']),
                'note' => $data['note'],
                'created_by' => $createdBy,
                'supersedes_id' => $head?->id,
            ]);

            // The in-memory lot may be carrying a stale history (the caller
            // loaded it before this append); refresh it so a response built
            // from it reports the rate that is now true.
            $lot->unsetRelation('costVersions');

            return $version->load('createdBy');
        });
    }
}
