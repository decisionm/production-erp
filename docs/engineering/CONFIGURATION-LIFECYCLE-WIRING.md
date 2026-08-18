# Wiring a master onto the Configuration Lifecycle Contract

The mechanism (`backend/app/Support/Configuration/*`, Phase 7.6) already
enforces DEC-20260817-002. Wiring a master means **declaring what references
it** and **exposing the seam** — never re-implementing a rule. This page is
the one place the pattern is written down; Item and Warehouse are the worked
examples (`ItemService`, `WarehouseService`, `WarehouseController`,
`routes/api.php` inventory group).

## 1 · The five files

| File | What you add |
|---|---|
| `Models/<Master>.php` | `use RecordsConfigurationAudit;` (Tier‑1 masters already have it) |
| `Services/<Master>Service.php` | `use ManagesConfigurationLifecycle;` + `configurationLabel()`, `dependencyChecks()`, `configurationHardDeleteAuthorisation()` |
| `Http/Controllers/<Master>Controller.php` | `use ServesConfigurationLifecycle;` + `show`, `destroy`, `archive`, `activate` |
| `Http/Resources/<Master>Resource.php` | a `can` key |
| `routes/api.php` | two POSTs, then `apiResource(... 'show', 'destroy')` |

## 2 · The route shape

```
GET    <module>/<resource>                 index    — can.delete is null (ask)
GET    <module>/<resource>/{id}            show     — can.delete authoritative
POST   <module>/<resource>                 store
PUT    <module>/<resource>/{id}            update
DELETE <module>/<resource>/{id}            hard delete
POST   <module>/<resource>/{id}/archive    reason optional, reversible
POST   <module>/<resource>/{id}/activate   reason optional, reversible
```

Register the two POSTs **before** the `apiResource` so `archive`/`activate`
cannot be read as an id. The body of both is the shared
`App\Support\Configuration\Http\ConfigurationReasonRequest`.

Nothing is added to the route's middleware. The group's own `module:<key>`
is the whole of create/edit/activate/deactivate RBAC; the hard-delete tier is
enforced inside the lifecycle (§4), which is what lets a module-manage user
archive while being refused delete.

## 3 · `can` — where the counts are paid

`abilities(resolveDelete: true)` costs 8–30 COUNTs (≈40 for Item). So:

* **`show` and the three actions** call `withAbilities()`, which stamps the
  authoritative block onto the model. This is what the confirm dialog fetches.
* **`index`** stamps nothing; the Resource falls back to
  `abilities(resolveDelete: false)`, where `delete` is `null` — *undetermined,
  ask* — or `false` for a user with no hard-delete tier (a decision, not an
  unknown; counting would not change it).

The Resource reads `$model->can ?? app(<Master>Service::class)->abilities(...)`
— the `PurchaseOrderResource` idiom.

`edit`, `activate` and `archive` are separate questions, never each other's
opposite: a mould that is `under_repair` is neither active nor retired, so
both are offered.

## 4 · The hard-delete tier

`configuration-delete.manage`, a `PermissionService::MODULES` catalog entry.
It **must** be a catalog entry: `RoleService` intersects every grant with
`PermissionService::allPermissionNames()`, so a hand-created permission is
stripped from every role on the next save through the Roles screen and then
fails silently. `machine-master` and `carton-trace` are the same precedent.

`PermissionSeeder` hands the Administrator role every catalog permission, and
the owner logs in as Administrator — so Administrator receives it and nobody
else does unless a human grants it. No new role was invented.

Every service returns the same callback:

```php
protected function configurationHardDeleteAuthorisation(): ?Closure
{
    return HardDeleteAuthority::callback();
}
```

Returning `null` (the default) means *nobody may hard-delete this master* —
fail-closed, and a legitimate answer for a master nobody should ever delete.

## 5 · Declaring dependencies — the dangerous part

The schema backstop (`SchemaCascades` + `DependencyReport::cascadeGaps()`)
reads the database for you, so a forgotten **CASCADE** child is a refusal, not
a disaster. **It filters on `DELETE_RULE = 'CASCADE'` and nothing else.**
Everything below has no backstop or the wrong one:

| Delete rule | What happens if you forget it |
|---|---|
| `CASCADE` | refused by the backstop, naming the table — safe |
| `RESTRICT` / `NO ACTION` | the database refuses, but as a `QueryException` inside the delete transaction: a **500**, not the contract's 422-with-counts |
| `SET NULL` | **no backstop at all** — the delete succeeds and silently blanks a column on a posted document |
| non-FK (a settings key naming an id, a string match, a Tally identity) | **no backstop at all** — nothing notices |

So: **declare every foreign key that points at the table, whatever its delete
rule, plus every non-FK reference.** One `DependencyCheck::table()` per child
table, several columns of the same table OR-ed in one check. Do not collapse
tables into one `::callable` — the 422 loses which reference blocked, and that
is exactly the data the modal renders.

### Archived children

`DependencyCheck::countRows()` adds `whereNull('deleted_at')` unless told
otherwise. A soft-deleted child is still a physical row — a CASCADE takes it,
a RESTRICT still blocks on it — so:

> **Every declared child table that has a `deleted_at` column gets
> `->includeTrashed()`, or `->cascadeSide()` if it cascades, whatever its
> delete rule.**

Child tables in this schema that soft-delete: `boms`, `routings`,
`spc_characteristics`, `masterbatch_dosings`, `packing_material_mappings`,
`production_standards`, `production_configurations`, `molds`, `assets`,
`measuring_instruments`, `maintenance_schedules`, and the masters themselves.
The cascade-gap detector only enforces trashed-counting for *cascading*
columns, so on a RESTRICT or SET NULL child this hole is invisible.

### Finding the list

Do not read it off migrations — read it off the schema. In a throwaway test:

```php
foreach (DB::select("select name from sqlite_master where type='table'") as $t) {
    foreach (DB::select('pragma foreign_key_list("'.$t->name.'")') as $fk) {
        // $fk->table (parent), $t->name.$fk->from (child column), $fk->on_delete
    }
}
```

The full FK graph for the Tier‑1 masters as of 17-Aug-2026 is in the WS‑1
handover; re-derive rather than trust a copy once the schema moves.

### The three kinds of non-FK reference, with the examples that exist

* **A settings key naming an id.** Five `app_settings` keys name a warehouse
  (`WarehouseService::settingKeysNamingWarehouses()` — day bin, finished
  goods, raw material, packing material, Production/WIP). One `factory_settings`
  key names item ids (`masterbatch_colour_map`). Read **every** row of the key,
  not just the active one: fail-closed, and a superseded map is what makes a
  past shift's prefill explainable.
* **A string match.** `ProductionWipLocationResolver` resolves the WIP
  location by warehouse **code** when no setting names one.
  `ScrapItemResolver` resolves the scrap item by **SKU or exact name** from
  `production.scrap.rejected_item_sku`. `HierarchyUpsert` re-links a godown to
  its parent by **name**. Compare raw columns, not through the resolver — a
  resolver that skips soft-deleted rows would miss the archived record that is
  being deleted.
* **A Tally identity.** `DependencyCheck::attribute('tally_guid')` /
  `('tally_stock_item_guid')`. A master Tally vouches for is not ours to drop:
  every past voucher was posted against that mapping (DEC-20260817-002 §4).

Where past use genuinely **cannot** be proven, say so with
`DependencyCheck::unprovable('<code>')` — it blocks exactly like a positive
count. Never interpolate a number to make a check pass.

## 6 · Uniqueness — do not touch it

An archived record **retains and reserves** its business code
(DEC-20260817-002 §2). `unique:items,sku` and `unique:warehouses,code` already
count soft-deleted rows, and `uniqueSkuFrom()` / `uniqueCodeFrom()` already use
`withTrashed()`. That is correct and stays. Only a genuine hard delete frees a
code, because nothing in history ever referred to it.

## 7 · What wiring must never do

* No `forceDelete()`, no cascading parent delete, no disabling FK checks, and
  never a destructive cleanup to make a check pass. The mechanism owns this.
* No Tally read or write. Archiving causes **no** Tally mutation — this repo
  has no model observers at all, which is the evidence rather than a promise.
* Transactions, ledgers and posted documents stay append-only
  (`MaterialLotCostVersionTest`, the T16 pin).
* FC-06: no rate, amount or supplier identity on a configuration resource.
* The FG / FG-STORE / RM / RM-STORE warehouse **rows** are gated by
  DEC-20260817-001. Wiring the Warehouse entity is in scope; touching those
  rows is not — and the Tally-identity check plus the reference counts refuse
  them as the general rule, so no test needs to name them.

## 8 · The test set per entity

create · edit · archive-while-referenced · excluded from NEW selection while
history still renders · reactivate · duplicate code refused **including
against an archived row** · delete-referenced REFUSED with counts **and the
cascade children asserted to survive** · delete-unused succeeds and frees the
code · the audit trail recorded · the tier enforced (module-manage without
`configuration-delete.manage` gets 403 on DELETE; archive still works).
