<?php

namespace App\Modules\Inventory\Http\Requests\Concerns;

use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Enums\ItemTrackingType;
use App\Modules\Inventory\Models\Enums\SerialNumberStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\SerialNumber;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Validation\Validator;

/**
 * `items.tracking_type` READ AS A RULE, on the three generic stock doors.
 *
 * The column has been a label since 19-Jul-2026: the SPA shows a Batch picker
 * for a batch-tracked item and a Serial picker for a serial-tracked one, and
 * the server accepted whatever arrived — including a batch belonging to a
 * DIFFERENT ITEM, a serial number issued twice, and a batch-tracked lot moved
 * with no batch at all. See TrackingIdentityIntegrityTest for each one.
 *
 * WHY HERE AND NOT IN THE SERVICE. These are rules about what a CLIENT may
 * ask for through `/inventory/stock-movements/*`. StockMovementService is
 * also the writer for production completion, Tally reconcile, goods receipt,
 * deliveries and rework — writers that pass no identity at all and whose
 * behaviour is deliberately out of scope. The one rule that IS a service-wide
 * invariant, "the identity belongs to the item", lives there instead, because
 * it is never right for any caller.
 *
 * WHAT THIS DOES NOT DECIDE. Which items should be batch- or serial-tracked
 * is the factory's call and is not read from anywhere here: this honours the
 * setting already on the record, and an item nobody has classified
 * (`tracking_type` = none, the column default) keeps behaving exactly as it
 * always has — quantity only, no identity.
 */
trait ValidatesTrackingIdentity
{
    /**
     * The request key naming the warehouse a batch/serial must ALREADY be in
     * for this action, or null on a receipt, where the identity is arriving
     * and has no current location to check.
     */
    abstract protected function currentWarehouseKey(): ?string;

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // The id rules run first. If any of them already failed, the row
            // may not exist at all and every message below would be a second
            // sentence about the same mistake.
            if ($validator->errors()->hasAny(['item_id', 'batch_id', 'serial_number_id'])) {
                return;
            }

            $item = Item::find($this->input('item_id'));
            if ($item === null) {
                return;
            }

            $batch = $this->identity(Batch::class, 'batch_id');
            $serial = $this->identity(SerialNumber::class, 'serial_number_id');

            match ($item->tracking_type) {
                ItemTrackingType::Batch => $this->judgeBatchTracked($validator, $item, $batch, $serial),
                ItemTrackingType::Serial => $this->judgeSerialTracked($validator, $item, $batch, $serial),
                default => $this->judgeUntracked($validator, $item, $batch, $serial),
            };
        });
    }

    private function judgeUntracked(Validator $validator, Item $item, ?Batch $batch, ?SerialNumber $serial): void
    {
        if ($batch !== null) {
            $validator->errors()->add('batch_id', "{$item->name} is not batch-tracked, so no batch may be recorded against it.");
        }

        if ($serial !== null) {
            $validator->errors()->add('serial_number_id', "{$item->name} is not serial-tracked, so no serial number may be recorded against it.");
        }
    }

    private function judgeBatchTracked(Validator $validator, Item $item, ?Batch $batch, ?SerialNumber $serial): void
    {
        if ($serial !== null) {
            $validator->errors()->add('serial_number_id', "{$item->name} is batch-tracked, not serial-tracked.");
        }

        if ($batch === null) {
            $validator->errors()->add('batch_id', "{$item->name} is batch-tracked: name the batch this stock belongs to.");

            return;
        }

        if ($batch->item_id !== $item->id) {
            $validator->errors()->add('batch_id', "Batch {$batch->batch_number} belongs to a different item.");
        }
    }

    private function judgeSerialTracked(Validator $validator, Item $item, ?Batch $batch, ?SerialNumber $serial): void
    {
        if ($batch !== null) {
            $validator->errors()->add('batch_id', "{$item->name} is serial-tracked, not batch-tracked.");
        }

        if ($serial === null) {
            $validator->errors()->add('serial_number_id', "{$item->name} is serial-tracked: name the serial number this stock is.");

            return;
        }

        if ($serial->item_id !== $item->id) {
            $validator->errors()->add('serial_number_id', "Serial number {$serial->serial_number} belongs to a different item.");

            return;
        }

        $this->currentWarehouseKey() === null
            ? $this->judgeArriving($validator, $serial)
            : $this->judgeLeaving($validator, $serial);
    }

    /**
     * A RECEIPT. One physical unit, one row: receiving a serial number that is
     * already in stock does not mint a second unit, it silently re-stamps the
     * one row's warehouse — so the unit teleports and the store it left keeps
     * the quantity.
     *
     * Only `in_stock` is refused, deliberately. Whether a consumed or scrapped
     * unit may come back through a receipt is a real question about returns
     * that nobody has answered, and refusing it here would be answering it.
     */
    private function judgeArriving(Validator $validator, SerialNumber $serial): void
    {
        if ($serial->status !== SerialNumberStatus::InStock) {
            return;
        }

        $where = $this->warehouseLabel($serial->warehouse_id);

        $validator->errors()->add(
            'serial_number_id',
            "Serial number {$serial->serial_number} is already in stock{$where}.",
        );
    }

    /**
     * AN ISSUE OR A TRANSFER. The unit has to be in stock, and it has to be in
     * the store it is being taken out of — the two checks that between them
     * stop the same serial number being issued twice, issued from a store it
     * was never in, and transferred out of a store it has already left.
     */
    private function judgeLeaving(Validator $validator, SerialNumber $serial): void
    {
        if ($serial->status !== SerialNumberStatus::InStock) {
            $validator->errors()->add(
                'serial_number_id',
                "Serial number {$serial->serial_number} is {$serial->status->value}, not in stock.",
            );

            return;
        }

        $from = (int) $this->input($this->currentWarehouseKey());

        if ($serial->warehouse_id !== null && $serial->warehouse_id !== $from) {
            $validator->errors()->add(
                'serial_number_id',
                "Serial number {$serial->serial_number} is in {$this->warehouseCode($serial->warehouse_id)}, not {$this->warehouseCode($from)}.",
            );
        }
    }

    /**
     * @param  class-string<Batch|SerialNumber>  $model
     */
    private function identity(string $model, string $key): Batch|SerialNumber|null
    {
        $id = $this->input($key);

        return $id === null || $id === '' ? null : $model::find($id);
    }

    private function warehouseLabel(?int $warehouseId): string
    {
        return $warehouseId === null ? '' : ' at '.$this->warehouseCode($warehouseId);
    }

    private function warehouseCode(int $warehouseId): string
    {
        return (string) (Warehouse::find($warehouseId)?->code ?? "warehouse #{$warehouseId}");
    }
}
