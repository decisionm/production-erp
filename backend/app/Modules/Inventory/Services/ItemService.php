<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemGroup;
use App\Modules\Production\Services\RunMaterialSuggestionService;
use App\Support\Configuration\DependencyCheck;
use App\Support\Configuration\HardDeleteAuthority;
use App\Support\Configuration\ManagesConfigurationLifecycle;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ItemService
{
    use ManagesConfigurationLifecycle;

    protected function configurationLabel(): string
    {
        return 'item';
    }

    protected function configurationHardDeleteAuthorisation(): ?Closure
    {
        return HardDeleteAuthority::callback();
    }

    /**
     * EVERYTHING that may reference an item — the longest declaration in this
     * schema, and the one the whole contract rests on for the SKU master
     * (materials and finished products are one entity here). Read alongside
     * docs/engineering/CONFIGURATION-LIFECYCLE-WIRING.md.
     *
     * `items` is the most-referenced table in the database: FORTY-THREE
     * foreign-key columns across thirty-nine tables, plus three references no
     * foreign key expresses. Every one is listed, whatever its delete rule,
     * because the schema backstop only catches CASCADE:
     *
     *   CASCADE   (6 columns) — no database backstop; these checks are the
     *             ONLY thing between a delete and the destruction of a
     *             product's whole recipe (stock balances, dosings, packing
     *             mappings, resin allocations, pool balances, configurations).
     *   RESTRICT  — the database WOULD refuse, but as a QueryException inside
     *             the delete transaction: a 500, not the contract's
     *             422-with-counts. Declared so the refusal is the contract's.
     *   SET NULL  — NO backstop at all. `shift_production_entries
     *             .finished_item_id`, `production_standards.item_id`,
     *             `production_standard_packagings.item_id` and
     *             `mold_change_logs.changed_from_item_id` would each be
     *             silently blanked on a POSTED document by a "clear" delete.
     *
     * ARCHIVED CHILDREN ARE COUNTED wherever the child table itself soft
     * deletes (`boms`, `routings`, `spc_characteristics`,
     * `masterbatch_dosings`, `packing_material_mappings`,
     * `production_standards`, `production_configurations`). A soft-deleted
     * child is still a physical row: a CASCADE takes it, a RESTRICT still
     * blocks on it, and a withdrawn masterbatch dosing is exactly the row a
     * past shift's prefill has to stay explainable by.
     *
     * @return list<DependencyCheck>
     */
    protected function dependencyChecks(): array
    {
        return [
            // --- CASCADE children: no database backstop; this is the guard.
            DependencyCheck::table('stock_balances', 'item_id')
                ->label('stock balance')->cascadeSide(),
            DependencyCheck::table('masterbatch_dosings', ['product_item_id', 'masterbatch_item_id'], 'masterbatch_dosings')
                ->label('masterbatch dosing')->cascadeSide(),
            DependencyCheck::table('packing_material_mappings', 'item_id')
                ->label('packing material mapping')->cascadeSide(),
            DependencyCheck::table('batch_resin_allocations', 'item_id')
                ->label('batch resin allocation')->cascadeSide(),
            DependencyCheck::table('resin_pool_balances', 'item_id')
                ->label('resin pool balance')->cascadeSide(),
            DependencyCheck::table('production_configurations', 'item_id')
                ->label('production configuration')->cascadeSide(),

            // --- RESTRICT / NO ACTION children ---------------------------
            DependencyCheck::table('stock_movements', 'item_id')->label('stock movement'),
            DependencyCheck::table('batches', 'item_id')->label('batch'),
            DependencyCheck::table('material_lots', 'item_id')->label('material lot'),
            DependencyCheck::table('goods_receipt_note_lines', 'item_id')->label('goods receipt line'),
            DependencyCheck::table('purchase_requisition_lines', 'item_id')->label('purchase requisition line'),
            DependencyCheck::table('purchase_order_lines', 'item_id')->label('purchase order line'),
            DependencyCheck::table('supplier_bill_lines', 'item_id')->label('supplier bill line'),
            DependencyCheck::table('sales_order_lines', 'item_id')->label('sales order line'),
            // The two documents of the sales-order fulfilment flow: a hold
            // on finished goods, and the shortfall handed to the floor.
            // Transactional both, so they RESTRICT and are counted here
            // rather than cascading away with the master.
            DependencyCheck::table('stock_reservations', 'item_id')->label('stock reservation'),
            DependencyCheck::table('production_requests', 'item_id')->label('production request'),
            DependencyCheck::table('quotation_lines', 'item_id')->label('quotation line'),
            DependencyCheck::table('delivery_lines', 'item_id')->label('delivery line'),
            DependencyCheck::table('invoice_lines', 'item_id')->label('invoice line'),
            DependencyCheck::table('incoming_inspections', 'item_id')->label('incoming inspection'),
            DependencyCheck::table('non_conformance_reports', 'item_id')->label('non-conformance report'),
            DependencyCheck::table('work_orders', 'item_id')->label('work order'),
            DependencyCheck::table('work_order_materials', 'component_item_id', 'work_order_materials')
                ->label('work order material'),
            DependencyCheck::table('rework_orders', 'item_id')->label('rework order'),
            DependencyCheck::table('rework_order_materials', 'component_item_id', 'rework_order_materials')
                ->label('rework order material'),
            DependencyCheck::table('subcontract_orders', 'item_id')->label('subcontract order'),
            DependencyCheck::table('subcontract_order_materials', 'component_item_id', 'subcontract_order_materials')
                ->label('subcontract order material'),
            DependencyCheck::table('maintenance_work_order_parts', 'item_id')->label('maintenance work order part'),
            DependencyCheck::table('bom_lines', 'component_item_id', 'bom_lines')->label('BOM line'),
            DependencyCheck::table('serial_numbers', 'item_id')->label('serial number'),
            DependencyCheck::table('shift_material_consumptions', 'item_id')->label('shift material consumption'),
            DependencyCheck::table('shift_stock_counts', 'item_id')->label('shift stock count'),
            DependencyCheck::table('finished_cartons', 'item_id')->label('finished carton'),
            DependencyCheck::table('day_bin_movements', 'item_id')->label('day bin movement'),
            DependencyCheck::table('material_request_lines', 'item_id')->label('material request line'),
            DependencyCheck::table('store_issue_lines', 'item_id')->label('store issue line'),
            // A BASE PRODUCT WITH VARIANTS STILL POINTING AT IT
            // (DEC-20260821-001). The column RESTRICTs, so the database
            // would refuse — but as a QueryException inside the delete
            // transaction, i.e. a 500. Declared here so the refusal is the
            // contract's 422-with-counts, exactly as this docblock's
            // RESTRICT paragraph requires of every referencing column.
            // includeTrashed for the reason the ARCHIVED CHILDREN paragraph
            // above gives: `items` soft-deletes, an archived variant is
            // still a physical row, and a RESTRICT blocks on it just the
            // same. Coded `pack_variants` rather than the derived `items`,
            // because the 422's key must not read as "this item".
            DependencyCheck::table('items', 'variant_of_item_id', 'pack_variants')
                ->label('pack variant')->includeTrashed(),

            // Soft-deleting children: an archived one still blocks, because it
            // is still a physical row the database would act on.
            DependencyCheck::table('boms', 'item_id')->label('BOM')->includeTrashed(),
            DependencyCheck::table('routings', 'item_id')->label('routing')->includeTrashed(),
            DependencyCheck::table('spc_characteristics', 'item_id')
                ->label('SPC characteristic')->includeTrashed(),

            // --- SET NULL children: the delete would SUCCEED and blank these.
            DependencyCheck::table('production_standards', 'item_id')
                ->label('production standard')->includeTrashed(),
            // includeTrashed, like its sibling above and for a reason this
            // very commit created: the D-WIRING migration gave
            // production_standard_packagings a `deleted_at` for the FIRST
            // time, and countRows() silently starts excluding trashed rows
            // the moment a child table soft-deletes. So adding the lifecycle
            // column turned a complete check into an incomplete one. An Item
            // named ONLY by an ARCHIVED pack variant then read as clear,
            // hard-deleted, and — the column is SET NULL — left that variant
            // permanently unable to say which product it packed.
            DependencyCheck::table('production_standard_packagings', 'item_id')
                ->label('production standard packaging')->includeTrashed(),
            DependencyCheck::table('shift_production_entries', ['item_id', 'finished_item_id'], 'shift_production_entries')
                ->label('shift production entry'),
            DependencyCheck::table('mold_change_logs', ['changed_to_item_id', 'changed_from_item_id'], 'mold_change_logs')
                ->label('mould change log'),

            // --- no foreign key at all ---------------------------------

            // The factory-editable colour -> masterbatch map: a `json`
            // factory_settings row whose VALUES are item ids. Nothing in the
            // database connects the two, so a delete would leave the map
            // naming an id that no longer exists.
            //
            // EVERY row of that key is read, not only the active one
            // (RunMaterialSuggestionService reads is_active = true): a
            // superseded map is exactly what makes a past shift's prefill
            // explainable, and fail-closed means the wider set.
            DependencyCheck::callable(
                fn (Model $item): int => in_array(
                    (int) $item->getKey(),
                    RunMaterialSuggestionService::mappedMasterbatchItemIds(),
                    true,
                ) ? 1 : 0,
                'masterbatch_colour_map',
            )->label('masterbatch colour mapping'),

            // The scrap item is named in configuration BY SKU-OR-EXACT-NAME
            // (production.scrap.rejected_item_sku, ScrapItemResolver). Matched
            // on the raw columns rather than through resolve(), because
            // resolve() skips soft-deleted items and an ARCHIVED item being
            // hard-deleted is precisely the case that must still be caught.
            DependencyCheck::callable(function (Model $item): int {
                $configured = app(ScrapItemResolver::class)->configuredName();

                if ($configured === null) {
                    return 0;
                }

                return ((string) $item->getAttribute('sku') === $configured
                    || (string) $item->getAttribute('name') === $configured) ? 1 : 0;
            }, 'scrap_item_setting')->label('scrap posting setting'),

            // TALLY IDENTITY (DEC-20260817-002 §4): every voucher line names
            // this item, and the GUID is the mapping those postings were made
            // against. Archiving preserves it; deleting would throw it away.
            DependencyCheck::attribute('tally_stock_item_guid', 'tally_identity')
                ->label('Tally stock item identity'),
        ];
    }

    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Item::query()
            // The item list renders the Tally stock group per row. Two
            // columns, eager-loaded, so a 20-row page is two queries rather
            // than twenty-one — the catalogue is ~644 rows and this list is
            // the screen everybody opens first.
            ->with('group:id,name')
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function count(): int
    {
        return Item::query()->count();
    }

    public function create(array $data): Item
    {
        // Explicit here rather than relying on the DB column default: Eloquent's
        // create() doesn't re-fetch DB-applied defaults into the returned model.
        $attributes = [
            'reorder_level' => 0,
            'tracking_type' => 'none',
            'is_active' => true,
            ...$data,
        ];

        if (($attributes['variant_of_item_id'] ?? null) === null) {
            return Item::create($attributes);
        }

        /*
         * A NEW VARIANT LOSES THE RACE THE SAME WAY AN EDITED ONE DOES.
         * One request creates C -> A while another points A -> B: both
         * validations see A as an unlinked base, and if only the update
         * locked, the create lands afterwards and leaves C -> A -> B
         * (Codex, 5543e21). A brand-new row has no variants of its own, so
         * the target's own link is the whole invariant here.
         */
        return DB::transaction(function () use ($attributes): Item {
            $this->assertTargetIsStillABase((int) $attributes['variant_of_item_id']);

            return Item::create($attributes);
        });
    }

    /**
     * Re-read the base under its own lock and refuse if it stopped being one.
     */
    private function assertTargetIsStillABase(int $targetId): Item
    {
        $target = Item::query()->whereKey($targetId)->lockForUpdate()->first();

        if ($target === null) {
            throw ValidationException::withMessages([
                'variant_of_item_id' => 'That base product no longer exists.',
            ]);
        }

        if ($target->variant_of_item_id !== null) {
            throw ValidationException::withMessages([
                'variant_of_item_id' => "\"{$target->displayName()}\" became a pack variant while this was being edited. Point this item at the BASE product they both vary from.",
            ]);
        }

        return $target;
    }

    public function update(Item $item, array $data): Item
    {
        // A person typing a SKU of their own is the moment a name-seeded
        // placeholder stops being one (Phase 5, P5-02). Judged on CHANGE, not
        // presence: the Items form echoes the SKU back on every save, and an
        // edit to the colour must not silently declare the placeholder chosen.
        // Never client-writable — the request never validates the flag in,
        // and this is the only place it is cleared.
        if (array_key_exists('sku', $data) && (string) $data['sku'] !== (string) $item->sku) {
            $data['sku_provisional'] = false;
        }

        if (! array_key_exists('variant_of_item_id', $data) || $data['variant_of_item_id'] === null) {
            /*
             * A LABEL WITHOUT A LINK IN THE PAYLOAD still depends on one in
             * the database. ValidatesVariantLink reads the link off the
             * route-bound model, so a payload carrying only `variant_label`
             * was validated against the link as it stood a moment ago; if
             * another request unlinked the item in between, this write would
             * land a variant label on a base product — the state the front
             * door refuses (Codex, 2e3af77). Re-read under the row's lock and
             * refuse, rather than write a label about nothing.
             */
            if (($data['variant_label'] ?? null) !== null && ! array_key_exists('variant_of_item_id', $data)) {
                return DB::transaction(function () use ($item, $data): Item {
                    $locked = Item::query()->whereKey($item->getKey())->lockForUpdate()->first();

                    if ($locked === null || $locked->variant_of_item_id === null) {
                        throw ValidationException::withMessages([
                            'variant_label' => 'This item is no longer a pack variant, so it cannot carry a pack-variant label. Point it at its base product first.',
                        ]);
                    }

                    $item->update($data);

                    return $item;
                });
            }

            /*
             * CLEARING THE LINK CLEARS THE LABEL, at the write. The request
             * layer already does this (prepareVariantLabelForUnlink), but the
             * pair is one fact and the invariant belongs to whoever writes it
             * — a command, a sync or a future controller reaching the service
             * directly must not be able to leave a base product wearing a
             * variant label.
             */
            if (array_key_exists('variant_of_item_id', $data) && $data['variant_of_item_id'] === null) {
                $data['variant_label'] = null;
            }

            $item->update($data);

            return $item;
        }

        /*
         * THE ONE-LEVEL RULE, RE-CHECKED UNDER A LOCK.
         *
         * ValidatesVariantLink refuses the three shapes that build a chain,
         * but it reads before the write and holds nothing. Two editors
         * submitting A -> B and B -> A at the same moment each see a target
         * with no link and an item with no variants; both validations pass and
         * the pair lands as a cycle, which Item::variantRootId()'s "one level,
         * no walk" and the identity grouping both assume cannot exist.
         *
         * So the losing write re-reads the same two facts with the rows
         * locked, and refuses. Only a link WRITE takes this path — clearing a
         * link and every unrelated edit stay the plain update they were.
         */
        return DB::transaction(function () use ($item, $data): Item {
            $targetId = (int) $data['variant_of_item_id'];
            $itemId = (int) $item->getKey();

            /*
             * BOTH ROWS, IN ONE ORDERED STATEMENT. Locking the target first
             * and the item second would have the reciprocal writes this guard
             * exists for take A-then-B and B-then-A — a deadlock, which MySQL
             * resolves by rolling one side back with an error instead of the
             * refusal the loser is owed (Cursor, def53c9). Ascending id is an
             * order both sides agree on whichever way the edit points.
             */
            $rows = Item::query()
                ->whereIn('id', array_unique([$itemId, $targetId]))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $target = $rows->get($targetId);
            $locked = $rows->get($itemId);

            if ($target === null || $locked === null) {
                throw ValidationException::withMessages([
                    'variant_of_item_id' => 'That base product no longer exists.',
                ]);
            }

            if ($target->variant_of_item_id !== null) {
                throw ValidationException::withMessages([
                    'variant_of_item_id' => "\"{$target->displayName()}\" became a pack variant while this was being edited. Point this item at the BASE product they both vary from.",
                ]);
            }

            if ($locked->variants()->withTrashed()->exists()) {
                throw ValidationException::withMessages([
                    'variant_of_item_id' => "\"{$locked->displayName()}\" became the base product for another pack variant while this was being edited, so it cannot become a variant itself.",
                ]);
            }

            $item->update($data);

            return $item;
        });
    }

    /**
     * Upsert an item pulled from Tally, matched on its stable GUID (never on
     * name — Tally names get renamed and contain spaces/slashes). ERP-only
     * fields (tracking_type, reorder_level, description) are left untouched on
     * update, since Tally has no concept of them — see the split-ownership rule
     * in docs/archive/TALLY-SYNC-MASTER-PLAN.md §3. `parent` is the item's Tally stock-group
     * name; it's resolved to item_group_id if that group has been pulled.
     *
     * @param  array{guid: string, name: string, base_unit?: string|null, alter_id?: int|null, parent?: string|null}  $data
     * @return array{item: Item, created: bool}
     */
    public function upsertFromTally(array $data): array
    {
        $groupId = $this->resolveGroupId($data['parent'] ?? null);

        $item = Item::withTrashed()->where('tally_stock_item_guid', $data['guid'])->first();

        if ($item !== null) {
            $item->fill([
                'name' => $data['name'],
                'uom' => $data['base_unit'] ?? $item->uom,
                'tally_alter_id' => $data['alter_id'] ?? $item->tally_alter_id,
                'tally_synced_at' => now(),
                'item_group_id' => $groupId ?? $item->item_group_id,
                // Whose Tally this row came from. Only overwritten when the
                // pull actually stated it — an older agent that says nothing
                // must not erase provenance already recorded.
                'tally_company' => $data['company'] ?? $item->tally_company,
            ]);

            // A previously deleted item reappearing in Tally means it's live
            // again — restore rather than silently leaving it soft-deleted.
            if ($item->trashed()) {
                $item->restore();
            }

            $item->save();

            return ['item' => $item, 'created' => false];
        }

        $item = $this->create([
            'sku' => $this->uniqueSkuFrom($data['name']),
            // Seeded, not chosen: the SKU above is the Tally name because the
            // ERP needs a unique SKU to store the row and has nothing else.
            // Said on the row so the configuration review can list it until
            // a person sets a real one (the SKU format itself is the owner's).
            'sku_provisional' => true,
            'name' => $data['name'],
            'uom' => $data['base_unit'] ?? 'PCS',
            'tally_stock_item_guid' => $data['guid'],
            'tally_company' => $data['company'] ?? null,
            'tally_alter_id' => $data['alter_id'] ?? null,
            'tally_synced_at' => now(),
            'item_group_id' => $groupId,
        ]);

        return ['item' => $item, 'created' => true];
    }

    /** Match a Tally stock-group name to a pulled ItemGroup; null if not yet pulled. */
    private function resolveGroupId(?string $groupName): ?int
    {
        $groupName = $groupName !== null ? trim($groupName) : null;

        if ($groupName === null || $groupName === '' || $groupName === 'Primary') {
            return null;
        }

        return ItemGroup::where('name', $groupName)->value('id');
    }

    /**
     * Seed a new Tally-sourced item's SKU from its name (staff can rename it
     * afterwards — SKU stays ERP-editable, §3). SKU is unique, so suffix a
     * counter on the rare collision with an existing item.
     */
    private function uniqueSkuFrom(string $name): string
    {
        $base = trim($name) !== '' ? trim($name) : 'ITEM';
        $sku = $base;

        for ($i = 2; Item::withTrashed()->where('sku', $sku)->exists(); $i++) {
            $sku = "{$base}-{$i}";
        }

        return $sku;
    }
}
