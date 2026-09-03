<?php

namespace App\Console\Commands;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\ProductionWipLocationResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * DEC-20260903-002 — remove the 23-Jul-2026 demo stock standing on the
 * Production/WIP row, BY EXACT RECORD, dry run first.
 *
 * What the live spot check (03-Sep-2026) found on the WIP warehouse row:
 * nineteen movements written 23-Jul-2026 04:44 UTC by the demo seeder —
 * references "Issue to …" and "QC release", no purpose stamp, seeded labels,
 * caps, preforms, "PET Resin (Virgin Grade)" and HDPE. None of it is factory
 * material, and it has been showing on the floor's figures ever since.
 *
 * HOW IT IS SAFE TO DELETE FROM AN APPEND-ONLY LEDGER. The ledger is
 * append-only for the factory's transactions (StockMovement refuses update
 * and delete through the model). These rows are not transactions: nothing
 * bought them, nothing issued them, nothing consumed them, and the owner has
 * ruled they are removed rather than reversed (a reversal would leave a demo
 * receipt AND a demo issue in the books for ever). The delete goes through
 * the query builder, as production:reset-test-data does, and ONLY for ids a
 * person read off the dry run and typed back.
 *
 * FAIL-CLOSED, WITH COUNTS (the ConfigurationLifecycle discipline):
 *   - an id that is not in the candidate set refuses the whole run;
 *   - an id another record references (goods_receipt_note_lines) refuses;
 *   - a transfer leg whose partner leg is not also named refuses — deleting
 *     one leg of a pair conjures stock in the other location;
 *   - the touched balances are recomputed from the surviving movements as
 *     the EXACT signed sum (negative stays negative — inventory:check-ledger
 *     is the invariant, and it does not clamp), inside the same transaction.
 *
 * The candidate rule is DATE + WAREHOUSE + SHAPE, never an item name:
 * Production/WIP (resolved, never a hard-coded id), created on --on (the UTC
 * day, default 23-Jul-2026), no purpose or purpose unknown, reference
 * "Issue to …" or "QC release". A stamped, real row on the same day is never
 * a candidate. The dry run prints the ids; the write takes them back.
 */
class RemoveWipDemoRows extends Command
{
    protected $signature = 'inventory:remove-wip-demo-rows
        {--on=2026-07-23 : The UTC calendar day the demo rows were written (stock_movements.created_at)}
        {--ids= : Comma-separated stock_movements ids to remove, read from the dry run}
        {--write : Actually delete (default is a dry run that lists the candidates and changes nothing)}';

    protected $description = 'DEC-20260903-002: list (dry run) or remove (--write --ids=) the demo-seeded stock movements standing on the Production/WIP row, by exact id';

    /** Tables that point at a stock movement. A referenced row is never removed. */
    private const REFERENCES = [
        ['table' => 'goods_receipt_note_lines', 'column' => 'stock_movement_id', 'what' => 'is the ledger row of a goods-receipt line'],
    ];

    public function __construct(private readonly ProductionWipLocationResolver $wip)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $on = (string) $this->option('on');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) !== 1) {
            $this->error("--on must be a calendar day in YYYY-MM-DD form (got '{$on}').");

            return self::FAILURE;
        }

        $wip = $this->wip->warehouse();

        if ($wip === null) {
            $this->error('No Production/WIP location could be resolved (no setting, no warehouse coded WIP). Nothing read.');

            return self::FAILURE;
        }

        $candidates = $this->candidates((int) $wip->id, $on);

        $this->info(sprintf('Demo rows on Production/WIP ("%s", #%d) created on %s — plan', $wip->name, $wip->id, $on));
        $this->table(['count', 'value'], [
            ['candidates', $candidates->count()],
            ['quantity rows (receipts + transfers in)', $candidates->whereIn('type', ['receipt', 'transfer_in'])->count()],
            ['quantity rows (issues + transfers out)', $candidates->whereIn('type', ['issue', 'transfer_out'])->count()],
        ]);
        $this->line('candidates '.$candidates->count());

        if ($candidates->isNotEmpty()) {
            $this->table(
                ['id', 'item', 'type', 'quantity', 'reference', 'created_at'],
                $candidates->map(fn (object $row) => [
                    '#'.$row->id,
                    $this->itemLabel((int) $row->item_id),
                    $row->type,
                    bcadd((string) $row->quantity, '0', 4),
                    (string) $row->reference,
                    (string) $row->created_at,
                ])->all(),
            );
        }

        if (! $this->option('write')) {
            $this->newLine();
            $this->line('DRY RUN — nothing removed. To remove, re-run with --write --ids=<the ids above, comma-separated>.');

            return self::SUCCESS;
        }

        $ids = $this->parseIds((string) $this->option('ids'));

        if ($ids === []) {
            $this->error('A write needs --ids=<comma-separated ids read from the dry run>. Nothing removed.');

            return self::FAILURE;
        }

        $refusals = $this->refusals($ids, $candidates);

        if ($refusals !== []) {
            foreach ($refusals as $line) {
                $this->error('  REFUSED — '.$line);
            }
            $this->newLine();
            $this->error(sprintf('Write refused: %d problem(s) above. Nothing removed.', count($refusals)));

            return self::FAILURE;
        }

        $rows = $candidates->whereIn('id', $ids)->values();

        DB::transaction(function () use ($rows, $ids): void {
            DB::table('stock_movements')->whereIn('id', $ids)->delete();
            $this->recomputeBalances($rows);
        });

        $this->newLine();
        $this->info(sprintf('REMOVED %d demo row(s) from Production/WIP; %d stock balance(s) recomputed from the surviving movements.', count($ids), $rows->unique(fn (object $r) => "{$r->item_id}@{$r->warehouse_id}")->count()));

        return self::SUCCESS;
    }

    /** @return Collection<int, object> */
    private function candidates(int $wipWarehouseId, string $on): Collection
    {
        return DB::table('stock_movements')
            ->where('warehouse_id', $wipWarehouseId)
            ->whereDate('created_at', $on)
            ->where(fn ($q) => $q->whereNull('purpose')->orWhere('purpose', 'unknown'))
            ->where(fn ($q) => $q->where('reference', 'like', 'Issue to %')->orWhere('reference', 'like', 'QC release%'))
            ->orderBy('id')
            ->get(['id', 'item_id', 'warehouse_id', 'type', 'quantity', 'reference', 'transfer_group', 'created_at']);
    }

    /** @return list<int> */
    private function parseIds(string $raw): array
    {
        $ids = [];

        foreach (preg_split('/[\s,]+/', trim($raw)) ?: [] as $piece) {
            if ($piece === '') {
                continue;
            }
            if (preg_match('/^\d+$/', $piece) !== 1) {
                return [];
            }
            $ids[] = (int) $piece;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Every reason the write must not happen, all of them, so one run tells
     * the person everything rather than one thing per attempt.
     *
     * @param  list<int>  $ids
     * @param  Collection<int, object>  $candidates
     * @return list<string>
     */
    private function refusals(array $ids, Collection $candidates): array
    {
        $refusals = [];
        $candidateIds = $candidates->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach ($ids as $id) {
            if (! in_array($id, $candidateIds, true)) {
                $refusals[] = "not a candidate: #{$id} is not a demo row on Production/WIP for that day (already removed, real, or elsewhere)";
            }
        }

        $schema = DB::getSchemaBuilder();

        foreach (self::REFERENCES as $ref) {
            if (! $schema->hasTable($ref['table']) || ! $schema->hasColumn($ref['table'], $ref['column'])) {
                continue;
            }
            $referenced = DB::table($ref['table'])->whereIn($ref['column'], $ids)->pluck($ref['column']);
            foreach ($referenced->unique() as $id) {
                $refusals[] = "referenced: #{$id} {$ref['what']} ({$ref['table']}.{$ref['column']})";
            }
        }

        $named = $candidates->whereIn('id', $ids);

        foreach ($named as $row) {
            if ($row->transfer_group === null) {
                continue;
            }
            $partners = DB::table('stock_movements')
                ->where('transfer_group', $row->transfer_group)
                ->where('id', '!=', $row->id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id);

            foreach ($partners as $partnerId) {
                if (! in_array($partnerId, $ids, true)) {
                    $refusals[] = "transfer partner not named: #{$row->id} shares transfer group {$row->transfer_group} with #{$partnerId}, which is not in --ids; removing one leg alone would conjure stock";
                }
            }
        }

        return $refusals;
    }

    /**
     * The EXACT signed sum of what survives, per touched (item, warehouse) —
     * the same rule inventory:check-ledger signs by. A pair with nothing
     * left keeps its balance row at zero rather than losing it.
     *
     * @param  Collection<int, object>  $removed
     */
    private function recomputeBalances(Collection $removed): void
    {
        $pairs = $removed
            ->map(fn (object $row) => ['item_id' => (int) $row->item_id, 'warehouse_id' => (int) $row->warehouse_id])
            ->unique(fn (array $pair) => "{$pair['item_id']}@{$pair['warehouse_id']}")
            ->values();

        foreach ($pairs as $pair) {
            $sum = '0.0000';
            $rows = DB::table('stock_movements')
                ->where('item_id', $pair['item_id'])
                ->where('warehouse_id', $pair['warehouse_id'])
                ->get(['type', 'quantity']);

            foreach ($rows as $row) {
                $sign = match ((string) $row->type) {
                    'receipt', 'transfer_in' => '1',
                    'issue', 'transfer_out' => '-1',
                    default => '0',
                };
                $sum = bcadd($sum, bcmul((string) $row->quantity, $sign, 4), 4);
            }

            StockBalance::query()->updateOrCreate(
                ['item_id' => $pair['item_id'], 'warehouse_id' => $pair['warehouse_id']],
                ['quantity' => $sum],
            );
        }
    }

    private function itemLabel(int $itemId): string
    {
        $item = Item::withTrashed()->find($itemId);

        return $item === null ? "#{$itemId}" : "#{$itemId} {$item->sku} {$item->name}";
    }
}
