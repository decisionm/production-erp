<?php

namespace App\Modules\Production\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\Enums\ConfigurationStatus;
use App\Modules\Production\Models\Mold;
use App\Modules\Production\Models\ProductionConfiguration;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Support\Facades\DB;

/**
 * Bulk import of machine-product configurations, with a dry run.
 *
 * Two rules, both non-negotiable:
 *
 *  1. Every imported row lands as DRAFT, whatever the source says. The
 *     factory's master workbook marks all 11 candidate mappings "To
 *     Confirm"; importing one as approved would put a guessed standard in
 *     front of a supervisor with nothing to distinguish it from a confirmed
 *     one.
 *  2. An approved configuration is never overwritten. It is reported as a
 *     conflict and skipped — silently replacing the standard a live shift
 *     is measured against is the worst thing this importer could do.
 *
 * Rows arrive as structured arrays (the SPA parses CSV, a script parses the
 * workbook). Deliberately not an .xlsx parser: that would mean a new server
 * dependency for a format that converts to CSV in one click, and the
 * validation — not the parsing — is where the value is.
 */
class ConfigurationImportService
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{
     *     dry_run: bool, summary: array{create: int, conflict: int, rejected: int},
     *     rows: list<array{row: int, action: string, reason: ?string, resolved: array<string, mixed>}>,
     * }
     */
    public function import(array $rows, bool $dryRun, ?int $createdBy): array
    {
        $results = [];
        $summary = ['create' => 0, 'conflict' => 0, 'rejected' => 0];

        foreach ($rows as $index => $row) {
            $assessed = $this->assess($row, $index + 1);
            $results[] = $assessed;
            $summary[$assessed['action']] = ($summary[$assessed['action']] ?? 0) + 1;
        }

        if (! $dryRun) {
            DB::transaction(function () use (&$results, $createdBy) {
                foreach ($results as &$result) {
                    if ($result['action'] !== 'create') {
                        continue;
                    }

                    $configuration = ProductionConfiguration::create([
                        ...$result['resolved'],
                        // Draft regardless of what the source row claims.
                        'status' => ConfigurationStatus::Draft->value,
                        'created_by' => $createdBy,
                    ]);

                    $result['created_id'] = $configuration->id;
                }
            });
        }

        return ['dry_run' => $dryRun, 'summary' => $summary, 'rows' => $results];
    }

    /**
     * Resolve one row's references and decide what would happen to it.
     * Never writes.
     *
     * @param  array<string, mixed>  $row
     * @return array{row: int, action: string, reason: ?string, resolved: array<string, mixed>, source_row: array<string, mixed>}
     */
    private function assess(array $row, int $number): array
    {
        $reject = fn (string $reason) => [
            'row' => $number, 'action' => 'rejected', 'reason' => $reason,
            'resolved' => [], 'source_row' => $row,
        ];

        $machine = $this->findMachine($row['machine'] ?? $row['machine_code'] ?? null);
        if ($machine === null) {
            return $reject('No machine matches "'.($row['machine'] ?? $row['machine_code'] ?? '').'".');
        }

        $item = $this->findItem($row['item'] ?? $row['product'] ?? null);
        if ($item === null) {
            // The single most common workbook case: the report label ("100
            // RC", "500 RA") is not a Tally item name. Say so precisely —
            // this list IS the product-identity question for Vincent.
            return $reject('No product matches "'.($row['item'] ?? $row['product'] ?? '').'" — needs mapping to a Tally item name.');
        }

        $mold = $this->findMold($row['mold'] ?? $row['mould'] ?? null);
        $colour = $this->blankToNull($row['colour'] ?? null);

        $resolved = [
            'work_center_id' => $machine->id,
            'item_id' => $item->id,
            'mold_id' => $mold?->id,
            'colour' => $colour,
            'unit_weight_grams' => $this->numeric($row['unit_weight_grams'] ?? null),
            'default_cycle_time' => $this->numeric($row['cycle_time'] ?? $row['default_cycle_time'] ?? null),
            'cycle_time_min' => $this->numeric($row['cycle_time_min'] ?? null),
            'cycle_time_max' => $this->numeric($row['cycle_time_max'] ?? null),
            'default_cavities' => $this->intOrNull($row['cavities'] ?? $row['default_cavities'] ?? null),
            'source' => 'workbook',
            'source_reference' => $this->blankToNull($row['mapping_id'] ?? null),
            'confirmation_status' => $this->blankToNull($row['confirmation_status'] ?? null),
            'notes' => $this->blankToNull($row['notes'] ?? null),
        ];

        $existingApproved = ProductionConfiguration::query()
            ->where('work_center_id', $machine->id)
            ->where('item_id', $item->id)
            ->where('status', ConfigurationStatus::Approved->value)
            ->when($mold === null, fn ($q) => $q->whereNull('mold_id'), fn ($q) => $q->where('mold_id', $mold->id))
            ->when($colour === null, fn ($q) => $q->whereNull('colour'), fn ($q) => $q->where('colour', $colour))
            ->first();

        if ($existingApproved !== null) {
            return [
                'row' => $number,
                'action' => 'conflict',
                'reason' => "Configuration #{$existingApproved->id} is already APPROVED for this machine, product, mould and colour. Import will not overwrite it.",
                'resolved' => $resolved,
                'source_row' => $row,
            ];
        }

        return ['row' => $number, 'action' => 'create', 'reason' => null, 'resolved' => $resolved, 'source_row' => $row];
    }

    private function findMachine(?string $reference): ?WorkCenter
    {
        if (blank($reference)) {
            return null;
        }

        return WorkCenter::query()
            ->where('code', $reference)
            ->orWhere('name', $reference)
            ->first();
    }

    private function findItem(?string $reference): ?Item
    {
        if (blank($reference)) {
            return null;
        }

        // Exact match only — deliberately. A fuzzy match on "500 RA" could
        // pick either of several 500 ml ambers with different weights, and
        // a wrongly-matched product produces confidently wrong kg for
        // every shift it runs.
        return Item::query()
            ->where('sku', $reference)
            ->orWhere('name', $reference)
            ->first();
    }

    private function findMold(?string $reference): ?Mold
    {
        if (blank($reference)) {
            return null;
        }

        return Mold::query()->where('code', $reference)->orWhere('name', $reference)->first();
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return blank($value) ? null : (string) $value;
    }

    private function numeric(mixed $value): ?string
    {
        return is_numeric($value) ? (string) $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
