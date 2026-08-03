<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which product standards a machine may run today, and which one applies.
 *
 * A product standard is offered on EVERY active machine, and the absence of
 * an approved machine-product configuration is not even a warning — running
 * on the product standard is the normal case. The factory's workbook has no
 * machine column; blocking or nagging on machine-specific settings would
 * interrupt every start while producing no new information.
 *
 * What the app does instead is record which machine actually ran which
 * standard. After a week of shifts that record IS the machine-product
 * mapping, derived from what happened rather than from what someone
 * remembered.
 */
class ProductionStandardResolver
{
    public function __construct(
        private readonly MachineCapabilityService $machineCapability = new MachineCapabilityService,
    ) {}

    /**
     * Every standard variant for a product, newest-usable first.
     *
     * @return Collection<int, ProductionStandard>
     */
    public function variantsFor(int $itemId): Collection
    {
        return ProductionStandard::query()
            ->with('packagings')
            ->where('item_id', $itemId)
            // Unresolved variants stay visible — a supervisor may know which
            // of two cycle times applies to the machine in front of them,
            // and hiding the choice would leave them no way to say so.
            ->orderByRaw("CASE status WHEN 'approved' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END")
            ->orderBy('cavities')
            ->orderBy('cycle_time')
            ->get();
    }

    /**
     * The standard to use for a run: the one explicitly chosen, else the
     * only one, else null when the product has several and the supervisor
     * has not picked.
     */
    public function resolve(int $itemId, ?int $standardId = null): ?ProductionStandard
    {
        if ($standardId !== null) {
            // Scoped to the item, not a bare find(). The id arrives from a
            // client and the request layer validates only that the standard
            // EXISTS (StartBatchRequest, BatchPreviewRequest), so an id
            // belonging to a different product was previously applied in full:
            // its cavities, weight and cycle time became the run's frozen
            // standard, and every expected figure and the Tally voucher derived
            // from another bottle's numbers. Returning null instead degrades
            // correctly to the "choose a standard" warning.
            return ProductionStandard::query()
                ->with('packagings')
                ->where('item_id', $itemId)
                ->find($standardId);
        }

        $variants = $this->variantsFor($itemId);

        // Exactly one variant means there is nothing to ask about.
        return $variants->count() === 1 ? $variants->first() : null;
    }

    /**
     * The packaging option for a run: the one chosen, else the default, else
     * the only one. Null when the standard offers several and none is
     * marked default — the caller must ask.
     */
    public function resolvePackaging(?ProductionStandard $standard, ?int $packagingId = null): ?ProductionStandardPackaging
    {
        if ($standard === null) {
            return null;
        }

        if ($packagingId !== null) {
            return $standard->packagings->firstWhere('id', $packagingId);
        }

        if ($standard->packagings->count() === 1) {
            return $standard->packagings->first();
        }

        return $standard->packagings->firstWhere('is_default', true);
    }

    /**
     * Advisory notes for an intended run. Never blocking — every one of
     * these is a "you should know", not a "you may not".
     *
     * @return list<array{code: string, message: string}>
     */
    public function warningsFor(
        ?ProductionStandard $standard,
        ?ProductionStandardPackaging $packaging,
        int $itemId,
        ?int $workCenterId = null,
        // The cavities this run will actually use, once a machine
        // configuration has been resolved. The cavity rule is judged on this
        // rather than on the standard's mould figure; null means the caller
        // has not resolved a run yet and the standard stands in.
        ?int $activeCavities = null,
    ): array {
        $warnings = [];

        if ($standard === null) {
            $count = $this->variantsFor($itemId)->count();
            $warnings[] = $count > 1
                ? ['code' => 'standard_choice_required', 'message' => "This product has {$count} standard variants — choose which one this run uses."]
                : ['code' => 'no_product_standard', 'message' => 'No imported production standard for this product — cycle time, cavities and weight must be entered by hand.'];

            return $warnings;
        }

        // Running on the factory product standard is the NORMAL case, so it
        // gets no notice at all. This used to warn "no approved machine
        // mapping yet" (later "machine's own settings not saved yet") on every
        // start without a configuration — which is most starts — and the owner
        // read it as an error every single time, because a yellow box IS an
        // error to the person it interrupts. Which figures govern the run is
        // already visible in the preview's own numbers and in the green
        // configuration banner when a machine override exists; the batch
        // snapshot records the source either way. A warning must mean
        // "something is off", or the one that matters drowns.

        if ($standard->status === 'unresolved') {
            $warnings[] = ['code' => 'standard_unresolved', 'message' => (string) $standard->unresolved_reason];
        }

        if ($packaging === null && $standard->packagings->count() > 1) {
            $warnings[] = ['code' => 'packaging_choice_required', 'message' => 'This product can be packed more than one way — choose pouch or tray.'];
        }

        if ($standard->unit_weight_grams === null) {
            $warnings[] = ['code' => 'weight_missing', 'message' => 'No unit weight on this standard — kg figures cannot be calculated.'];
        }

        // The cavity rule: high-cavity moulds belong on specific machines. Sits
        // with the other advisories on purpose — it is a "you should know",
        // and the run gets recorded either way.
        $cavityWarning = $this->machineCapability->warningFor($standard, $workCenterId, $activeCavities);
        if ($cavityWarning !== null) {
            $warnings[] = $cavityWarning;
        }

        return $warnings;
    }
}
