<?php

namespace Tests\Feature\Regression;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Regression\Support\RegressionFixtures;
use Tests\TestCase;

/**
 * Phase 7 regression smoke (P7-05, WS-E) — every adopted module's top-level
 * lists, read back with ONE seeded row each, and refused to a reader who
 * holds every permission in the catalogue EXCEPT that module's own.
 *
 * The first half is the "does the page open" question for every screen in
 * the sidebar: 200, `data` carrying the seeded row, `meta` on the paginated
 * lists. The second half is the permission wall: the strongest reader who
 * lacks the module's view/manage pair still gets 403 from the module
 * middleware — a hole here would be a permission granted by some OTHER
 * module's permission, which is exactly what EnsureModulePermission exists
 * to prevent.
 *
 * Shapes (per URI, so a change of shape is a deliberate diff here):
 *   P  paginated resource collection — `data` (≥ 1 row) + `meta`
 *   L  plain resource collection      — `data` (≥ 1 row), no paginator
 *   R  raw LengthAwarePaginator       — `data` (≥ 1 row) + `current_page`
 *      (production/standards answers the workspace paginator, not a
 *      JsonResource collection — recorded, not corrected, by this smoke)
 *   O  object                         — `data` present (reports, settings)
 *   B  bare object, no `data` wrapper — 200 (sales/tally-mirror says so in
 *      routes/api.php: "a bare object, not a row")
 *   T  traceability-gated             — as P, read with the flag ON
 */
class ModuleIndexTest extends TestCase
{
    use RefreshDatabase, RegressionFixtures;

    /**
     * module → [permission module (or null for the open surface), [uri => shape]].
     *
     * The URIs are relative to /api/v1/. Query strings carry the parameters
     * a report or a workspace needs to answer at all.
     */
    private const MODULES = [
        'masters' => ['production', [
            'production/work-centers' => 'P',
            'production/shifts' => 'P',
            'production/molds' => 'P',
            'production/scrap-reasons' => 'P',
            'production/downtime-reasons' => 'L',
            'production/configurations' => 'P',
            'production/masterbatch-dosings' => 'L',
            'production/packing-material-mappings' => 'L',
            'production/packing-material-options' => 'L',
            'production/factory-settings' => 'L',
            'production/settings' => 'O',
        ]],
        'products' => ['production', [
            'production/standards?view=all' => 'R',
            'production/standards/coverage' => 'L',
            'production/configuration/review' => 'O',
        ]],
        'purchase' => ['procurement', [
            'procurement/vendors' => 'P',
            'procurement/purchase-requisitions' => 'P',
            'procurement/purchase-orders' => 'P',
            'procurement/goods-receipts' => 'P',
        ]],
        'inventory' => ['inventory', [
            'inventory/items' => 'P',
            'inventory/warehouses' => 'P',
            'inventory/stock-balances' => 'P',
            'inventory/stock-movements' => 'P',
            'inventory/batches' => 'P',
            'inventory/serial-numbers' => 'P',
            'inventory/material-lots' => 'T',
            'inventory/material-bags' => 'T',
        ]],
        'production' => ['production', [
            'production/shift-production-entries' => 'P',
            'production/shift-production-entries/active' => 'L',
            'production/boms' => 'P',
            'production/routings' => 'P',
            'production/work-orders' => 'P',
            'production/subcontract-orders' => 'P',
            'production/rework-orders' => 'P',
            'production/machine-downtime-logs' => 'P',
            'production/mold-change-logs' => 'P',
            'production/power-interruption-logs' => 'P',
            'production/shift-stock-counts' => 'P',
            'production/factory-day-bin' => 'O',
            'production/factory-day-bin/raw-materials' => 'L',
            'production/machine-resin' => 'O',
        ]],
        'sales' => ['sales', [
            'sales/customers' => 'P',
            'sales/sales-orders' => 'P',
            'sales/deliveries' => 'P',
            'sales/invoices' => 'P',
            'sales/tally-mirror' => 'B',
        ]],
        'tally-sync' => ['tally-sync', [
            'tally-sync/entries' => 'P',
            'tally-sync/summary' => 'O',
            'tally-sync/agent-tokens' => 'L',
            'tally-sync/stock-snapshots' => 'L',
            'tally-sync/settings' => 'O',
        ]],
        'quality' => ['quality', [
            'quality/incoming-inspections' => 'P',
            'quality/ncrs' => 'P',
            'quality/capas' => 'P',
            'quality/instruments' => 'P',
            'quality/spc-characteristics' => 'P',
        ]],
        'maintenance' => ['maintenance', [
            'maintenance/assets' => 'P',
            'maintenance/schedules' => 'P',
            'maintenance/work-orders' => 'P',
        ]],
        'hrms' => ['hrms', [
            'hrms/employees' => 'P',
            'hrms/leave-types' => 'P',
            'hrms/leave-balances' => 'P',
            'hrms/leave-requests' => 'P',
            'hrms/attendance' => 'P',
        ]],
        'payroll' => ['payroll', [
            'payroll/salary-components' => 'P',
            'payroll/salary-structures' => 'P',
            'payroll/runs' => 'P',
            'payroll/payslips' => 'P',
        ]],
        'finance' => ['finance', [
            'finance/gl-accounts' => 'P',
            'finance/journal-entries' => 'P',
        ]],
        'crm' => ['crm', [
            'crm/leads' => 'P',
            'crm/opportunities' => 'P',
            'crm/quotations' => 'P',
        ]],
        'compliance' => ['compliance', [
            'compliance/gst-rates' => 'P',
            'compliance/gst-registrations' => 'P',
        ]],
        'administration' => ['users', [
            'users' => 'P',
        ]],
        'administration-roles' => ['roles', [
            'roles' => 'L',
            'permissions' => 'L',
        ]],
    ];

    /**
     * The reports: each read with the parameters it needs, each behind its
     * own module. uri => [permission module, shape].
     */
    private const REPORTS = [
        'production/reports/production?date=2026-08-12' => ['production', 'O'],
        'production/reports/reconciliation?date_from=2026-08-01&date_to=2026-08-31' => ['production', 'O'],
        'production/reports/traceability?date_from=2026-08-01&date_to=2026-08-31' => ['production', 'O'],
        'production/shift-summaries/report?production_date=2026-08-12' => ['production', 'O'],
        'production/cec?date=2026-08-12' => ['production', 'O'],
        'production/capacity/load-report?start_date=2026-08-01&end_date=2026-08-31' => ['production', 'O'],
        'finance/reports/trial-balance' => ['finance', 'O'],
        'finance/reports/profit-and-loss' => ['finance', 'O'],
        'finance/reports/balance-sheet' => ['finance', 'O'],
        'finance/reports/receivables' => ['finance', 'O'],
        'compliance/reports/gstr1' => ['compliance', 'O'],
    ];

    /** @return array<string, array{0: string}> */
    public static function modules(): array
    {
        return array_combine(
            array_keys(self::MODULES),
            array_map(fn (string $module) => [$module], array_keys(self::MODULES)),
        );
    }

    // ---- the seeded row comes back -----------------------------------------

    #[DataProvider('modules')]
    public function test_each_top_level_list_of_the_module_answers_its_seeded_row(string $module): void
    {
        $admin = $this->actAsAdministrator();
        $this->seedEveryModule($admin);

        [, $lists] = self::MODULES[$module];
        $failures = [];

        foreach ($lists as $uri => $shape) {
            config(['production.traceability_enabled' => $shape === 'T']);
            $response = $this->getJson('/api/v1/'.$uri);
            $problem = $this->shapeProblem($response->getStatusCode(), $response->json(), $shape);
            if ($problem !== null) {
                $failures[] = "{$uri}: {$problem}";
            }
        }

        $this->assertSame([], $failures, "{$module}: ".implode(' | ', $failures));
    }

    public function test_each_report_answers_with_the_seeded_data_behind_it(): void
    {
        $admin = $this->actAsAdministrator();
        $fx = $this->seedEveryModule($admin);
        config(['production.traceability_enabled' => true]);
        $failures = [];

        $reports = self::REPORTS + [
            "maintenance/reports/reliability?asset_id={$fx['asset']->id}" => ['maintenance', 'O'],
            "production/mrp/net-requirements?item_id={$fx['bottle']->id}&quantity=1" => ['production', 'O'],
        ];

        foreach ($reports as $uri => [, $shape]) {
            $response = $this->getJson('/api/v1/'.$uri);
            $problem = $this->shapeProblem($response->getStatusCode(), $response->json(), $shape);
            if ($problem !== null) {
                $failures[] = "{$uri}: {$problem}";
            }
        }

        $this->assertSame([], $failures, implode(' | ', $failures));
    }

    public function test_the_download_center_lists_kinds_for_the_administrator_and_nothing_for_a_reader_with_no_permission(): void
    {
        $this->actAsAdministrator();

        $catalogue = $this->getJson('/api/v1/exports')->assertOk()->json('data');
        $this->assertNotEmpty($catalogue, 'the export catalogue offers the administrator nothing');
        $this->getJson('/api/v1/exports/runs')->assertOk()->assertJsonStructure(['data']);

        // No module gate here by design (routes/api.php: "any authenticated
        // user may ask; each KIND carries its own permissionAny()") — the
        // documented answer for a reader with no permission is an EMPTY
        // catalogue, not a 403.
        Sanctum::actingAs($this->userHolding([], 'Regression Nobody'));
        $this->getJson('/api/v1/exports')->assertOk()->assertExactJson(['data' => []]);
        $this->getJson('/api/v1/exports/runs')->assertOk();
    }

    /**
     * Reads that are DELIBERATELY open to more than one module, so the wall
     * below cannot expect a 403 on them.
     *
     * `sales/deliveries`: the STORE performs the final dispatch action and
     * Sales does not (DEC-20260901-001, resolving Q78). Both teams therefore
     * need to READ deliveries — Sales to trace what left against its orders,
     * the Store to see what it dispatched — so the read is
     * `module:sales,inventory` while the POST is `module:inventory` alone.
     *
     * Skipping a URI here weakens the wall, so each one is re-asserted
     * positively below rather than merely excused.
     *
     * @var array<string, string>
     */
    private const SHARED_READS = [
        'sales/deliveries' => 'inventory',
    ];

    // ---- the permission wall -----------------------------------------------

    #[DataProvider('modules')]
    public function test_a_reader_holding_every_other_permission_is_refused_by_the_module(string $module): void
    {
        [$permissionModule, $lists] = self::MODULES[$module];
        Sanctum::actingAs($this->userHoldingEverythingExcept(["{$permissionModule}.view", "{$permissionModule}.manage"]));
        config(['production.traceability_enabled' => true]);

        $notRefused = [];
        foreach (array_keys($lists) as $uri) {
            if (isset(self::SHARED_READS[$uri])) {
                continue;
            }

            $status = $this->getJson('/api/v1/'.$uri)->getStatusCode();
            if ($status !== 403) {
                $notRefused[] = "{$uri} => {$status}";
            }
        }

        $this->assertSame([], $notRefused, "{$module}: not refused without {$permissionModule}.view/.manage: ".implode(', ', $notRefused));
    }

    /**
     * The other half of SHARED_READS: each skipped URI really IS readable by
     * the module it was skipped for, and really is refused to someone holding
     * neither. Without this, adding a URI to that list would be a way to
     * switch a permission wall off silently.
     */
    public function test_a_deliberately_shared_read_is_open_to_its_other_module_and_shut_to_everyone_else(): void
    {
        foreach (self::SHARED_READS as $uri => $otherModule) {
            Sanctum::actingAs($this->userHolding(["{$otherModule}.view"], 'Regression Sharer'));
            $this->getJson('/api/v1/'.$uri)->assertOk();

            Sanctum::actingAs($this->userHolding(['maintenance.view'], 'Regression Outsider'));
            $this->getJson('/api/v1/'.$uri)->assertForbidden();
        }
    }

    public function test_each_report_is_refused_without_its_own_module_permission(): void
    {
        $notRefused = [];
        config(['production.traceability_enabled' => true]);

        foreach (self::REPORTS as $uri => [$permissionModule]) {
            Sanctum::actingAs($this->userHoldingEverythingExcept(["{$permissionModule}.view", "{$permissionModule}.manage"], "Almost-admin minus {$permissionModule}"));
            $status = $this->getJson('/api/v1/'.$uri)->getStatusCode();
            if ($status !== 403) {
                $notRefused[] = "{$uri} => {$status}";
            }
        }

        $this->assertSame([], $notRefused, implode(', ', $notRefused));
    }

    // ---- helpers -----------------------------------------------------------

    /** null when the answer has the shape the table promises; else the sentence naming what is off. */
    private function shapeProblem(int $status, mixed $json, string $shape): ?string
    {
        if ($status !== 200) {
            $message = is_array($json) ? ($json['message'] ?? '') : '';

            return "status {$status} {$message}";
        }
        if ($shape === 'B') {
            return is_array($json) && $json !== [] ? null : 'not an object';
        }
        if (! is_array($json) || ! array_key_exists('data', $json)) {
            return 'no `data` key';
        }

        return match ($shape) {
            'P', 'T' => $this->rowsProblem($json) ?? (array_key_exists('meta', $json) ? null : 'no `meta` on a paginated list'),
            'R' => $this->rowsProblem($json) ?? (array_key_exists('current_page', $json) ? null : 'no `current_page` on the raw paginator'),
            'L' => $this->rowsProblem($json),
            'O' => null,
            default => "unknown shape {$shape}",
        };
    }

    private function rowsProblem(array $json): ?string
    {
        if (! is_array($json['data'])) {
            return '`data` is not a list';
        }
        if (count($json['data']) === 0) {
            return 'the seeded row is missing (`data` is empty)';
        }

        return null;
    }
}
