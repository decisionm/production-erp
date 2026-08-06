<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\Enums\BatchStatus;
use App\Modules\Production\Models\ShiftProductionEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ONE PAGE OF THE FACTORY'S PRODUCTION REPORT, TYPED IN ONE GO.
 *
 * The owner's stated priority (05-Aug): "the daily production entry, each page
 * needs to enter in our app, and with consumption — all raw material, packing and
 * production material all goes to Tally."
 *
 * A page is a date, a shift, and ten to twelve machine rows. Through the ordinary
 * screens that is two acts per row — Start Batch, then Complete Batch, each with
 * its own dialog — so a shift already finished costs something like sixty
 * interactions to record. The floor does this every day for three shifts. That
 * arithmetic is the feature.
 *
 * ============================ ROW BY ROW, NOT PAGE ============================
 *
 * Each row gets its OWN transaction, and a row that fails is reported beside the
 * ones that succeeded. A page-wide transaction would be the obvious choice and
 * the wrong one: eleven good rows lost because the twelfth names a product whose
 * standard is incomplete means the supervisor types the whole page again, and
 * types it while annoyed. Partial success is the honest outcome here — the eleven
 * shifts really did happen.
 *
 * The rows are independent in the domain too. Each is a different machine running
 * a different product; nothing about row 7 depends on row 6 having landed.
 *
 * ============================== NOTHING IS NEW ================================
 *
 * This composes startBatch() and completeBatch() and adds no rule of its own.
 * Every readiness check, the weight resolution, the kilogram arithmetic, the
 * consumption booking and the whole approval chain behave exactly as they do when
 * a supervisor clicks through the dialogs — because they ARE that code. A bulk
 * path that reimplemented any of it would drift from the single path within a
 * month, and the drift would show up as a Tally voucher nobody could explain.
 *
 * Deliberately NOT approved, quality-checked or posted here. This records what
 * the paper says happened; the four-eyes chain is a separate act by separate
 * people, and a bulk entry that also approved itself would defeat the control
 * this factory's accountant relies on.
 *
 * ============================== TYPED TWICE ===================================
 *
 * The hazard a bulk endpoint actually has is the double submit: a slow response,
 * a second tap, and the day's production doubles with no error anywhere. Both
 * copies look real, and the first sign is a stock count weeks later.
 *
 * So a row is skipped when this date, shift, machine and product already carry a
 * batch. The product is part of that key on purpose — the paper for 5 August
 * shift A has twelve rows across ten machines, because ASB-4 and ASB-7 each ran a
 * second product after a mould change, and a key of date+shift+machine alone
 * would have silently refused those two real rows.
 */
class ShiftPageEntryService
{
    public function __construct(
        private readonly ShiftProductionEntryService $entries,
    ) {}

    /**
     * @param  array{production_date: string, shift_id: int, rows: list<array<string, mixed>>}  $page
     * @return array{
     *     recorded: list<array{row: int, machine_id: int, entry_id: int, batch_number: string}>,
     *     skipped: list<array{row: int, machine_id: int, reason: string, entry_id: int}>,
     *     failed: list<array{row: int, machine_id: int, reason: string}>,
     * }
     */
    public function ingest(array $page, ?int $userId): array
    {
        $recorded = [];
        $skipped = [];
        $failed = [];

        foreach (array_values($page['rows']) as $index => $row) {
            // One-based, matching the line a supervisor is looking at on the
            // page rather than an array offset they cannot see.
            $rowNumber = $index + 1;
            $machineId = (int) ($row['work_center_id'] ?? 0);

            $existing = $this->existingEntry($page, $row);

            if ($existing !== null) {
                $skipped[] = [
                    'row' => $rowNumber,
                    'machine_id' => $machineId,
                    'entry_id' => $existing->id,
                    'reason' => "already recorded as {$existing->batch_number}",
                ];

                continue;
            }

            try {
                $entry = DB::transaction(fn () => $this->recordRow($page, $row, $userId));

                $recorded[] = [
                    'row' => $rowNumber,
                    'machine_id' => $machineId,
                    'entry_id' => $entry->id,
                    'batch_number' => (string) $entry->batch_number,
                ];
            } catch (Throwable $e) {
                // The message reaches a supervisor, so it is the domain's own
                // wording — "this product has no Tally godown", not a stack
                // trace. Every service this calls throws ValidationException or
                // a domain exception with a sentence already written for the
                // floor.
                $failed[] = [
                    'row' => $rowNumber,
                    'machine_id' => $machineId,
                    'reason' => $this->readableReason($e),
                ];
            }
        }

        return ['recorded' => $recorded, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * Start and complete one row, exactly as the two dialogs would.
     *
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $row
     */
    private function recordRow(array $page, array $row, ?int $userId): ShiftProductionEntry
    {
        $entry = $this->entries->startBatch([
            'shift_id' => $page['shift_id'],
            'production_date' => $page['production_date'],
            'work_center_id' => $row['work_center_id'],
            'item_id' => $row['item_id'],
            'operator_id' => $row['operator_id'] ?? null,
            'production_standard_id' => $row['production_standard_id'] ?? null,
            'production_standard_packaging_id' => $row['production_standard_packaging_id'] ?? null,
            'active_cavities' => $row['active_cavities'] ?? null,
            'colour' => $row['colour'] ?? null,
            // The paper records a shift that already ran, so a material
            // shortage at "start" is not a thing that can be reported now.
            // Anything the readiness gate refuses is refused for this row and
            // reported; nothing is waved through on the page's behalf.
        ], $userId);

        return $this->entries->completeBatch($entry, [
            'quantity_produced' => $row['quantity_produced'],
            'quantity_scrap' => $row['quantity_scrap'] ?? null,
            'running_hours' => $row['running_hours'] ?? null,
            'nos_per_tray' => $row['nos_per_tray'] ?? null,
            'no_of_trays' => $row['no_of_trays'] ?? null,
            'nos_per_box' => $row['nos_per_box'] ?? null,
            'no_of_box' => $row['no_of_box'] ?? null,
            'scrap_reason_id' => $row['scrap_reason_id'] ?? null,
            'notes' => $row['notes'] ?? null,
            // Lumps ride as a scrap line, which is where the reconciliation and
            // the withheld-scrap figure both read them from. The paper has its
            // own LUMPS column and it is not the same population as rejection.
            'scraps' => $this->scrapLines($row),
            'material_consumptions' => $row['material_consumptions'] ?? [],
        ], $userId);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private function scrapLines(array $row): array
    {
        $lines = $row['scraps'] ?? [];
        $lumps = $row['lumps_kg'] ?? null;

        // Zero is not a lump line. The paper leaves the column blank on most
        // rows, and a 0.0000 kg scrap row would put an empty population into
        // the reconciliation and onto the voucher's withheld note.
        if ($lumps !== null && bccomp((string) $lumps, '0', 4) === 1) {
            $lines[] = ['type' => 'lumps', 'quantity_kg' => $lumps];
        }

        return $lines;
    }

    /**
     * Has this exact row already been recorded?
     *
     * Date + shift + machine + PRODUCT. The product belongs in the key because
     * the paper for 5 August shift A carries twelve rows across ten machines —
     * ASB-4 and ASB-7 each ran a second product after a mould change — and a
     * narrower key would have refused those as duplicates of themselves.
     *
     * Cancelled batches do not count: a row withdrawn as a mistake is a row that
     * still needs entering, and treating it as "already recorded" would leave a
     * supervisor unable to correct their own page.
     *
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $row
     */
    private function existingEntry(array $page, array $row): ?ShiftProductionEntry
    {
        return ShiftProductionEntry::query()
            ->whereDate('production_date', $page['production_date'])
            ->where('shift_id', $page['shift_id'])
            ->where('work_center_id', $row['work_center_id'] ?? 0)
            ->where('item_id', $row['item_id'] ?? 0)
            ->where('batch_status', '!=', BatchStatus::Cancelled->value)
            ->first();
    }

    private function readableReason(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return implode(' ', array_merge(...array_values($e->errors())));
        }

        return $e->getMessage();
    }
}
