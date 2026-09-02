# Procurement Controls Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the procurement screens and server enforce the owner's 02-Sep-2026 rules: a requisition cannot be approved by its requester and can be withdrawn by them; purchase pickers offer raw and packing material by default and refuse finished goods; vendors carry classifications and a default view; the vendor page names the state; the supplier bill shows a GRN line's rejection; a goods receipt never exists without a purchase order.

**Architecture:** Every rule is enforced on the server in the module's Service or FormRequest and refused with a `DomainException` (rendered 422 by `backend/bootstrap/app.php`), then reflected in the React pages through pure helper functions that carry their own vitest. No stock effect, no Tally effect, no new permission.

**Tech Stack:** Laravel 12 (PHP 8.3, MySQL, PHPUnit via `php artisan test`), React + TypeScript (Vite, Ant Design, TanStack Query, vitest), Pint.

**Spec:** `docs/factory/workflows/01-DASHBOARD-AND-PROCUREMENT.md` §2–§6 and `docs/factory/CURRENT-DECISIONS.md` entries DEC-20260902-015, -023, -025, -026, -034. Programme: `docs/superpowers/plans/2026-09-02-workflow-build-programme.md`.

## Global Constraints

- Money and stock quantities are `decimal` columns, never float (CLAUDE.md).
- Controllers stay thin: validation in FormRequests, logic in Services, cross-module reads through the other module's Service (CLAUDE.md).
- Every refusal is a `DomainException` with a plain message; no explanatory paragraph in the UI (25-Aug rule: labels and numbers, not sentences).
- FC-06: no purchase rate or supplier detail reaches a floor login; nothing here adds one.
- A line's category comes from `items.category` (DEC-20260827-001); nothing infers it from a name.
- Branch: `claude/procurement-controls` off `decisionm/main`. Stage explicit paths, never `git add -A` (a parallel session may share the tree).
- Before the PR: `cd backend && ./vendor/bin/pint --dirty && php artisan test`; `cd frontend && npm run typecheck && npm run test && npm run build`.

---

### Task 1: A requisition cannot be approved or rejected by the person who raised it (DEC-20260902-025)

**Files:**
- Create: `backend/app/Modules/Procurement/Exceptions/SelfDecisionException.php`
- Modify: `backend/app/Modules/Procurement/Services/PurchaseRequisitionService.php:166-179` (`approve`, `reject`) and the private `decide` at `:192-201`
- Test: `backend/tests/Feature/Procurement/RequisitionApproverTest.php`

**Interfaces:**
- Consumes: `PurchaseRequisitionService::create(array $data, ?int $requestedBy)` writes `requested_by`; `approve(PurchaseRequisition, ?int $approvedBy)`; `reject(PurchaseRequisition, ?int $rejectedBy)`; `App\Exceptions\DomainException` (marker interface rendered as 422).
- Produces: `SelfDecisionException::forRequisition(int $id): self`; `approve`/`reject` throw it when the decider id equals `requested_by`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * DEC-20260902-025: any procurement-write holder may approve a requisition
 * EXCEPT the person who raised it; self-approval is refused with a clear
 * message; no Administrator bypass. Rejection is an approver action too.
 */
class RequisitionApproverTest extends TestCase
{
    use RefreshDatabase;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->item = Item::create(['sku' => 'ITEM_RM', 'name' => 'Item RM', 'uom' => 'Kgs', 'category' => ItemCategory::RawMaterial]);
    }

    private function actAs(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['procurement.view', 'procurement.manage'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'sanctum'));
        }
        Sanctum::actingAs($user);

        return $user;
    }

    private function raise(): int
    {
        return $this->postJson('/api/v1/procurement/purchase-requisitions', [
            'lines' => [['item_id' => $this->item->id, 'quantity' => '10']],
        ])->assertCreated()->json('data.id');
    }

    public function test_the_requester_cannot_approve_their_own_requisition(): void
    {
        $this->actAs();
        $id = $this->raise();

        $this->postJson("/api/v1/procurement/purchase-requisitions/{$id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', 'A requisition cannot be approved by the person who raised it.');

        $this->assertDatabaseHas('purchase_requisitions', ['id' => $id, 'status' => 'draft', 'approved_by' => null]);
    }

    public function test_the_requester_cannot_reject_their_own_requisition(): void
    {
        $this->actAs();
        $id = $this->raise();

        $this->postJson("/api/v1/procurement/purchase-requisitions/{$id}/reject")->assertStatus(422);
        $this->assertDatabaseHas('purchase_requisitions', ['id' => $id, 'status' => 'draft']);
    }

    public function test_a_different_procurement_user_approves_and_is_recorded(): void
    {
        $this->actAs();
        $id = $this->raise();

        $approver = $this->actAs();
        $this->postJson("/api/v1/procurement/purchase-requisitions/{$id}/approve")->assertOk();

        $this->assertDatabaseHas('purchase_requisitions', ['id' => $id, 'status' => 'approved', 'approved_by' => $approver->id]);
    }

    public function test_an_administrator_who_raised_it_is_not_exempt(): void
    {
        $admin = $this->actAs();
        $admin->assignRole(\Spatie\Permission\Models\Role::findOrCreate('Administrator', 'sanctum'));
        $id = $this->raise();

        $this->postJson("/api/v1/procurement/purchase-requisitions/{$id}/approve")->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter RequisitionApproverTest`
Expected: FAIL — the self-approval cases return 200 today.

- [ ] **Step 3: Add the exception**

```php
<?php

namespace App\Modules\Procurement\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

/**
 * DEC-20260902-025: the person who raised a requisition may not decide it.
 * One message for approve and reject; the refusal names the rule, not the user.
 */
class SelfDecisionException extends RuntimeException implements DomainException
{
    public static function forRequisition(int $requisitionId, string $verb): self
    {
        return new self("A requisition cannot be {$verb} by the person who raised it.");
    }
}
```

- [ ] **Step 4: Compare decider with requester inside the locked decision**

In `PurchaseRequisitionService`, change `approve` and `reject` to pass the decider id through, and add the comparison in `decide` after the lock (so a race cannot slip a self-decision past it):

```php
public function approve(PurchaseRequisition $requisition, ?int $approvedBy = null): PurchaseRequisition
{
    return $this->decide($requisition, PurchaseRequisitionStatus::Approved, [
        'approved_by' => $approvedBy,
        'approved_at' => now(),
    ], $approvedBy, 'approved');
}

public function reject(PurchaseRequisition $requisition, ?int $rejectedBy = null): PurchaseRequisition
{
    return $this->decide($requisition, PurchaseRequisitionStatus::Rejected, [
        'rejected_by' => $rejectedBy,
        'rejected_at' => now(),
    ], $rejectedBy, 'rejected');
}

private function decide(PurchaseRequisition $requisition, PurchaseRequisitionStatus $target, array $stamps, ?int $decidedBy, string $verb): PurchaseRequisition
{
    return DB::transaction(function () use ($requisition, $target, $stamps, $decidedBy, $verb) {
        $locked = PurchaseRequisition::query()->lockForUpdate()->findOrFail($requisition->id);
        $this->guardStatus($locked, PurchaseRequisitionStatus::Draft, $target);

        // DEC-20260902-025: no Administrator exemption, so this is a plain
        // id comparison and nothing consults roles.
        if ($decidedBy !== null && $locked->requested_by !== null && (int) $locked->requested_by === (int) $decidedBy) {
            throw SelfDecisionException::forRequisition($locked->id, $verb);
        }

        $locked->forceFill(['status' => $target, ...$stamps])->save();

        return $this->decorate($locked);
    });
}
```

Add `use App\Modules\Procurement\Exceptions\SelfDecisionException;` at the top.

- [ ] **Step 5: Run the test file and the procurement suite**

Run: `cd backend && php artisan test --filter Procurement`
Expected: PASS, including the four new tests. If an existing test approves a requisition with the same user that raised it, change that test to approve with a second user, because the old behaviour is the bug this decision removes.

- [ ] **Step 6: Pint and commit**

```bash
cd backend && ./vendor/bin/pint --dirty
git add backend/app/Modules/Procurement/Exceptions/SelfDecisionException.php backend/app/Modules/Procurement/Services/PurchaseRequisitionService.php backend/tests/Feature/Procurement/RequisitionApproverTest.php
git commit -m "A requisition cannot be approved or rejected by the person who raised it (DEC-20260902-025)"
```

---

### Task 2: The requester may withdraw their own draft requisition (DEC-20260902-025)

**Files:**
- Create: `backend/database/migrations/2026_09_03_100000_add_withdrawn_stamps_to_purchase_requisitions.php`
- Modify: `backend/app/Modules/Procurement/Models/Enums/PurchaseRequisitionStatus.php:7-9` (add `Withdrawn`)
- Modify: `backend/app/Modules/Procurement/Services/PurchaseRequisitionService.php` (add `withdraw`)
- Modify: `backend/app/Modules/Procurement/Http/Controllers/PurchaseRequisitionController.php` (add `withdraw`)
- Modify: `backend/routes/api.php:568-569` (add the route beside approve/reject)
- Modify: `backend/app/Modules/Procurement/Http/Resources/PurchaseRequisitionResource.php:18-30` (add `requested_by_id`, `withdrawn_by`, `withdrawn_at`)
- Modify: `frontend/src/features/procurement/types.ts` (PurchaseRequisition: add `requested_by_id`, status union gains `'withdrawn'`), `frontend/src/features/procurement/api.ts` (add `withdrawPurchaseRequisition`), `frontend/src/features/procurement/pages/PurchaseRequisitionsPage.tsx:43-60` (status options and decision line) and the row actions
- Test: `backend/tests/Feature/Procurement/RequisitionWithdrawTest.php`

**Interfaces:**
- Consumes: `SelfDecisionException` (Task 1), `InvalidStatusTransitionException::make(string $entity, string $from, string $to)`.
- Produces: `PurchaseRequisitionService::withdraw(PurchaseRequisition, int $userId): PurchaseRequisition`; `POST /api/v1/procurement/purchase-requisitions/{id}/withdraw`; status value `withdrawn`; resource keys `requested_by_id`, `withdrawn_by`, `withdrawn_at`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** DEC-20260902-025: a requester withdraws their own draft; nobody else does, and nothing but a draft can be withdrawn. */
class RequisitionWithdrawTest extends TestCase
{
    use RefreshDatabase;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->item = Item::create(['sku' => 'ITEM_RM', 'name' => 'Item RM', 'uom' => 'Kgs', 'category' => ItemCategory::RawMaterial]);
    }

    private function actAs(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['procurement.view', 'procurement.manage'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'sanctum'));
        }
        Sanctum::actingAs($user);

        return $user;
    }

    private function raise(): int
    {
        return $this->postJson('/api/v1/procurement/purchase-requisitions', [
            'lines' => [['item_id' => $this->item->id, 'quantity' => '10']],
        ])->assertCreated()->json('data.id');
    }

    public function test_the_requester_withdraws_their_own_draft(): void
    {
        $me = $this->actAs();
        $id = $this->raise();

        $this->postJson("/api/v1/procurement/purchase-requisitions/{$id}/withdraw")
            ->assertOk()
            ->assertJsonPath('data.status', 'withdrawn')
            ->assertJsonPath('data.requested_by_id', $me->id);

        $this->assertDatabaseHas('purchase_requisitions', ['id' => $id, 'status' => 'withdrawn', 'withdrawn_by' => $me->id]);
    }

    public function test_someone_else_cannot_withdraw_it(): void
    {
        $this->actAs();
        $id = $this->raise();
        $this->actAs();

        $this->postJson("/api/v1/procurement/purchase-requisitions/{$id}/withdraw")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only the person who raised a requisition can withdraw it.');
    }

    public function test_an_approved_requisition_cannot_be_withdrawn(): void
    {
        $this->actAs();
        $id = $this->raise();
        $this->actAs();
        $this->postJson("/api/v1/procurement/purchase-requisitions/{$id}/approve")->assertOk();

        Sanctum::actingAs(User::find($this->getJson("/api/v1/procurement/purchase-requisitions?per_page=1")->json('data.0.requested_by_id')));
        $this->postJson("/api/v1/procurement/purchase-requisitions/{$id}/withdraw")->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter RequisitionWithdrawTest`
Expected: FAIL — 404 on the route.

- [ ] **Step 3: Migration and enum**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DEC-20260902-025: a requester withdraws their own requisition through a separate action, which is not a rejection. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->foreignId('withdrawn_by')->nullable()->after('rejected_at')->constrained('users')->nullOnDelete();
            $table->timestamp('withdrawn_at')->nullable()->after('withdrawn_by');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('withdrawn_by');
            $table->dropColumn('withdrawn_at');
        });
    }
};
```

In `PurchaseRequisitionStatus` add `case Withdrawn = 'withdrawn';` after `Rejected`. If the enum has a `label()` method, add `'withdrawn' => 'Withdrawn'`.

- [ ] **Step 4: Service, controller, route, resource**

Service (below `reject`):

```php
/**
 * DEC-20260902-025: the requester's own exit. Not a decision, so it is not
 * `decide()`: no approver stamps, and the comparison runs the other way.
 */
public function withdraw(PurchaseRequisition $requisition, int $userId): PurchaseRequisition
{
    return DB::transaction(function () use ($requisition, $userId) {
        $locked = PurchaseRequisition::query()->lockForUpdate()->findOrFail($requisition->id);
        $this->guardStatus($locked, PurchaseRequisitionStatus::Draft, PurchaseRequisitionStatus::Withdrawn);

        if ((int) $locked->requested_by !== $userId) {
            throw new \App\Modules\Procurement\Exceptions\NotTheRequesterException('Only the person who raised a requisition can withdraw it.');
        }

        $locked->forceFill([
            'status' => PurchaseRequisitionStatus::Withdrawn,
            'withdrawn_by' => $userId,
            'withdrawn_at' => now(),
        ])->save();

        return $this->decorate($locked);
    });
}
```

Create `backend/app/Modules/Procurement/Exceptions/NotTheRequesterException.php`:

```php
<?php

namespace App\Modules\Procurement\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

class NotTheRequesterException extends RuntimeException implements DomainException {}
```

Controller:

```php
public function withdraw(Request $request, PurchaseRequisition $purchaseRequisition): PurchaseRequisitionResource
{
    return PurchaseRequisitionResource::make(
        $this->requisitions->withdraw($purchaseRequisition, (int) $request->user()->id),
    );
}
```

Route, beside `reject` at `backend/routes/api.php:569`:

```php
Route::post('purchase-requisitions/{purchase_requisition}/withdraw', [PurchaseRequisitionController::class, 'withdraw']);
```

Resource, beside `requested_by` at `:21`:

```php
'requested_by_id' => $this->requested_by,
'withdrawn_by' => $this->whenLoaded('withdrawnBy', fn () => $this->withdrawnBy?->name),
'withdrawn_at' => $this->withdrawn_at?->toIso8601String(),
```

Add a `withdrawnBy(): BelongsTo` relation on `PurchaseRequisition` (copy the `rejectedBy` one) and `'withdrawnBy'` to the service's `WITH` constant at `:24`. Cast `withdrawn_at` as `datetime` beside `rejected_at`.

- [ ] **Step 5: Run the backend suite**

Run: `cd backend && php artisan test --filter Requisition`
Expected: PASS.

- [ ] **Step 6: Frontend — type, api, button**

`types.ts`: in `PurchaseRequisition` add `requested_by_id: number | null; withdrawn_by?: string | null; withdrawn_at?: string | null;` and widen the status union with `'withdrawn'`.

`api.ts`:

```ts
export async function withdrawPurchaseRequisition(id: number): Promise<PurchaseRequisition> {
    const { data } = await api.post<{ data: PurchaseRequisition }>(`/procurement/purchase-requisitions/${id}/withdraw`);
    return data.data;
}
```

`PurchaseRequisitionsPage.tsx`: add `{ value: 'withdrawn', label: 'Withdrawn' }` to `STATUS_OPTIONS` at `:43-44`; in the decision-line helper at `:53-60` add the branch `if (row.status === 'withdrawn') return row.withdrawn_by ? \`Withdrawn by ${row.withdrawn_by}\` : 'Withdrawn';`. Read the current user from the auth store (`frontend/src/features/auth/store.ts`; if the selector differs from `useAuthStore((s) => s.user)`, use the one that file exports) and render, on a draft row where `row.requested_by_id === me?.id`, a `Withdraw` button that calls `withdrawPurchaseRequisition(row.id)` through a `useMutation` whose `onError` goes through the page's existing `showApiError`, and whose `onSuccess` invalidates `['procurement', 'purchase-requisitions']`. Hide Approve and Reject on a row the current user raised, since the server refuses them.

- [ ] **Step 7: Typecheck, test, commit**

```bash
cd frontend && npm run typecheck && npm run test
cd ../backend && ./vendor/bin/pint --dirty
git add backend/database/migrations/2026_09_03_100000_add_withdrawn_stamps_to_purchase_requisitions.php backend/app/Modules/Procurement backend/routes/api.php backend/tests/Feature/Procurement/RequisitionWithdrawTest.php frontend/src/features/procurement/types.ts frontend/src/features/procurement/api.ts frontend/src/features/procurement/pages/PurchaseRequisitionsPage.tsx
git commit -m "A requester withdraws their own draft requisition (DEC-20260902-025)"
```

---

### Task 3: Purchase pickers show raw and packing material by default, the rest behind a deliberate choice (DEC-20260902-023, frontend)

**Files:**
- Create: `frontend/src/features/procurement/purchasePicker.ts`
- Create: `frontend/src/features/procurement/purchasePicker.test.ts`
- Modify: `frontend/src/features/procurement/pages/PurchaseRequisitionsPage.tsx:117-118` and `:391-393`
- Modify: `frontend/src/features/procurement/pages/PurchaseOrdersPage.tsx:78` and where `itemOptions` feeds the line `Select`

**Interfaces:**
- Consumes: `Item` (`frontend/src/features/inventory/types.ts`: `category?: ItemCategoryValue | null`, `is_active`), `itemLabel(item)` from `@/lib/itemLabel`, `purchasableItemOptions` (`purchaseOrders.ts:1082`, which already drops archived items).
- Produces: `purchasePickerItems(items, showAdditional): PurchasePickerItem[]` and `isUnclassified(item): boolean`.

- [ ] **Step 1: Write the failing test**

```ts
import { describe, expect, it } from 'vitest';
import { isUnclassified, purchasePickerItems } from './purchasePicker';
import type { Item } from '@/features/inventory/types';

const item = (id: number, category: Item['category'], extra: Partial<Item> = {}): Item =>
    ({ id, sku: `S${id}`, name: `Item ${id}`, uom: 'Kgs', is_active: true, is_production_input: true, category, ...extra }) as Item;

describe('purchasePickerItems (DEC-20260902-023)', () => {
    const items = [
        item(1, 'raw_material'),
        item(2, 'packing_material'),
        item(3, 'other'),
        item(4, null),
        item(5, 'finished_good'),
        item(6, 'raw_material', { is_active: false }),
    ];

    it('offers raw and packing material by default and nothing else', () => {
        expect(purchasePickerItems(items, false).map((i) => i.id)).toEqual([1, 2]);
    });

    it('adds other and unclassified items behind the deliberate choice, flagging the unclassified', () => {
        const shown = purchasePickerItems(items, true);
        expect(shown.map((i) => i.id)).toEqual([1, 2, 3, 4]);
        expect(shown.find((i) => i.id === 4)?.warning).toBe('Unclassified — reason required');
        expect(shown.find((i) => i.id === 3)?.warning).toBeUndefined();
    });

    it('never offers a finished good, whatever the choice', () => {
        expect(purchasePickerItems(items, true).some((i) => i.id === 5)).toBe(false);
    });

    it('never offers an archived item', () => {
        expect(purchasePickerItems(items, true).some((i) => i.id === 6)).toBe(false);
    });

    it('isUnclassified is true only for a null category', () => {
        expect(isUnclassified(item(4, null))).toBe(true);
        expect(isUnclassified(item(3, 'other'))).toBe(false);
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx vitest run src/features/procurement/purchasePicker.test.ts`
Expected: FAIL — module not found.

- [ ] **Step 3: Write the helper**

```ts
import type { Item } from '@/features/inventory/types';

/**
 * DEC-20260902-023. The picker offers Raw Material and Packing Material by
 * default. Behind "Show additional purchasable items" it also offers Other
 * (consumables, spares, tooling) and unclassified items, the latter flagged
 * because the document will demand a reason. A finished good never appears.
 * The category is `items.category` (DEC-20260827-001); nothing is inferred
 * from a name. The server enforces the same rule (StorePurchaseRequisitionRequest,
 * StorePurchaseOrderRequest); this is the courtesy half.
 */
export interface PurchasePickerItem {
    id: number;
    item: Item;
    warning?: string;
}

export const DEFAULT_PURCHASE_CATEGORIES = ['raw_material', 'packing_material'] as const;

export function isUnclassified(item: Pick<Item, 'category'>): boolean {
    return item.category === null || item.category === undefined;
}

export function purchasePickerItems(items: readonly Item[] | undefined | null, showAdditional: boolean): PurchasePickerItem[] {
    const out: PurchasePickerItem[] = [];
    for (const item of items ?? []) {
        if (!item.is_active) continue;
        if (item.category === 'finished_good') continue;
        const isDefault = (DEFAULT_PURCHASE_CATEGORIES as readonly string[]).includes(item.category ?? '');
        if (!isDefault && !showAdditional) continue;
        out.push(isUnclassified(item) ? { id: item.id, item, warning: 'Unclassified — reason required' } : { id: item.id, item });
    }
    return out;
}
```

- [ ] **Step 4: Run the test**

Run: `cd frontend && npx vitest run src/features/procurement/purchasePicker.test.ts`
Expected: PASS (5 tests).

- [ ] **Step 5: Wire both pages**

In `PurchaseRequisitionsPage.tsx` replace `:118` with:

```ts
const [showAdditional, setShowAdditional] = useState(false);
const pickerItems = purchasePickerItems(items?.data, showAdditional);
const itemOptions = pickerItems.map(({ item, warning }) => ({
    value: item.id,
    label: warning ? `${itemLabel(item)} · ${warning}` : itemLabel(item),
}));
```

and above the lines table in the create drawer add an Ant `Checkbox` labelled `Show additional purchasable items` bound to `showAdditional`. Where a chosen line's item `isUnclassified`, render an `Input` bound to `lines.{index}.unclassified_reason` with placeholder `Reason` (required; Task 4 makes the server demand it). Do the same in `PurchaseOrdersPage.tsx` at `:78` and its line picker, composing with the existing `purchasableItemOptions` only for its `keepIds` behaviour on amend: pass `purchasePickerItems(items?.data, showAdditional).map((p) => p.item)` into `purchasableItemOptions(...)` so archived-kept lines still render.

- [ ] **Step 6: Typecheck, test, commit**

```bash
cd frontend && npm run typecheck && npm run test
git add frontend/src/features/procurement/purchasePicker.ts frontend/src/features/procurement/purchasePicker.test.ts frontend/src/features/procurement/pages/PurchaseRequisitionsPage.tsx frontend/src/features/procurement/pages/PurchaseOrdersPage.tsx
git commit -m "Purchase pickers: raw and packing by default, other items behind a deliberate choice (DEC-20260902-023)"
```

---

### Task 4: The server refuses a finished good and demands a reason for an unclassified item (DEC-20260902-023, backend)

**Files:**
- Create: `backend/database/migrations/2026_09_03_100100_add_unclassified_reason_to_purchase_lines.php`
- Create: `backend/app/Modules/Procurement/Support/PurchaseLineEligibility.php`
- Modify: `backend/app/Modules/Procurement/Http/Requests/StorePurchaseRequisitionRequest.php:17-27`
- Modify: `backend/app/Modules/Procurement/Http/Requests/StorePurchaseOrderRequest.php:76-79` (ERP-entered orders only; the `source === 'tally'` mirror path records what Tally already holds and is untouched)
- Modify: `backend/app/Modules/Procurement/Models/PurchaseRequisitionLine.php:10` (Fillable gains `unclassified_reason`), the PO line model likewise, `PurchaseRequisitionService::create` `:145-149`, and the PO create path where lines are written
- Modify: `backend/app/Modules/Procurement/Http/Resources/PurchaseRequisitionLineResource.php:44-47` and `PurchaseOrderLineResource.php` (add `unclassified_reason`)
- Test: `backend/tests/Feature/Procurement/PurchaseLineEligibilityTest.php`

**Interfaces:**
- Consumes: `Item::category` cast to `ItemCategory` (`Item.php:262`), `ItemCategory::FinishedGood`, `App\Modules\Inventory\Services\ItemService` for the cross-module read.
- Produces: `PurchaseLineEligibility::validate(array $lines, callable $fail): void` used by both requests; column `unclassified_reason` (nullable string 255) on `purchase_requisition_lines` and `purchase_order_lines`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Item;
use App\Modules\Procurement\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * DEC-20260902-023 on the wire: a finished good is refused on a requisition
 * and on an ERP-entered purchase order; an unclassified item is accepted only
 * with a reason; raw, packing and Other items are accepted as before.
 * Resolves the half of InactiveMasterGuardTest that pinned "category is not
 * consulted" while Q59 was open — Q59(a) is now answered.
 */
class PurchaseLineEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vendor = Vendor::create(['code' => 'V-ALPHA', 'name' => 'Vendor Alpha']);
        $user = User::factory()->create(['is_active' => true]);
        foreach (['procurement.view', 'procurement.manage'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'sanctum'));
        }
        Sanctum::actingAs($user);
    }

    private function item(string $sku, ?ItemCategory $category): Item
    {
        return Item::create(['sku' => $sku, 'name' => $sku, 'uom' => 'Nos', 'category' => $category]);
    }

    public function test_a_finished_good_is_refused_on_a_requisition(): void
    {
        $bottle = $this->item('BOTTLE', ItemCategory::FinishedGood);

        $this->postJson('/api/v1/procurement/purchase-requisitions', ['lines' => [['item_id' => $bottle->id, 'quantity' => '5']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.item_id']);
    }

    public function test_an_unclassified_item_needs_a_reason(): void
    {
        $spray = $this->item('SPRAY', null);

        $this->postJson('/api/v1/procurement/purchase-requisitions', ['lines' => [['item_id' => $spray->id, 'quantity' => '2']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.unclassified_reason']);

        $this->postJson('/api/v1/procurement/purchase-requisitions', ['lines' => [['item_id' => $spray->id, 'quantity' => '2', 'unclassified_reason' => 'Mould release, used on M3']]])
            ->assertCreated()
            ->assertJsonPath('data.lines.0.unclassified_reason', 'Mould release, used on M3');
    }

    public function test_other_raw_and_packing_are_accepted_without_a_reason(): void
    {
        foreach ([ItemCategory::Other, ItemCategory::RawMaterial, ItemCategory::PackingMaterial] as $category) {
            $item = $this->item('I-'.$category->value, $category);
            $this->postJson('/api/v1/procurement/purchase-requisitions', ['lines' => [['item_id' => $item->id, 'quantity' => '1']]])->assertCreated();
        }
    }

    public function test_a_finished_good_is_refused_on_an_erp_entered_purchase_order(): void
    {
        $bottle = $this->item('BOTTLE', ItemCategory::FinishedGood);

        $this->postJson('/api/v1/procurement/purchase-orders', [
            'vendor_id' => $this->vendor->id,
            'lines' => [['item_id' => $bottle->id, 'quantity' => '5']],
        ])->assertStatus(422)->assertJsonValidationErrors(['lines.0.item_id']);
    }
}
```

If `StorePurchaseOrderRequest` requires more header fields (read `:20-75`), add them to the PO payload with synthetic values.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter PurchaseLineEligibilityTest`
Expected: FAIL — the finished good is created.

- [ ] **Step 3: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DEC-20260902-023: an unclassified item on a purchase document carries the reason an authorised person gave. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requisition_lines', fn (Blueprint $t) => $t->string('unclassified_reason', 255)->nullable()->after('notes'));
        Schema::table('purchase_order_lines', fn (Blueprint $t) => $t->string('unclassified_reason', 255)->nullable()->after('notes'));
    }

    public function down(): void
    {
        Schema::table('purchase_requisition_lines', fn (Blueprint $t) => $t->dropColumn('unclassified_reason'));
        Schema::table('purchase_order_lines', fn (Blueprint $t) => $t->dropColumn('unclassified_reason'));
    }
};
```

If `purchase_order_lines` has no `notes` column, drop the `->after('notes')`.

- [ ] **Step 4: The shared eligibility check**

```php
<?php

namespace App\Modules\Procurement\Support;

use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Item;

/**
 * DEC-20260902-023, the server half. Called from the two purchase FormRequests'
 * `withValidator` hooks so a client that posts JSON meets the same refusal the
 * picker shows. Reads the category from the item master only.
 */
final class PurchaseLineEligibility
{
    /**
     * @param  array<int, array{item_id?: int|string, unclassified_reason?: string|null}>  $lines
     * @param  callable(string $key, string $message): void  $fail
     */
    public static function validate(array $lines, callable $fail): void
    {
        $ids = array_values(array_filter(array_map(fn ($l) => isset($l['item_id']) ? (int) $l['item_id'] : null, $lines)));
        $items = Item::query()->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($lines as $index => $line) {
            $item = isset($line['item_id']) ? $items->get((int) $line['item_id']) : null;
            if ($item === null) {
                continue; // exists:items,id already reports it
            }
            if ($item->category === ItemCategory::FinishedGood) {
                $fail("lines.{$index}.item_id", 'A finished good is not purchased.');
                continue;
            }
            if ($item->category === null && trim((string) ($line['unclassified_reason'] ?? '')) === '') {
                $fail("lines.{$index}.unclassified_reason", 'An unclassified item needs a reason.');
            }
        }
    }
}
```

- [ ] **Step 5: Hook both requests**

In `StorePurchaseRequisitionRequest` add the rule `'lines.*.unclassified_reason' => ['nullable', 'string', 'max:255'],` and:

```php
public function withValidator(\Illuminate\Validation\Validator $validator): void
{
    $validator->after(function ($v) {
        \App\Modules\Procurement\Support\PurchaseLineEligibility::validate(
            (array) $this->input('lines', []),
            fn (string $key, string $message) => $v->errors()->add($key, $message),
        );
    });
}
```

In `StorePurchaseOrderRequest` the same, guarded with `if ($this->input('source') === 'tally') { return; }` at the top of the closure. Add `unclassified_reason` to both line models' Fillable and copy it through in `PurchaseRequisitionService::create` (`'unclassified_reason' => $line['unclassified_reason'] ?? null,`) and the PO line write. Add `'unclassified_reason' => $this->unclassified_reason,` to both line resources.

- [ ] **Step 6: Run the procurement suite and reconcile the old negative controls**

Run: `cd backend && php artisan test --filter Procurement`
Expected: `PurchaseLineEligibilityTest` PASS. `InactiveMasterGuardTest` has tests that pin a finished good being ACCEPTED because Q59 was open; Q59(a) is now DEC-20260902-023, so change those assertions to `assertStatus(422)` and update the file's docblock to cite the decision. Keep its archived-item tests unchanged.

- [ ] **Step 7: Pint and commit**

```bash
cd backend && ./vendor/bin/pint --dirty
git add backend/database/migrations/2026_09_03_100100_add_unclassified_reason_to_purchase_lines.php backend/app/Modules/Procurement backend/tests/Feature/Procurement/PurchaseLineEligibilityTest.php backend/tests/Feature/Procurement/InactiveMasterGuardTest.php
git commit -m "Purchase documents refuse a finished good and demand a reason for an unclassified item (DEC-20260902-023)"
```

---

### Task 5: Vendor classification on the server (DEC-20260902-026)

**Files:**
- Create: `backend/database/migrations/2026_09_03_100200_create_vendor_classifications_table.php`
- Create: `backend/app/Modules/Procurement/Models/Enums/VendorClassification.php`
- Create: `backend/app/Modules/Procurement/Models/VendorClassificationRow.php`
- Modify: `backend/app/Modules/Procurement/Models/Vendor.php:14-20` (relation), `Http/Requests/StoreVendorRequest.php:21-30` and `UpdateVendorRequest.php` (rule), `Http/Resources/VendorResource.php:19-50` (output), `Services/VendorService.php:38-46` (filter + write), `Http/Controllers/VendorController.php:39-45` (query params)
- Test: `backend/tests/Feature/Procurement/VendorClassificationTest.php`

**Interfaces:**
- Consumes: `VendorService::paginate(int $perPage, ?string $search)`; `VendorController::index` reads `q`.
- Produces: enum values `resin | packaging | consumables_spares_tooling | service | other`; `Vendor::classifications()` HasMany; `VendorService::paginate(int $perPage, ?string $search, ?array $classifications = null, bool $unclassified = false)`; request field `classifications: string[]`; resource key `classifications: string[]`; query params `classification[]=resin&classification[]=packaging` and `unclassified=1`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Procurement\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** DEC-20260902-026: one or more of five classifications, set by a person; a filter, never a block. */
class VendorClassificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create(['is_active' => true]);
        foreach (['procurement.view', 'procurement.manage'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'sanctum'));
        }
        Sanctum::actingAs($user);
    }

    public function test_a_vendor_takes_one_or_more_classifications(): void
    {
        $id = $this->postJson('/api/v1/procurement/vendors', ['name' => 'Relpet Traders', 'classifications' => ['resin', 'packaging']])
            ->assertCreated()->assertJsonPath('data.classifications', ['packaging', 'resin'])->json('data.id');

        $this->putJson("/api/v1/procurement/vendors/{$id}", ['name' => 'Relpet Traders', 'classifications' => ['service']])
            ->assertOk()->assertJsonPath('data.classifications', ['service']);
    }

    public function test_an_unknown_classification_is_refused(): void
    {
        $this->postJson('/api/v1/procurement/vendors', ['name' => 'X', 'classifications' => ['tooling']])
            ->assertStatus(422)->assertJsonValidationErrors(['classifications.0']);
    }

    public function test_the_list_filters_by_classification_and_by_unclassified(): void
    {
        $resin = Vendor::create(['code' => 'V-R', 'name' => 'Resin Co']);
        $resin->classifications()->create(['classification' => 'resin']);
        $service = Vendor::create(['code' => 'V-S', 'name' => 'Service Co']);
        $service->classifications()->create(['classification' => 'service']);
        Vendor::create(['code' => 'V-U', 'name' => 'Unclassified Co']);

        $this->getJson('/api/v1/procurement/vendors?classification[]=resin&classification[]=packaging')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'V-R');
        $this->getJson('/api/v1/procurement/vendors?unclassified=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'V-U');
        $this->getJson('/api/v1/procurement/vendors')->assertOk()->assertJsonCount(3, 'data');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter VendorClassificationTest`
Expected: FAIL — `classifications` ignored, filter ignored.

- [ ] **Step 3: Migration, enum, row model, relation**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DEC-20260902-026: a vendor carries ONE OR MORE of five classifications, set
 * by a person. Multi-valued, so a child table and not a column. The Tally
 * ledger group may only propose; nothing here writes a row automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_classifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('classification', 40);
            $table->timestamps();
            $table->unique(['vendor_id', 'classification']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_classifications');
    }
};
```

```php
<?php

namespace App\Modules\Procurement\Models\Enums;

/** DEC-20260902-026: the five vendor classifications, in the owner's words. */
enum VendorClassification: string
{
    case Resin = 'resin';
    case Packaging = 'packaging';
    case ConsumablesSparesTooling = 'consumables_spares_tooling';
    case Service = 'service';
    case Other = 'other';

    /** The three the Vendors tab and the PO picker show by default. */
    public static function defaults(): array
    {
        return [self::Resin, self::Packaging, self::ConsumablesSparesTooling];
    }

    public function label(): string
    {
        return match ($this) {
            self::Resin => 'Resin',
            self::Packaging => 'Packaging',
            self::ConsumablesSparesTooling => 'Consumables, Spares and Tooling',
            self::Service => 'Service',
            self::Other => 'Other',
        };
    }
}
```

```php
<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Procurement\Models\Enums\VendorClassification;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['vendor_id', 'classification'])]
class VendorClassificationRow extends Model
{
    protected $table = 'vendor_classifications';

    protected function casts(): array
    {
        return ['classification' => VendorClassification::class];
    }
}
```

On `Vendor`: `public function classifications(): HasMany { return $this->hasMany(VendorClassificationRow::class); }`.

- [ ] **Step 4: Request rule, service write and filter, controller, resource**

Both vendor requests: `'classifications' => ['sometimes', 'array'], 'classifications.*' => ['string', \Illuminate\Validation\Rule::enum(VendorClassification::class)],`.

`VendorService`: in `create` and `update`, after saving the vendor, when `array_key_exists('classifications', $data)`:

```php
$vendor->classifications()->delete();
foreach (array_unique($data['classifications']) as $value) {
    $vendor->classifications()->create(['classification' => $value]);
}
```

and change `paginate`:

```php
public function paginate(int $perPage = 20, ?string $search = null, ?array $classifications = null, bool $unclassified = false): LengthAwarePaginator
{
    $term = $search !== null ? trim($search) : '';

    return Vendor::query()
        ->with('classifications')
        ->when($term !== '', fn ($vendors) => $this->query->whereVendorMatches($vendors, $term))
        ->when($classifications !== null && $classifications !== [] && ! $unclassified,
            fn ($vendors) => $vendors->whereHas('classifications', fn ($c) => $c->whereIn('classification', $classifications)))
        ->when($classifications !== null && $classifications !== [] && $unclassified,
            fn ($vendors) => $vendors->where(fn ($w) => $w
                ->whereHas('classifications', fn ($c) => $c->whereIn('classification', $classifications))
                ->orWhereDoesntHave('classifications')))
        ->when(($classifications === null || $classifications === []) && $unclassified,
            fn ($vendors) => $vendors->whereDoesntHave('classifications'))
        ->orderBy('name')
        ->paginate($perPage);
}
```

Controller `index`: read `$request->query('classification')` (array or null) and `$request->boolean('unclassified')` and pass them through. Resource: `'classifications' => $this->whenLoaded('classifications', fn () => $this->classifications->map(fn ($r) => $r->classification->value)->sort()->values()->all(), []),` — and load the relation in `show`/`store`/`update` responses.

- [ ] **Step 5: Run the suite, Pint, commit**

Run: `cd backend && php artisan test --filter Vendor`
Expected: PASS.

```bash
cd backend && ./vendor/bin/pint --dirty
git add backend/database/migrations/2026_09_03_100200_create_vendor_classifications_table.php backend/app/Modules/Procurement backend/tests/Feature/Procurement/VendorClassificationTest.php
git commit -m "Vendors carry one or more of five classifications, set by a person (DEC-20260902-026)"
```

---

### Task 6: Vendor classification on the screens, with the default view (DEC-20260902-026)

**Files:**
- Create: `frontend/src/features/procurement/vendorClassification.ts`
- Create: `frontend/src/features/procurement/vendorClassification.test.ts`
- Modify: `frontend/src/features/procurement/types.ts:8-22` (Vendor gains `classifications: VendorClassification[]`), `api.ts:41-46` (`listVendors` gains `classifications?`, `unclassified?`), `pages/VendorsPage.tsx:32,105,200-214,264,307-310` (filter, column, form), `pages/PurchaseOrdersPage.tsx:78` (vendor picker default)

**Interfaces:**
- Consumes: `listVendors(page, perPage, search)`, `listAllVendors()`, `Vendor`.
- Produces: `VENDOR_CLASSIFICATIONS`, `DEFAULT_VENDOR_VIEW`, `vendorPickerOptions(vendors, showAll)`.

- [ ] **Step 1: Write the failing test**

```ts
import { describe, expect, it } from 'vitest';
import { DEFAULT_VENDOR_VIEW, vendorPickerOptions } from './vendorClassification';
import type { Vendor } from './types';

const vendor = (id: number, classifications: Vendor['classifications']): Vendor =>
    ({ id, code: `V-${id}`, name: `Vendor ${id}`, email: null, phone: null, address: null, gstin: null, state_code: null, tally_ledger_name: null, is_active: true, classifications }) as Vendor;

describe('vendorPickerOptions (DEC-20260902-026)', () => {
    const vendors = [vendor(1, ['resin']), vendor(2, ['service']), vendor(3, []), vendor(4, ['packaging', 'other'])];

    it('shows resin, packaging and consumables/spares/tooling vendors by default', () => {
        expect(DEFAULT_VENDOR_VIEW).toEqual(['resin', 'packaging', 'consumables_spares_tooling']);
        expect(vendorPickerOptions(vendors, false).map((o) => o.value)).toEqual([1, 4]);
    });

    it('shows service, other and unclassified vendors behind the explicit choice', () => {
        expect(vendorPickerOptions(vendors, true).map((o) => o.value)).toEqual([1, 2, 3, 4]);
    });

    it('labels an unclassified vendor as such', () => {
        expect(vendorPickerOptions(vendors, true).find((o) => o.value === 3)?.label).toBe('V-3 — Vendor 3 · Unclassified');
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx vitest run src/features/procurement/vendorClassification.test.ts`
Expected: FAIL — module not found.

- [ ] **Step 3: Write the helper**

```ts
import type { Vendor } from './types';

export type VendorClassification = 'resin' | 'packaging' | 'consumables_spares_tooling' | 'service' | 'other';

export const VENDOR_CLASSIFICATIONS: { value: VendorClassification; label: string }[] = [
    { value: 'resin', label: 'Resin' },
    { value: 'packaging', label: 'Packaging' },
    { value: 'consumables_spares_tooling', label: 'Consumables, Spares and Tooling' },
    { value: 'service', label: 'Service' },
    { value: 'other', label: 'Other' },
];

/** DEC-20260902-026: the three shown by default; Service, Other and Unclassified sit behind an explicit filter. */
export const DEFAULT_VENDOR_VIEW: VendorClassification[] = ['resin', 'packaging', 'consumables_spares_tooling'];

export function classificationLabel(value: VendorClassification): string {
    return VENDOR_CLASSIFICATIONS.find((c) => c.value === value)?.label ?? value;
}

/** Classification controls the default view only; it never blocks selecting a vendor (showAll offers every active vendor). */
export function vendorPickerOptions(vendors: readonly Vendor[] | undefined | null, showAll: boolean): { value: number; label: string }[] {
    const out: { value: number; label: string }[] = [];
    for (const v of vendors ?? []) {
        if (!v.is_active) continue;
        const inDefault = (v.classifications ?? []).some((c) => DEFAULT_VENDOR_VIEW.includes(c));
        if (!inDefault && !showAll) continue;
        const suffix = (v.classifications ?? []).length === 0 ? ' · Unclassified' : '';
        out.push({ value: v.id, label: `${v.code} — ${v.name}${suffix}` });
    }
    return out;
}
```

- [ ] **Step 4: Run the test**

Run: `cd frontend && npx vitest run src/features/procurement/vendorClassification.test.ts`
Expected: PASS (3 tests).

- [ ] **Step 5: Wire the API, the Vendors page and the PO picker**

`types.ts`: add `classifications: VendorClassification[];` to `Vendor` (import the type). `api.ts` `listVendors`:

```ts
export async function listVendors(page = 1, perPage = 50, search?: string, classifications?: VendorClassification[], unclassified = false): Promise<Paginated<Vendor>> {
    const term = search?.trim() ?? '';
    const { data } = await api.get<Paginated<Vendor>>('/procurement/vendors', {
        params: {
            page, per_page: perPage,
            ...(term !== '' ? { q: term } : {}),
            ...(classifications && classifications.length > 0 ? { classification: classifications } : {}),
            ...(unclassified ? { unclassified: 1 } : {}),
        },
    });
    return data;
}
```

`VendorsPage.tsx`: a multi-`Select` filter above the table, options `VENDOR_CLASSIFICATIONS` plus `{ value: '__unclassified', label: 'Unclassified' }`, defaulting to `DEFAULT_VENDOR_VIEW`, kept in the URL through the page's existing list-params hook (`@/lib/useListParams`), passed to `listVendors`. A `Classification` column after `Name` rendering `classifications.map(classificationLabel).join(', ')` or `Unclassified`. In the form (`:105` defaults, `:264` edit values, zod schema at `:32`): a multi-`Select` field `classifications` with `VENDOR_CLASSIFICATIONS`, default `[]`, sent on create and update. `PurchaseOrdersPage.tsx:78`: replace the vendor options with `vendorPickerOptions(vendors?.data, showAllVendors)` and a `Checkbox` labelled `Show all vendors`.

- [ ] **Step 6: Typecheck, test, commit**

```bash
cd frontend && npm run typecheck && npm run test
git add frontend/src/features/procurement/vendorClassification.ts frontend/src/features/procurement/vendorClassification.test.ts frontend/src/features/procurement/types.ts frontend/src/features/procurement/api.ts frontend/src/features/procurement/pages/VendorsPage.tsx frontend/src/features/procurement/pages/PurchaseOrdersPage.tsx
git commit -m "Vendors tab and PO picker show material vendors by default; the rest behind a filter (DEC-20260902-026)"
```

---

### Task 7: The Vendor page names the state (chapter 1 §2, required change 2)

**Files:**
- Modify: `backend/app/Modules/Procurement/Http/Resources/VendorResource.php:26` (add `state_name`)
- Modify: `frontend/src/features/procurement/types.ts` (Vendor gains `state_name: string | null`), `pages/VendorsPage.tsx:207` (column)
- Test: `backend/tests/Feature/Procurement/VendorStateNameTest.php`

**Interfaces:**
- Consumes: `App\Modules\Compliance\Services\GstStateCodes::name(?string $code): ?string` (`GstStateCodes.php:79`).
- Produces: resource key `state_name`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Procurement\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class VendorStateNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_state_code_is_answered_with_its_name(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permission::findOrCreate('procurement.view', 'sanctum'));
        Sanctum::actingAs($user);
        Vendor::create(['code' => 'V-PY', 'name' => 'Puducherry Co', 'state_code' => '34']);
        Vendor::create(['code' => 'V-NA', 'name' => 'No State Co']);

        $rows = collect($this->getJson('/api/v1/procurement/vendors')->assertOk()->json('data'))->keyBy('code');
        $this->assertSame('Puducherry', $rows['V-PY']['state_name']);
        $this->assertNull($rows['V-NA']['state_name']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter VendorStateNameTest`
Expected: FAIL — key missing.

- [ ] **Step 3: Resource and column**

`VendorResource.php`, after `'state_code' => $this->state_code,`:

```php
'state_name' => \App\Modules\Compliance\Services\GstStateCodes::name($this->state_code),
```

`VendorsPage.tsx:207`:

```tsx
{ title: 'State', dataIndex: 'state_code', render: (code: string | null, row: Vendor) => (code ? `${code} — ${row.state_name ?? 'Unknown code'}` : '') },
```

- [ ] **Step 4: Run, typecheck, commit**

```bash
cd backend && php artisan test --filter VendorStateNameTest && ./vendor/bin/pint --dirty
cd ../frontend && npm run typecheck
git add backend/app/Modules/Procurement/Http/Resources/VendorResource.php backend/tests/Feature/Procurement/VendorStateNameTest.php frontend/src/features/procurement/types.ts frontend/src/features/procurement/pages/VendorsPage.tsx
git commit -m "The Vendor page names the state beside its code"
```

---

### Task 8: The Supplier Bill shows a GRN line's rejected quantity and Rejections Out reference (DEC-20260902-015)

**Files:**
- Modify: `backend/app/Modules/Procurement/Http/Resources/GoodsReceiptNoteLineResource.php:55-70` (the `qc.inspection` block gains `rejected_quantity` and `rejections_out_reference` if absent)
- Modify: `frontend/src/features/procurement/types.ts` (`GoodsReceiptLineQc.inspection` gains the two keys), `pages/SupplierBillsPage.tsx:151-159` (label)
- Test: `backend/tests/Feature/Procurement/SupplierBillSeesRejectionTest.php`

**Interfaces:**
- Consumes: `IncomingInspection` fields `rejected_quantity`, `rejections_out_reference` (`IncomingInspection.php:14-18`); the `incomingInspections` relation the resource already reads at `:55`.
- Produces: `qc.inspection.rejected_quantity: string`, `qc.inspection.rejections_out_reference: string | null` on every GRN line the bill page lists.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use App\Modules\Inventory\Models\Enums\ItemCategory;
use App\Modules\Inventory\Models\Item;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use App\Modules\Quality\Models\IncomingInspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** DEC-20260902-015: the rejected quantity and the Rejections Out reference are visible where Accounts matches the supplier's paper. */
class SupplierBillSeesRejectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_receipt_line_answers_its_rejection(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['procurement.view', 'finance.view'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'sanctum'));
        }
        Sanctum::actingAs($user);

        $item = Item::create(['sku' => 'RESIN', 'name' => 'Resin', 'uom' => 'Kgs', 'category' => ItemCategory::RawMaterial]);
        // Build one PO + GRN through the API the way ProcurementEndToEndTest does (copy its helper), then:
        $line = GoodsReceiptNoteLine::query()->firstOrFail();
        IncomingInspection::create([
            'goods_receipt_note_line_id' => $line->id, 'item_id' => $item->id,
            'inspected_quantity' => $line->quantity, 'accepted_quantity' => bcsub($line->quantity, '25', 4), 'rejected_quantity' => '25',
            'result' => 'partial', 'inspection_date' => now()->toDateString(), 'inspected_by' => $user->id,
            'rejections_out_reference' => 'RJO-GRN1-L1',
        ]);

        $this->getJson("/api/v1/procurement/goods-receipts/{$line->goods_receipt_note_id}")
            ->assertOk()
            ->assertJsonPath('data.lines.0.qc.inspection.rejected_quantity', '25.0000')
            ->assertJsonPath('data.lines.0.qc.inspection.rejections_out_reference', 'RJO-GRN1-L1');
    }
}
```

Use the exact GRN creation helper from `ProcurementEndToEndTest.php` (read its `setUp` and the `receive` helper) rather than inventing payloads; the `result` value must be one of `InspectionResult`'s cases.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter SupplierBillSeesRejectionTest`
Expected: FAIL on the missing keys (if the keys already exist, the test passes and Step 3 is only the frontend).

- [ ] **Step 3: Resource keys and the bill label**

In the `inspection` array at `GoodsReceiptNoteLineResource.php:62-66` add:

```php
'rejected_quantity' => $inspection->rejected_quantity,
'rejections_out_reference' => $inspection->rejections_out_reference,
```

`SupplierBillsPage.tsx:151-159`:

```ts
const grnLineOptions = useMemo(
    () =>
        (receiptsQuery.data ?? []).map((line) => {
            const inspection = line.qc?.inspection;
            const rejected = inspection && Number(inspection.rejected_quantity) > 0
                ? ` · rejected ${inspection.rejected_quantity}${inspection.rejections_out_reference ? ` · ${inspection.rejections_out_reference}` : ''}`
                : '';
            return {
                value: line.id,
                label: `${grnNumber({ id: line.goods_receipt_note_id })} · ${itemLabel(line.item)} · received ${line.quantity}${rejected}`,
                item_id: line.item?.id,
            };
        }),
    [receiptsQuery.data],
);
```

Confirm `receiptsQuery` loads lines with `qc` (the GRN index/show resource loads `incomingInspections`; if the bill page's query uses a lighter endpoint, switch it to the one that carries `qc`).

- [ ] **Step 4: Run, typecheck, commit**

```bash
cd backend && php artisan test --filter SupplierBillSeesRejectionTest && ./vendor/bin/pint --dirty
cd ../frontend && npm run typecheck
git add backend/app/Modules/Procurement/Http/Resources/GoodsReceiptNoteLineResource.php backend/tests/Feature/Procurement/SupplierBillSeesRejectionTest.php frontend/src/features/procurement/types.ts frontend/src/features/procurement/pages/SupplierBillsPage.tsx
git commit -m "The Supplier Bill shows a receipt line's rejected quantity and Rejections Out reference (DEC-20260902-015)"
```

---

### Task 9: A goods receipt never exists without a purchase order (DEC-20260902-034, pinned)

**Files:**
- Test: `backend/tests/Feature/Procurement/ReceiptNeedsOrderTest.php`
- Modify only if the test fails: `backend/app/Modules/Procurement/Http/Requests/StoreGoodsReceiptNoteRequest.php` (`purchase_order_id` must be `required`).

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Feature\Procurement;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** DEC-20260902-034: PO first, always. The receipt screen never offers "receive without order", and the server refuses one. */
class ReceiptNeedsOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_receipt_without_a_purchase_order_is_refused(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['procurement.view', 'procurement.manage'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'sanctum'));
        }
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/procurement/goods-receipts', ['lines' => [['item_id' => 1, 'quantity' => '1']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['purchase_order_id']);
    }
}
```

- [ ] **Step 2: Run it**

Run: `cd backend && php artisan test --filter ReceiptNeedsOrderTest`
Expected: PASS if `purchase_order_id` is already required (the research note says receipts start from an open PO). If it FAILS, make the rule `['required', 'integer', 'exists:purchase_orders,id']` and re-run.

- [ ] **Step 3: Commit**

```bash
git add backend/tests/Feature/Procurement/ReceiptNeedsOrderTest.php
git commit -m "Pin: a goods receipt needs a purchase order (DEC-20260902-034)"
```

---

### Task 10: Full suites, PR, and the chapter's status lines

- [ ] **Step 1: Run everything**

```bash
cd backend && ./vendor/bin/pint --dirty && php artisan test
cd ../frontend && npm run typecheck && npm run test && npm run build
scripts/factory-knowledge/check.sh
```

Expected: all green; `FACTORY KNOWLEDGE: sound`.

- [ ] **Step 2: Update chapter 1**

In `docs/factory/workflows/01-DASHBOARD-AND-PROCUREMENT.md`, change the §3 GAP line "The current picker and backend enforce neither rule yet" to "Built: PR #<n>", and the §2 required changes 1 and 2 to "Built: PR #<n>". Commit with the code.

- [ ] **Step 3: Open the PR**

Follow the `ship-a-pr` skill: push `claude/procurement-controls`, `gh pr create` with a body that names the SHAs the suites passed on and the decisions each task implements. Note that merging deploys to the live factory and that the migrations add three nullable columns and one table, nothing destructive. After merge, the `deploy-live-verify` skill.

---

## Self-review

**Spec coverage.** DEC-20260902-025: Tasks 1, 2. DEC-20260902-023: Tasks 3, 4. DEC-20260902-026: Tasks 5, 6 (the ledger-group proposal is deferred to the customer/vendor import sub-project; the record allows it and does not require it). Chapter 1 §2 state names: Task 7. DEC-20260902-015: Task 8. DEC-20260902-034: Task 9. Chapter 1 §2 items 3–5 (Tally review rows, Excel contact import, duplicate vendors) are master-data work and belong to the import sub-project, not this one.

**Placeholders.** None; every step carries its code. Two steps say "read file X for the exact helper" (the PO request header fields, the end-to-end GRN helper, the auth store selector) because those shapes are only knowable from the file, and each says what to do with what is found.

**Type consistency.** `purchasePickerItems` returns `PurchasePickerItem { id, item, warning? }` in Task 3 and is consumed that way in the pages. `vendorPickerOptions` returns `{ value, label }` in Task 6 as tested. `VendorService::paginate` gains two trailing optional parameters, so the existing call at `VendorController.php:44` keeps compiling. `SelfDecisionException::forRequisition(int, string)` is called with `($locked->id, $verb)` in Task 1.
