<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\ProductionStandardPackaging;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which product standards a machine may run today, and which one applies.
 *
 * Watch mode is the operating state until machine-specific mappings exist:
 * a product standard is offered on EVERY active machine, and the absence of
 * an approved machine-product mapping is a warning, never a refusal. The
 * factory has 86 standards and no machine column; blocking on the mapping
 * would stop production entirely while producing no new information.
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
    ): array {
        $warnings = [];

        if ($standard === null) {
            $count = $this->variantsFor($itemId)->count();
            $warnings[] = $count > 1
                ? ['code' => 'standard_choice_required', 'message' => "This product has {$count} standard variants — choose which one this run uses."]
                : ['code' => 'no_product_standard', 'message' => 'No imported production standard for this product — cycle time, cavities and weight must be entered by hand.'];

            return $warnings;
        }

        // The headline of watch mode: the standard is a product-level figure
        // that no one has yet confirmed for THIS machine.
        $warnings[] = [
            'code' => 'machine_mapping_unconfirmed',
            'message' => 'No approved machine–product mapping yet. Using the factory product standard; this run will be recorded as evidence for approving the mapping later.',
        ];

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
        $cavityWarning = $this->machineCapability->warningFor($standard, $workCenterId);
        if ($cavityWarning !== null) {
            $warnings[] = $cavityWarning;
        }

        return $warnings;
    }
}
