<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\FinishedCarton;
use App\Modules\Production\Models\ShiftProductionEntry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Carton identity generation and lookup.
 *
 * THE COUNT IS THE PACKED (GROSS) COUNT: cartons are physical objects packed
 * on the floor before QC nets anything — a QC rejection reduces approved
 * finished goods, not the number of boxes that exist. Full cartons carry the
 * run's pieces-per-box; a loose remainder becomes one partial carton
 * labelled as such.
 *
 * IDEMPOTENT BY CONSTRUCTION: carton numbers are {batch}-C{seq} and unique;
 * regenerating fills gaps only (there are none in practice) and never mints
 * a second identity for the same physical box. Reprint is a plain read.
 */
class FinishedCartonService
{
    public function generateFor(ShiftProductionEntry $entry, ?int $userId): Collection
    {
        if ($entry->batch_status !== BatchStatus::Completed) {
            throw ValidationException::withMessages([
                'entry' => 'this batch is still running — cartons are labelled once the batch is completed and counted',
            ]);
        }

        return DB::transaction(function () use ($entry, $userId) {
            $existing = $entry->cartons()->count();
            if ($existing > 0) {
                return $this->listFor($entry);
            }

            // Packed = gross where a quality check has already netted the
            // entry; otherwise quantity_produced IS the packed count.
            $packed = (string) ($entry->gross_quantity_produced ?? $entry->quantity_produced ?? '0');
            $perBox = (string) ($entry->nos_per_box ?? ($entry->config_snapshot['nos_per_box'] ?? '0'));

            if (bccomp($packed, '0', 4) !== 1) {
                throw ValidationException::withMessages([
                    'entry' => 'this batch recorded no packed pieces — there is nothing to label',
                ]);
            }

            $batchNo = $entry->batch_number ?? "SPE-{$entry->id}";
            $sequence = 0;
            $rows = [];

            if (bccomp($perBox, '0', 4) === 1) {
                $fullBoxes = (int) bcdiv($packed, $perBox, 0);
                for ($i = 0; $i < $fullBoxes; $i++) {
                    $rows[] = [
                        'carton_no' => sprintf('%s-C%02d', $batchNo, ++$sequence),
                        'pieces' => $perBox,
                        'is_partial' => false,
                    ];
                }
                $remainder = bcsub($packed, bcmul((string) $fullBoxes, $perBox, 4), 4);
                if (bccomp($remainder, '0', 4) === 1) {
                    $rows[] = [
                        'carton_no' => sprintf('%s-C%02d', $batchNo, ++$sequence),
                        'pieces' => $remainder,
                        'is_partial' => true,
                    ];
                }
            } else {
                // No pieces-per-box on the run: one identity for the whole
                // packed count, honestly partial (an unknown box size must
                // not invent full boxes).
                $rows[] = [
                    'carton_no' => sprintf('%s-C%02d', $batchNo, ++$sequence),
                    'pieces' => $packed,
                    'is_partial' => true,
                ];
            }

            foreach ($rows as $row) {
                $entry->cartons()->create([
                    ...$row,
                    'item_id' => $entry->item_id,
                    'status' => FinishedCarton::STATUS_IN_STOCK,
                    'created_by' => $userId,
                ]);
            }

            return $this->listFor($entry);
        });
    }

    /**
     * The batch's cartons, loaded for the label: each carton carries its item
     * and the (one, shared) entry with machine and shift, so the resource can
     * print the batch spine and compute the net weight without a query per
     * box. This is also the reprint read — identities never change.
     */
    public function listFor(ShiftProductionEntry $entry): Collection
    {
        $entry->loadMissing(['workCenter', 'shift']);

        return $entry->cartons()->with('item')->orderBy('id')->get()
            ->each(fn (FinishedCarton $carton) => $carton->setRelation('entry', $entry));
    }

    /** The traceability read: one scanned carton back to its batch. */
    public function lookup(string $cartonNo): FinishedCarton
    {
        $carton = FinishedCarton::query()
            ->with(['item', 'entry.workCenter', 'entry.shift', 'delivery'])
            ->where('carton_no', $cartonNo)
            ->first();

        if ($carton === null) {
            throw ValidationException::withMessages([
                'carton_no' => "No carton carries the code {$cartonNo}.",
            ]);
        }

        return $carton;
    }
}
