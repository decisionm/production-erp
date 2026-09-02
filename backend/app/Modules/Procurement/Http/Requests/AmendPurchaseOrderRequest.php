<?php

namespace App\Modules\Procurement\Http\Requests;

use App\Modules\Inventory\Services\ItemService;
use App\Modules\Procurement\Http\Requests\Rules\PurchasableItem;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Support\PurchaseLineEligibility;
use App\Rules\PlainDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST /procurement/purchase-orders/{po}/amend (Phase 6, P6-01): the
 * replacement lines and schedules for a DRAFT, plus an optional reason kept
 * on the revision row. The line rules are StorePurchaseOrderRequest's —
 * the same shape create() takes — so an amended draft can never carry a
 * line the original could not. Whether the order MAY be amended (Draft,
 * not a Tally mirror) is PurchaseOrderService::amend()'s call, not this
 * request's: validation shapes the input; the state machine judges the
 * order.
 *
 * ONE DELIBERATE EXCEPTION to "never carry a line the original could not":
 * DEC-20260902-023 (see withValidator()) only judges a NEW or CHANGED line
 * against the finished-good/unclassified-reason rule — a line already on
 * the order, resubmitted unchanged, is grandfathered rather than forced
 * through a rule that postdates it.
 */
class AmendPurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            // The item half of "an amended draft can never carry a line the
            // original could not" (class docblock). It was the half that was
            // missing: create() refuses an item out of service, and until now
            // amend() would take one. Unconditional here — PurchaseOrderService
            // ::amend refuses a Tally mirror outright, so this rule has no
            // mirror scope to widen. That is pinned by
            // InactiveMasterGuardTest, not merely asserted here.
            'lines.*.item_id' => ['required', 'integer', 'exists:items,id', new PurchasableItem],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999', new PlainDecimal],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999999', new PlainDecimal],
            'lines.*.unclassified_reason' => ['nullable', 'string', 'max:255'],
            'lines.*.schedules' => ['sometimes', 'array'],
            'lines.*.schedules.*.due_date' => ['required_with:lines.*.schedules', 'date'],
            'lines.*.schedules.*.quantity' => ['required_with:lines.*.schedules', 'numeric', 'gt:0', 'max:99999999999', new PlainDecimal],
            'lines.*.schedules.*.tally_reference' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * DEC-20260902-023 reaches amend too: a NEW or CHANGED line is a fresh
     * choice, and meets the same rule create() does (a finished good
     * refused, an unclassified item needs a reason). A line already on the
     * order and resubmitted UNCHANGED is not a fresh choice — it is
     * grandfathered, exactly like a Draft raised before this decision
     * existed, or one whose item was fine when the line was written. Without
     * that carve-out, amending any OTHER line on an old order would force a
     * rewrite of every historical line the amend never touched, which is not
     * what "amend this line" means. Contrast PurchasableItem above, which IS
     * unconditional: an archived item is a state the picker already flags on
     * every read, so resubmitting it unchanged is still a live refusal, not
     * history.
     *
     * "Unchanged" is matched by content (item_id, quantity, unit_price AND
     * unclassified_reason) — no client-visible line id survives an amend to
     * match by — against the order's lines as they stand BEFORE this amend
     * replaces them, each existing line consumed at most once so two
     * identical incoming lines cannot both claim the same historical line as
     * their excuse. The reason is part of the match deliberately: a line
     * resubmitted with the same item/quantity/price but a STRIPPED reason is
     * not history repeating, it is someone clearing the one fact that made
     * the line legal, and PurchaseOrderService::amend() would otherwise
     * silently persist that as a null reason on an item still unclassified.
     * A stripped (or changed) reason therefore counts as CHANGED, so the
     * eligibility check runs and refuses it.
     *
     * Skipped entirely for a Tally mirror: PurchaseOrderService::amend()
     * refuses one outright (isTallyMirror), so there is nothing here to
     * scope — same reason StorePurchaseOrderRequest's hook skips `source:
     * tally`.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $order = $this->route('purchase_order');
            if (! $order instanceof PurchaseOrder || $order->isTallyMirror()) {
                return;
            }

            $lines = (array) $this->input('lines', []);
            $unchanged = $this->unchangedLineIndexes($order, $lines);
            $newOrChanged = array_filter($lines, fn ($index) => ! isset($unchanged[$index]), ARRAY_FILTER_USE_KEY);

            $ids = array_values(array_unique(array_filter(array_map(
                fn ($line) => isset($line['item_id']) ? (int) $line['item_id'] : null,
                $newOrChanged,
            ))));

            PurchaseLineEligibility::validate(
                $newOrChanged,
                fn (string $key, string $message) => $validator->errors()->add($key, $message),
                app(ItemService::class)->categoriesFor($ids),
            );
        });
    }

    /**
     * Which $lines indexes name the SAME item, quantity, unit_price AND
     * unclassified_reason as a line the order already carries — content
     * equality, decimal-safe (bccomp, scale 4: the cast columns' scale) for
     * the numbers and trim-to-null for the reason (an empty string and a
     * blank-only string both mean "no reason", matching
     * PurchaseLineEligibility's own reading of the field), each existing
     * line eligible to grandfather at most one incoming line.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, true> incoming index => true, for indexes judged unchanged
     */
    private function unchangedLineIndexes(PurchaseOrder $order, array $lines): array
    {
        $pool = $order->lines()->get(['item_id', 'quantity', 'unit_price', 'unclassified_reason'])
            ->map(fn ($line) => [
                'item_id' => (int) $line->item_id,
                'quantity' => (string) $line->quantity,
                'unit_price' => (string) $line->unit_price,
                'unclassified_reason' => self::normalizeReason($line->unclassified_reason),
            ])
            ->all();

        $unchanged = [];

        foreach ($lines as $index => $line) {
            $itemId = isset($line['item_id']) ? (int) $line['item_id'] : null;
            $quantity = $line['quantity'] ?? null;
            $unitPrice = $line['unit_price'] ?? null;
            $reason = self::normalizeReason($line['unclassified_reason'] ?? null);

            if ($itemId === null || ! is_numeric($quantity) || ! is_numeric($unitPrice)) {
                continue;
            }

            foreach ($pool as $poolIndex => $existing) {
                if ($existing['item_id'] === $itemId
                    && bccomp($existing['quantity'], (string) $quantity, 4) === 0
                    && bccomp($existing['unit_price'], (string) $unitPrice, 4) === 0
                    && $existing['unclassified_reason'] === $reason
                ) {
                    $unchanged[$index] = true;
                    unset($pool[$poolIndex]);
                    break;
                }
            }
        }

        return $unchanged;
    }

    /** Trim-to-null: an absent, empty, or blank-only reason is "no reason" — the same reading PurchaseLineEligibility::validate() gives the field. */
    private static function normalizeReason(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }
}
