<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Core\Models\AppSetting;
use App\Modules\Inventory\Http\Requests\ListWarehousesRequest;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Production\Services\FactoryDayBinService;
use App\Modules\Production\Services\FactoryWarehouseResolver;
use App\Support\Configuration\DependencyCheck;
use App\Support\Configuration\HardDeleteAuthority;
use App\Support\Configuration\ManagesConfigurationLifecycle;
use App\Support\Lists\ListSort;
use App\Support\Tally\HierarchyUpsert;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WarehouseService
{
    use ManagesConfigurationLifecycle;

    /**
     * Every `app_settings` key whose VALUE names a warehouse by id — the
     * warehouse references that no foreign key expresses and that therefore
     * have NO schema backstop of any kind.
     *
     * FIVE keys, not the three the 01-Aug migration listed: the packing
     * material store (FactoryWarehouseResolver::SETTING_PACKING_MATERIAL) and
     * the Production/WIP location (ProductionWipLocationResolver::SETTING_KEY,
     * Phase 7.5) were both added after that migration was written. This is
     * now the ONE list — the migration reads it from here — so the next key
     * is added in one place instead of two.
     *
     * @return list<string>
     */
    public static function settingKeysNamingWarehouses(): array
    {
        return [
            FactoryDayBinService::SETTING_KEY,
            FactoryWarehouseResolver::SETTING_FINISHED_GOODS,
            FactoryWarehouseResolver::SETTING_RAW_MATERIAL,
            FactoryWarehouseResolver::SETTING_PACKING_MATERIAL,
            ProductionWipLocationResolver::SETTING_KEY,
        ];
    }

    /**
     * The warehouse ids those settings currently name.
     *
     * Lifted verbatim (behaviour, not wording) out of the 01-Aug
     * `deactivate_demo_seeded_warehouses` migration, which computed exactly
     * this set to decide which rehearsal warehouse it must NOT retire. The
     * delete guard needs the same set for the opposite reason — a warehouse
     * a production setting still points at must not be destroyed — so it is
     * one query in one place rather than two implementations that can
     * disagree.
     *
     * STATIC and model-only, deliberately: the migration calls it at a point
     * where depending on the container being bootable would be a new risk it
     * did not have before.
     *
     * @return list<int>
     */
    public static function idsNamedBySettings(): array
    {
        return AppSetting::query()
            ->whereIn('key', self::settingKeysNamingWarehouses())
            ->pluck('value')
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();
    }

    protected function configurationLabel(): string
    {
        return 'warehouse';
    }

    protected function configurationHardDeleteAuthorisation(): ?Closure
    {
        return HardDeleteAuthority::callback();
    }

    /**
     * EVERYTHING that may reference a warehouse — the declaration the whole
     * contract rests on. Read alongside
     * docs/engineering/CONFIGURATION-LIFECYCLE-WIRING.md, which explains the
     * rules this list follows.
     *
     * Derived from the schema's own foreign keys (all fourteen of them,
     * whatever their delete rule) plus the references no foreign key
     * expresses. The schema backstop only catches CASCADE, so RESTRICT,
     * SET NULL, NO ACTION and non-FK references are each here because
     * nothing else would refuse them:
     *   - RESTRICT / NO ACTION would be a QueryException inside the delete
     *     transaction — a 500, not the contract's 422-with-counts;
     *   - SET NULL has no backstop AT ALL: the delete would succeed and
     *     quietly blank the column on a posted document;
     *   - the non-FK three at the bottom have no database involvement.
     *
     * NOTE on `warehouses.parent_id`: it is a plain nullable column with NO
     * database foreign key (the 23-Jul Tally migration says so explicitly),
     * so a nested godown's parent has no protection whatsoever.
     *
     * @return list<DependencyCheck>
     */
    protected function dependencyChecks(): array
    {
        return [
            // --- the one cascading child: no database backstop, this is the guard
            DependencyCheck::table('stock_balances', 'warehouse_id')
                ->label('stock balance')->cascadeSide(),

            // --- RESTRICT / NO ACTION children: the database would refuse
            //     with a QueryException; these turn that into the contract's
            //     refusal, with a count the screen can print.
            DependencyCheck::table('stock_movements', 'warehouse_id')->label('stock movement'),
            DependencyCheck::table('goods_receipt_notes', 'warehouse_id')->label('goods receipt note'),
            DependencyCheck::table('deliveries', 'warehouse_id')->label('delivery'),
            // A HOLD ON FINISHED GOODS names the warehouse it is held in,
            // and it must: re-pointing the finished-goods SETTING may never
            // silently move existing holds to a location the stock is not
            // in. Deleting that warehouse would do the same thing, harder.
            DependencyCheck::table('stock_reservations', 'warehouse_id')->label('stock reservation'),
            DependencyCheck::table('work_orders', 'warehouse_id')->label('work order'),
            DependencyCheck::table('rework_orders', 'warehouse_id')->label('rework order'),
            DependencyCheck::table('subcontract_orders', 'warehouse_id')->label('subcontract order'),
            DependencyCheck::table('maintenance_work_order_parts', 'warehouse_id')->label('maintenance work order part'),
            DependencyCheck::table('shift_production_entries', 'warehouse_id')->label('shift production entry'),
            DependencyCheck::table('shift_material_consumptions', 'warehouse_id')->label('shift material consumption'),
            DependencyCheck::table('store_issue_lines', ['from_warehouse_id', 'to_warehouse_id'], 'store_issue_lines')
                ->label('store issue line'),

            // --- SET NULL children: the delete would SUCCEED and blank these.
            DependencyCheck::table('serial_numbers', 'warehouse_id')->label('serial number'),
            DependencyCheck::table('material_bags', 'current_warehouse_id', 'material_bags')
                ->label('material bag'),

            // --- no foreign key at all ---------------------------------

            // A nested Tally godown points at its parent through a plain
            // nullable column. Deleting the parent orphans it silently.
            DependencyCheck::table('warehouses', 'parent_id', 'child_warehouses')
                ->label('child warehouse')->includeTrashed(),

            // The godown hierarchy is ALSO carried by name, for a child whose
            // parent had not been pulled yet when it arrived — HierarchyUpsert
            // re-links on the next pull, so the name is a live reference.
            DependencyCheck::callable(
                fn (Model $warehouse): int => Warehouse::withTrashed()
                    ->where('tally_parent_name', (string) $warehouse->name)
                    ->count(),
                'child_warehouses_by_tally_name',
            )->label('warehouse naming this one as its Tally parent'),

            // A production setting still names this warehouse. No FK, no
            // cascade, no error — the setting would simply point at nothing
            // and the floor would lose a location mid-shift.
            DependencyCheck::callable(
                fn (Model $warehouse): int => in_array((int) $warehouse->getKey(), self::idsNamedBySettings(), true) ? 1 : 0,
                'production_warehouse_setting',
            )->label('production warehouse setting'),

            // The Production/WIP location resolves BY CODE when no setting
            // names one (ProductionWipLocationResolver::CANONICAL_CODE), so
            // the code itself is a reference.
            DependencyCheck::callable(
                fn (Model $warehouse): int => mb_strtoupper(trim((string) $warehouse->code)) === ProductionWipLocationResolver::CANONICAL_CODE ? 1 : 0,
                'wip_location_by_code',
            )->label('Production/WIP location resolved by this code'),

            // TALLY IDENTITY (DEC-20260817-002 §4): a godown Tally vouches for
            // is Tally's, not ours to drop. Archiving preserves it; deleting
            // would throw away the mapping every past voucher was posted
            // against. This is also the half of DEC-20260817-001 that keeps
            // the Tally-linked row of a duplicate pair untouchable.
            DependencyCheck::attribute('tally_guid', 'tally_identity')
                ->label('Tally godown identity'),
        ];
    }

    /**
     * `orderBy('id')` IS THE TIEBREAKER, not an afterthought: `name` is not
     * unique (the code is), so two stores sharing a name tie, and a tie is not
     * a total order — walking page 1 then page 2 could serve one store twice
     * and skip another. Archived stores stay on the list; this is the admin
     * screen where they are reactivated from.
     */
    public function paginate(int $perPage = 20, ?string $search = null, ?string $sort = null): LengthAwarePaginator
    {
        $query = Warehouse::query()
            ->when($search !== null, function ($query) use ($search) {
                $like = "%{$search}%";
                $query->where(fn ($outer) => $outer->where('code', 'like', $like)->orWhere('name', 'like', $like));
            });

        // Name order unless asked otherwise (ListWarehousesRequest::SORTABLE).
        return ListSort::apply($query, $sort, ListWarehousesRequest::SORTABLE, 'name')
            ->paginate($perPage);
    }

    public function count(): int
    {
        return Warehouse::query()->count();
    }

    /**
     * One warehouse by id, or null when it does not exist (or was soft
     * deleted). The cross-module read other modules use instead of touching
     * Inventory's models — e.g. Production resolving the configured factory
     * day-bin warehouse, which must survive that warehouse being retired
     * rather than blow up mid-shift.
     */
    public function find(int $id): ?Warehouse
    {
        return Warehouse::query()->find($id);
    }

    /**
     * Upsert Tally godowns as warehouses. Godowns can nest, so the same
     * self-referencing hierarchy resolution is used; a warehouse `code` (unique,
     * required) is generated from the name for new ones — staff can rename later.
     *
     * @param  array<int, array{guid: string, name: string, parent?: string|null}>  $godowns
     * @return array{created: int, updated: int, total: int}
     */
    /**
     * @param  string|null  $company  the Tally company these godowns came from.
     *                                Recorded on every row: without it a
     *                                foreign company's godown is
     *                                indistinguishable from a real one, which
     *                                is how six of them came to be live here.
     */
    public function syncGodownsFromTally(array $godowns, ?string $company = null): array
    {
        return HierarchyUpsert::sync(
            Warehouse::class,
            $godowns,
            // The ERP's own columns, seeded once and never reset by a re-pull:
            // a person may rename the code.
            fn (array $row): array => [
                'code' => $this->uniqueCodeFrom($row['name']),
                'is_active' => true,
            ],
            // WHICH TALLY COMPANY THIS GODOWN BELONGS TO — written on every
            // pull, not only the first. It is Tally's fact, not the ERP's, and
            // it is what tells this company's godown apart from a row left
            // behind by another company. Recording it on create alone left
            // every already-pulled godown with no company, and the sole-godown
            // lookup with nothing to discriminate by.
            array_filter(['tally_company' => $company], fn ($value) => $value !== null),
        );
    }

    private function uniqueCodeFrom(string $name): string
    {
        $base = Str::upper(Str::slug($name, '-'));
        $base = $base !== '' ? $base : 'GDN';
        $code = $base;

        for ($i = 2; Warehouse::withTrashed()->where('code', $code)->exists(); $i++) {
            $code = "{$base}-{$i}";
        }

        return $code;
    }

    public function create(array $data): Warehouse
    {
        // Explicit here rather than relying on the DB column default: Eloquent's
        // create() doesn't re-fetch DB-applied defaults into the returned model.
        return Warehouse::create([
            'is_active' => true,
            ...$data,
        ]);
    }

    public function update(Warehouse $warehouse, array $data): Warehouse
    {
        $warehouse->update($data);

        return $warehouse;
    }
}
