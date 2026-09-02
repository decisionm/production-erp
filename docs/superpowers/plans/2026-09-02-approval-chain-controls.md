# Approval Chain and Start Batch Controls Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the batch approval chain and Start Batch enforce the owner's 02-Sep-2026 rules: the Quality checker cannot approve the same batch as Plant Manager; consumption variance is shown at every stage but never blocks; an added consumption line shows its category with a warning; the override reason and the packaging resolution are pinned as built; the two rollout switches (readiness, postability) are documented and pinned off.

**Architecture:** Server rules live in `ShiftProductionEntryService` beside the two four-eyes comparisons that already exist; the screens read the figures the entry resource already carries and add small pure helpers with their own vitest. Nothing here moves stock or posts to Tally; the two gates that would refuse live batches stay off until the live reads in the programme are done.

**Tech Stack:** Laravel 12 (PHP 8.3, PHPUnit via `php artisan test`), React + TypeScript (Vite, Ant Design, TanStack Query, vitest), Pint.

**Spec:** `docs/factory/workflows/02-QUALITY-INVENTORY-PRODUCTION.md` §4, §7–§12 and `docs/factory/CURRENT-DECISIONS.md` entries DEC-20260902-010, -017, -018, -019, -020, -021, -022. Programme: `docs/superpowers/plans/2026-09-02-workflow-build-programme.md`.

## Global Constraints

- The four-eyes flag is `production.approvals.allow_same_user` (default false) and there is NO Administrator exemption (FC-05, `backend/config/production.php:118-148`).
- Readiness enforcement is `production.readiness.enforced` (default false); the ten checks and their severities are at `backend/config/production.php:553-565`; colour is `warn`, `item_active` is `block`, and DEC-20260902-017 does not drop `item_active`.
- The posting gate is `production.approvals.require_postable_voucher` (default false, `backend/config/production.php:209`). Neither switch is turned on by this plan.
- Variance blocking is `variance_blocking_pct` and `unaccounted_blocking_kg` in the tolerance block of `backend/config/production.php` (null = disabled); DEC-20260902-022 keeps both null.
- No explanatory paragraph in the UI: a label or a figure, never a sentence (25-Aug rule).
- Money and stock quantities are decimal strings; never parse a kilogram figure into a float for display arithmetic.
- Branch: `claude/approval-chain-controls` off `decisionm/main`. Stage explicit paths, never `git add -A`.
- Before the PR: `cd backend && ./vendor/bin/pint --dirty && php artisan test`; `cd frontend && npm run typecheck && npm run test && npm run build`.

**Ruling recorded at planning:** DEC-20260902-021 (a reason for every Start Batch override) is already enforced by `ProductionConfigurationService::resolveEffectiveValues` (`backend/app/Modules/Production/Services/ProductionConfigurationService.php:760`, the `override_reason` refusal ~46 lines in) whenever an approved configuration governs the run, and the snapshot already stores `standard_cycle_time`, `standard_cavities`, `active_cavities`, `actual_cycle_time`, `override_reason` and `override_by` (`ShiftProductionEntryService.php:630-648`). Chapter 2 §11's GAP line overstated this. Task 4 therefore PINS the behaviour and labels the Factory Rules row rather than building a second mechanism.

---

### Task 1: The Quality checker cannot approve the same batch as Plant Manager (DEC-20260902-010)

**Files:**
- Modify: `backend/app/Modules/Production/Services/ShiftProductionEntryService.php:2637-2670` (`pmApprove`)
- Test: `backend/tests/Feature/FourEyesApprovalTest.php` (extend)

**Interfaces:**
- Consumes: `quality_checked_by` written by `recordQualityCheck` (`ShiftProductionEntryService.php:2017`); the accountant comparison pattern at `:2694-2712`; `InvalidStatusTransitionException` with a plain-sentence constructor.
- Produces: `pmApprove()` refuses when the signer is the checker, message `the person who checked quality cannot approve the same batch as plant manager`, relaxed only by `production.approvals.allow_same_user`.

- [ ] **Step 1: Write the failing tests**

Open `backend/tests/Feature/FourEyesApprovalTest.php`. Read `test_one_account_cannot_clear_both_gates` (`:71-95`) and `test_the_config_flag_relaxes_it_for_a_one_person_office` (`:112-128`) and copy their setup lines exactly (they create a completed entry, record its quality check, and drive the chain through the API). Add these two tests, changing only who acts:

```php
/** DEC-20260902-010: the third comparison — checker vs plant manager. */
public function test_the_quality_checker_cannot_approve_as_plant_manager(): void
{
    // Same fixture as test_one_account_cannot_clear_both_gates up to and
    // including the quality check, recorded by $checker.
    [$entry, $checker] = $this->completedEntryCheckedBy(); // extract this helper from the existing test's setup lines

    $checker->assignRole(\Spatie\Permission\Models\Role::findOrCreate('Plant Manager', 'sanctum'));
    \Laravel\Sanctum\Sanctum::actingAs($checker);

    $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/pm-approve")
        ->assertStatus(422)
        ->assertJsonPath('message', 'the person who checked quality cannot approve the same batch as plant manager');

    $this->assertDatabaseHas('shift_production_entries', ['id' => $entry->id, 'status' => 'pending', 'plant_manager_signed_by' => null]);
}

public function test_the_flag_relaxes_the_checker_comparison_too(): void
{
    config()->set('production.approvals.allow_same_user', true);
    [$entry, $checker] = $this->completedEntryCheckedBy();

    $checker->assignRole(\Spatie\Permission\Models\Role::findOrCreate('Plant Manager', 'sanctum'));
    \Laravel\Sanctum\Sanctum::actingAs($checker);

    $this->postJson("/api/v1/production/shift-production-entries/{$entry->id}/pm-approve")->assertOk();
}
```

Write `completedEntryCheckedBy(): array{0: ShiftProductionEntry, 1: User}` as a private helper by moving the existing setup lines into it, and make the two existing tests call it too so the fixture lives once. If the existing tests build the checker with `production.manage` plus `quality.manage` on one user, keep that shape.

- [ ] **Step 2: Run to verify they fail**

Run: `cd backend && php artisan test --filter FourEyesApprovalTest`
Expected: the two new tests FAIL (approve returns 200 today); the existing ones still pass.

- [ ] **Step 3: Add the comparison to `pmApprove`**

Directly after the quality-gate block (before the `return $this->advance(...)` at `:2664`), mirror the accountant gate:

```php
// DEC-20260902-010 — the third four-eyes comparison. Same flag, same
// absence of an Administrator exemption, as the other two.
$checkedBy = ShiftProductionEntry::query()
    ->whereKey($entry->id)
    ->where('status', ShiftProductionEntryStatus::Pending->value)
    ->value('quality_checked_by');

if (
    $checkedBy !== null
    && (int) $checkedBy === $signedBy
    && ! (bool) config('production.approvals.allow_same_user', false)
) {
    throw new InvalidStatusTransitionException('the person who checked quality cannot approve the same batch as plant manager');
}
```

Update the docblock at `backend/config/production.php:136-145` ("IT IS STILL NOT THE PM GATE…") to say the third comparison now exists under DEC-20260902-010.

- [ ] **Step 4: Run the file, then the approval suites**

Run: `cd backend && php artisan test --filter "FourEyesApprovalTest|ApprovalChainTest|BatchQualityStageTest"`
Expected: PASS.

- [ ] **Step 5: Pint and commit**

```bash
cd backend && ./vendor/bin/pint --dirty
git add backend/app/Modules/Production/Services/ShiftProductionEntryService.php backend/config/production.php backend/tests/Feature/FourEyesApprovalTest.php
git commit -m "The quality checker cannot approve the same batch as plant manager (DEC-20260902-010)"
```

---

### Task 2: Consumption figures at every stage, never a block (DEC-20260902-022)

**Files:**
- Create: `frontend/src/features/production/consumptionSummary.ts`
- Create: `frontend/src/features/production/consumptionSummary.test.ts`
- Modify: `frontend/src/features/quality/pages/ProductionQcPage.tsx` (`QualityCheckDrawer`, rendered at `:260`) and `frontend/src/features/production/pages/ApproveProductionPage.tsx:308-345` (the Descriptions block)
- Test: `backend/tests/Unit/ConsumptionBlockingDefaultsTest.php`

**Interfaces:**
- Consumes: `ConsumptionVariance` (`frontend/src/features/production/types.ts:163-183`): `expected_kg`, `actual_kg`, `variance_kg`, `variance_pct`, `unaccounted_kg`, all strings or null; the entry resource key `variance` (`ShiftProductionEntryResource.php:288`).
- Produces: `consumptionSummary(v: ConsumptionVariance | null): { label: string; value: string }[]`.

- [ ] **Step 1: Write the failing frontend test**

```ts
import { describe, expect, it } from 'vitest';
import { consumptionSummary } from './consumptionSummary';

describe('consumptionSummary (DEC-20260902-022)', () => {
    it('lists expected, actual, variance and unaccounted as figures', () => {
        expect(consumptionSummary({
            norm_source: 'bom', expected_kg: '100.0000', actual_kg: '104.5000', variance_kg: '4.5000', variance_pct: 4.5,
            rejection_kg: '0', scrap_kg: '1.0000', unaccounted_kg: '3.5000',
        })).toEqual([
            { label: 'Expected kg', value: '100.0000' },
            { label: 'Actual kg', value: '104.5000' },
            { label: 'Variance', value: '+4.5000 kg (4.5%)' },
            { label: 'Unaccounted kg', value: '3.5000' },
        ]);
    });

    it('shows a dash where no norm exists, never a zero', () => {
        expect(consumptionSummary({
            norm_source: null, expected_kg: null, actual_kg: '12.0000', variance_kg: null, variance_pct: null,
            rejection_kg: '0', scrap_kg: '0', unaccounted_kg: null,
        })).toEqual([
            { label: 'Expected kg', value: '—' },
            { label: 'Actual kg', value: '12.0000' },
            { label: 'Variance', value: '—' },
            { label: 'Unaccounted kg', value: '—' },
        ]);
    });

    it('returns the four dashes for a batch not yet completed', () => {
        expect(consumptionSummary(null).map((r) => r.value)).toEqual(['—', '—', '—', '—']);
    });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd frontend && npx vitest run src/features/production/consumptionSummary.test.ts`
Expected: FAIL — module not found.

- [ ] **Step 3: Write the helper**

```ts
import type { ConsumptionVariance } from './types';

const DASH = '—';

function signed(kg: string): string {
    return kg.startsWith('-') ? kg : `+${kg}`;
}

/**
 * DEC-20260902-022: the four consumption figures a signer must see before
 * signing. Figures only; the server never refuses on them. Strings pass
 * through untouched — no float arithmetic on a kilogram.
 */
export function consumptionSummary(v: ConsumptionVariance | null | undefined): { label: string; value: string }[] {
    if (!v) {
        return [
            { label: 'Expected kg', value: DASH },
            { label: 'Actual kg', value: DASH },
            { label: 'Variance', value: DASH },
            { label: 'Unaccounted kg', value: DASH },
        ];
    }
    const variance = v.variance_kg !== null && v.variance_pct !== null ? `${signed(v.variance_kg)} kg (${v.variance_pct}%)` : DASH;
    return [
        { label: 'Expected kg', value: v.expected_kg ?? DASH },
        { label: 'Actual kg', value: v.actual_kg },
        { label: 'Variance', value: variance },
        { label: 'Unaccounted kg', value: v.unaccounted_kg ?? DASH },
    ];
}
```

- [ ] **Step 4: Run the test, then wire the screens**

Run: `cd frontend && npx vitest run src/features/production/consumptionSummary.test.ts` — PASS.

In `ProductionQcPage.tsx`'s `QualityCheckDrawer`, above the reviewed/ok/rejected inputs, render:

```tsx
<Descriptions column={2} size="small" bordered title="Consumption">
    {consumptionSummary(entry.variance).map((row) => (
        <Descriptions.Item key={row.label} label={row.label}>{row.value}</Descriptions.Item>
    ))}
</Descriptions>
```

where `entry` is the drawer's `ShiftProductionEntry` prop (check its prop name at `:260-270`). In `ApproveProductionPage.tsx`, inside the existing `Descriptions` at `:308`, add the same four items after "Cost per accepted piece" if `unaccounted_kg` is not already rendered there (the page renders `variance_pct` through `varianceTag` at `:176`; keep that tag, add the figures). Both stages, Plant Manager and Accounts, use this page, so both see them.

- [ ] **Step 5: Pin the blocking defaults**

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;

/** DEC-20260902-022: both blocking thresholds stay disabled; variance is advisory. */
class ConsumptionBlockingDefaultsTest extends TestCase
{
    public function test_both_blocking_thresholds_default_to_disabled(): void
    {
        $tolerance = config('production.tolerance'); // the block holding machine_balance_ack_kg at config/production.php:91
        $this->assertNull($tolerance['variance_blocking_pct']);
        $this->assertNull($tolerance['unaccounted_blocking_kg']);
    }
}
```

If the block is keyed differently, read `backend/config/production.php:86-100` and use that key; the assertion does not change.

- [ ] **Step 6: Typecheck, tests, commit**

```bash
cd frontend && npm run typecheck && npm run test
cd ../backend && php artisan test --filter ConsumptionBlockingDefaultsTest && ./vendor/bin/pint --dirty
git add frontend/src/features/production/consumptionSummary.ts frontend/src/features/production/consumptionSummary.test.ts frontend/src/features/quality/pages/ProductionQcPage.tsx frontend/src/features/production/pages/ApproveProductionPage.tsx backend/tests/Unit/ConsumptionBlockingDefaultsTest.php
git commit -m "Consumption figures at Quality, Plant Manager and Accounts; blocking stays off (DEC-20260902-022)"
```

---

### Task 3: An added consumption line shows its category with a warning (DEC-20260902-019)

**Files:**
- Create: `frontend/src/features/production/addedLine.ts`
- Create: `frontend/src/features/production/addedLine.test.ts`
- Modify: `frontend/src/features/production/pages/ShiftProductionEntryPage.tsx` (the completion drawer, where options carry `is_expected`)

**Interfaces:**
- Consumes: the consumable option shape from `RunConsumableOptionsService` (`backend/app/Modules/Production/Services/RunConsumableOptionsService.php:224`): `{ item_id, name, sku, uom, category, is_expected, qa_held }`, already returned to the page.
- Produces: `addedLineWarning(category: string | null | undefined): string | null`.

- [ ] **Step 1: Write the failing test**

```ts
import { describe, expect, it } from 'vitest';
import { addedLineWarning } from './addedLine';

describe('addedLineWarning (DEC-20260902-019)', () => {
    it('flags an unclassified item', () => {
        expect(addedLineWarning(null)).toBe('Unclassified');
        expect(addedLineWarning(undefined)).toBe('Unclassified');
    });
    it('names Other as spare, tooling or consumable', () => {
        expect(addedLineWarning('other')).toBe('Other: spare, tooling or consumable');
    });
    it('is silent for raw and packing material', () => {
        expect(addedLineWarning('raw_material')).toBeNull();
        expect(addedLineWarning('packing_material')).toBeNull();
    });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd frontend && npx vitest run src/features/production/addedLine.test.ts` — FAIL, module not found.

- [ ] **Step 3: Write the helper and render it**

```ts
/**
 * DEC-20260902-019: an off-plan spare, tooling or unclassified item is
 * accepted with a reason and an authorised person, its category shown
 * clearly with a warning. Nothing here blocks; the server keeps its
 * refusal set (finished goods and the run's own product only).
 */
export function addedLineWarning(category: string | null | undefined): string | null {
    if (category === null || category === undefined) return 'Unclassified';
    if (category === 'other') return 'Other: spare, tooling or consumable';
    return null;
}
```

In `ShiftProductionEntryPage.tsx`, find where consumable options with `is_expected === false` are offered and where an added line renders in the completion drawer (search `is_expected`). Next to the option label and in the added-line row render `{addedLineWarning(option.category) && <Tag color="warning">{addedLineWarning(option.category)}</Tag>}`. Nothing else changes: the reason field and the permission refusal already exist (DEC-20260901-007).

- [ ] **Step 4: Typecheck, test, commit**

```bash
cd frontend && npm run typecheck && npm run test
git add frontend/src/features/production/addedLine.ts frontend/src/features/production/addedLine.test.ts frontend/src/features/production/pages/ShiftProductionEntryPage.tsx
git commit -m "An added consumption line shows its category with a warning (DEC-20260902-019)"
```

---

### Task 4: Pin the override reason, and label the Factory Rules row it fulfils (DEC-20260902-021)

**Files:**
- Test: `backend/tests/Feature/OverrideReasonRequiredTest.php`
- Modify: `frontend/src/features/production/factoryRules.ts:82-84` (`ruleAppliedLabel`), `factoryRules.test.ts:61`, `components/FactoryRulesTab.tsx` (pass the key), `components/FactoryRulesTab.render.test.tsx:122`

**Interfaces:**
- Consumes: `resolveEffectiveValues` refusal `override_reason` ("A reason is required when overriding the approved cycle time or cavities."); the Factory Rules row key `REQUIRE_OVERRIDE_REASON` (`backend/database/seeders/ProductionConfigurationDefaultsSeeder.php`, the row labelled "Require a reason for every override").
- Produces: `ruleAppliedLabel(applied: boolean, key?: string)`; for `REQUIRE_OVERRIDE_REASON` it returns `{ text: 'Enforced at Start Batch', tone: 'success' }`.

- [ ] **Step 1: Write the backend pin**

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DEC-20260902-021, pinned: with an approved machine configuration, a Start
 * Batch that overrides its cycle time or cavities needs a reason; the
 * snapshot keeps the original, the selected value, the reason and the person.
 * A value outside the configuration's limits is refused whatever the reason.
 */
class OverrideReasonRequiredTest extends TestCase
{
    use RefreshDatabase;

    // Build the fixture exactly as ProductReadinessGateTest::test_a_fully_mastered_product_starts_normally
    // does (read backend/tests/Feature/ProductReadinessGateTest.php:101-110 and its setUp), with an APPROVED
    // ProductionConfiguration whose default_cycle_time is 10 and cycle_time_min/max are 8/14.

    public function test_an_override_without_a_reason_is_refused(): void
    {
        $payload = $this->startPayload(['cycle_time_override' => 12]);
        $this->postJson('/api/v1/production/shift-production-entries', $payload)
            ->assertStatus(422)->assertJsonValidationErrors(['override_reason']);
    }

    public function test_an_override_with_a_reason_is_recorded_with_the_original(): void
    {
        $payload = $this->startPayload(['cycle_time_override' => 12, 'override_reason' => 'Mould running hot']);
        $id = $this->postJson('/api/v1/production/shift-production-entries', $payload)->assertCreated()->json('data.id');

        $this->assertDatabaseHas('shift_production_entries', [
            'id' => $id, 'standard_cycle_time' => 10, 'cycle_time_source' => 'override', 'override_reason' => 'Mould running hot',
        ]);
        $this->assertNotNull(\App\Modules\Production\Models\ShiftProductionEntry::find($id)->override_by);
    }

    public function test_a_reason_never_bypasses_the_limit(): void
    {
        $payload = $this->startPayload(['cycle_time_override' => 20, 'override_reason' => 'Trying it']);
        $this->postJson('/api/v1/production/shift-production-entries', $payload)->assertStatus(422);
    }
}
```

`startPayload(array $overrides): array` is the helper that returns the same body the readiness test posts, merged with `$overrides`. If the route or field names differ from the readiness test, take the readiness test's names; the three assertions do not change.

- [ ] **Step 2: Run it**

Run: `cd backend && php artisan test --filter OverrideReasonRequiredTest`
Expected: PASS without code change (the behaviour exists). If a case fails, the ruling above was wrong: report BLOCKED with the failing output rather than patching around it.

- [ ] **Step 3: Label the Factory Rules row**

`factoryRules.ts:82`:

```ts
/** DEC-20260902-021: one of the ten rows is enforced, by Start Batch itself, not by reading the row. */
const ENFORCED_ELSEWHERE: Record<string, string> = { REQUIRE_OVERRIDE_REASON: 'Enforced at Start Batch' };

export function ruleAppliedLabel(applied: boolean, key?: string): { text: string; tone: 'success' | 'default' } {
    if (key && ENFORCED_ELSEWHERE[key]) return { text: ENFORCED_ELSEWHERE[key], tone: 'success' };
    return applied ? { text: 'In use', tone: 'success' } : { text: 'Not in use', tone: 'default' };
}
```

Add to `factoryRules.test.ts`: `expect(ruleAppliedLabel(false, 'REQUIRE_OVERRIDE_REASON')).toEqual({ text: 'Enforced at Start Batch', tone: 'success' });`. In `FactoryRulesTab.tsx` pass `setting.key` as the second argument. In `FactoryRulesTab.render.test.tsx`, add an assertion that the rendered HTML for a `REQUIRE_OVERRIDE_REASON` row contains `Enforced at Start Batch`.

- [ ] **Step 4: Tests and commit**

```bash
cd frontend && npm run typecheck && npm run test
cd ../backend && ./vendor/bin/pint --dirty
git add backend/tests/Feature/OverrideReasonRequiredTest.php frontend/src/features/production/factoryRules.ts frontend/src/features/production/factoryRules.test.ts frontend/src/features/production/components/FactoryRulesTab.tsx frontend/src/features/production/components/FactoryRulesTab.render.test.tsx
git commit -m "Pin the Start Batch override reason and label its Factory Rules row (DEC-20260902-021)"
```

---

### Task 5: Pin the two rollout switches and the readiness list (DEC-20260902-017, -018)

**Files:**
- Test: `backend/tests/Feature/ProductReadinessGateTest.php` (extend), `backend/tests/Unit/ApprovalGateDefaultsTest.php` (create)
- Modify: `backend/config/production.php:174-215` and `:550-565` (comments only, citing the two decisions and the programme's live reads)

- [ ] **Step 1: Write the tests**

Extend `ProductReadinessGateTest` by copying `test_a_product_missing_cycle_time_and_packing_is_refused_naming_both` (`:111-131`) into two new tests that run with `config()->set('production.readiness.enforced', true)`:

```php
public function test_an_inactive_item_is_refused_when_enforced(): void
{
    config()->set('production.readiness.enforced', true);
    // fixture as the copied test, then:
    $this->item->update(['is_active' => false]);
    $this->postJson('/api/v1/production/shift-production-entries', $this->startPayload())
        ->assertStatus(422)->assertJsonFragment(['check' => 'item_active']);
}

public function test_colour_is_only_a_warning_when_enforced(): void
{
    config()->set('production.readiness.enforced', true);
    // fixture as the copied test with the product's colour cleared, everything else mastered:
    $this->postJson('/api/v1/production/shift-production-entries', $this->startPayload())
        ->assertCreated()->assertJsonFragment(['check' => 'colour']); // reported, not refused
}
```

Use the file's own payload helper and fixture names; the refusal body's shape (the `check` key) is whatever `ProductNotReadyException` renders — read the existing test's assertions and match them.

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;

/** DEC-20260902-017 and -018: both gates ship OFF and are switched on only after the live reads named in the programme. */
class ApprovalGateDefaultsTest extends TestCase
{
    public function test_readiness_and_postability_default_off(): void
    {
        $this->assertFalse((bool) config('production.readiness.enforced'));
        $this->assertFalse((bool) config('production.approvals.require_postable_voucher'));
        $this->assertSame('block', config('production.readiness.checks.item_active'));
        $this->assertSame('warn', config('production.readiness.checks.colour'));
    }
}
```

- [ ] **Step 2: Run them**

Run: `cd backend && php artisan test --filter "ProductReadinessGateTest|ApprovalGateDefaultsTest"`
Expected: PASS without code change. A failure here is a finding for the controller, not something to patch.

- [ ] **Step 3: Config comments and commit**

Above `'enforced'` at `backend/config/production.php:553` and above `'require_postable_voucher'` at `:209`, add one line each: `// DEC-20260902-017: switched on only after every active production product shows Ready on live.` and `// DEC-20260902-018: switched on only after the voucher preview is checked against real batches on live.`

```bash
cd backend && ./vendor/bin/pint --dirty
git add backend/config/production.php backend/tests/Feature/ProductReadinessGateTest.php backend/tests/Unit/ApprovalGateDefaultsTest.php
git commit -m "Pin the readiness and postability gates off, item_active blocking, colour warning (DEC-20260902-017, -018)"
```

---

### Task 6: Pin the packaging resolution at Start Batch (DEC-20260902-020)

**Files:**
- Test: `backend/tests/Feature/PackagingResolutionTest.php`

**Interfaces:**
- Consumes: `startBatch` resolving the standard and packaging (`ShiftProductionEntryService.php:480-510`), `ProductionStandardPackaging` with `is_default` (`backend/app/Modules/Production/Models/ProductionStandardPackaging.php:5-20`).

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Feature;

use App\Modules\Production\Models\ProductionStandardPackaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** DEC-20260902-020: one option selects itself; several with a default use it; several without a default ask. */
class PackagingResolutionTest extends TestCase
{
    use RefreshDatabase;

    // Fixture as ProductReadinessGateTest::test_a_fully_mastered_product_starts_normally, exposing $this->standard.

    public function test_a_single_packaging_is_selected_without_asking(): void
    {
        $only = $this->standard->packagings()->first();
        $id = $this->postJson('/api/v1/production/shift-production-entries', $this->startPayload())->assertCreated()->json('data.id');
        $this->assertDatabaseHas('shift_production_entries', ['id' => $id, 'production_standard_packaging_id' => $only->id]);
    }

    public function test_the_default_is_used_when_several_exist(): void
    {
        $second = $this->standard->packagings()->create(['mode' => 'tray', 'nos_per_tray' => 10, 'trays_per_box' => 52, 'nos_per_box' => 520, 'is_default' => true]);
        $id = $this->postJson('/api/v1/production/shift-production-entries', $this->startPayload())->assertCreated()->json('data.id');
        $this->assertDatabaseHas('shift_production_entries', ['id' => $id, 'production_standard_packaging_id' => $second->id]);
    }

    public function test_several_without_a_default_ask_the_supervisor(): void
    {
        $this->standard->packagings()->update(['is_default' => false]);
        $this->standard->packagings()->create(['mode' => 'tray', 'nos_per_tray' => 10, 'trays_per_box' => 52, 'nos_per_box' => 520, 'is_default' => false]);
        $this->postJson('/api/v1/production/shift-production-entries', $this->startPayload())
            ->assertStatus(422)->assertJsonValidationErrors(['production_standard_packaging_id']);
    }
}
```

The snapshot column that names the chosen packaging, and the request field the supervisor answers with, are whatever `startBatch` uses at `:480-510`; take those names and keep the three behaviours.

- [ ] **Step 2: Run it**

Run: `cd backend && php artisan test --filter PackagingResolutionTest`
Expected: PASS without code change. If the single-option case asks instead of selecting, that is the one gap DEC-20260902-020 names ("confirm the single-option auto-select"): implement it in `startBatch` where the packaging is resolved — when the standard has exactly one packaging and none was supplied, use it — and re-run.

- [ ] **Step 3: Commit**

```bash
cd backend && ./vendor/bin/pint --dirty
git add backend/tests/Feature/PackagingResolutionTest.php backend/app/Modules/Production/Services/ShiftProductionEntryService.php
git commit -m "Pin the Start Batch packaging resolution (DEC-20260902-020)"
```

---

### Task 7: Full suites, chapter lines, PR

- [ ] **Step 1: Run everything**

```bash
cd backend && ./vendor/bin/pint --dirty && php artisan test
cd ../frontend && npm run typecheck && npm run test && npm run build
scripts/factory-knowledge/check.sh
```

- [ ] **Step 2: Chapter 2**

In `docs/factory/workflows/02-QUALITY-INVENTORY-PRODUCTION.md`: §4's GAP for -010 becomes "Built: PR #<n>"; §9's GAP (category warning) likewise; §11's GAP is corrected to "the reason was already demanded under an approved configuration; pinned and the Factory Rules row labelled, PR #<n>"; §12's GAP likewise; §7 gains "item_active pinned as a refusal, PR #<n>".

- [ ] **Step 3: PR**

`ship-a-pr` skill: push `claude/approval-chain-controls`, `gh pr create`, body naming the SHAs the suites passed on and the decisions per task; no migration in this plan. After merge, `deploy-live-verify`.

---

## Self-review

**Spec coverage.** -010: Task 1. -022: Task 2. -019: Task 3. -021: Task 4 (pinned, per the ruling). -017/-018: Task 5 (pinned off, list pinned; the switch itself is a rollout act after the live reads). -020: Task 6. Chapter 2 §12's "figures on the Quality screen and both approval screens": Task 2.

**Placeholders.** Three tests instruct the implementer to take fixture and field names from a named existing test; each states the assertions that must hold regardless. No TBDs.

**Type consistency.** `consumptionSummary` returns `{ label, value }[]` in both the test and the pages. `ruleAppliedLabel(applied, key?)` keeps its first parameter so existing callers compile. `addedLineWarning` returns `string | null` and is used as a truthy guard.
