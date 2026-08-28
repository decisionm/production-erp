<?php

namespace Tests\Feature\Regression;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Regression\Support\RegressionFixtures;
use Tests\TestCase;

/**
 * Phase 7 regression smoke (P7-05, WS-E) — the WHOLE read surface of
 * /api/v1, walked from the route table itself so a route added tomorrow is
 * in the walk the day it lands.
 *
 * Three questions, asked of every GET route:
 *
 *   1. Unauthenticated → 401. There is no public GET; the one deliberately
 *      public route is POST /auth/login. If a GET is ever meant to be
 *      public it is listed in PUBLIC_GETS by hand, with the reason.
 *   2. An Administrator holding every catalogue permission → 2xx, or a
 *      422/409 that says what is missing (a report without its date range,
 *      a preview without its item, the CEC's blocked format) — NEVER a
 *      5xx. The two answers that are neither are DOCUMENTED here:
 *      tally-sync/pending is agent-only (403 to any browser session), and
 *      the traceability-gated routes are 404 while the flag is off (the
 *      design says "with the flag off the feature does not exist"). Both
 *      passes run: an empty database (nothing to render) and a database
 *      with one row in every module (every resource's toArray runs).
 *   3. Every PARAMETERISED GET is either read against a fixture (all 31 as
 *      of Phase 7 — item, batch, serial, lot, PO, GRN, SO, delivery,
 *      invoice, lead, quotation, instrument, SPC characteristic, sync
 *      entry, stock snapshot, payslip, entry, carton, machine, standard,
 *      product) or NAMED in SKIPPED with the reason. A new parameterised
 *      route that is in neither list fails the pin.
 *
 * A failure prints the route, the status and — for a 5xx — the exception's
 * first line, so the report can name it. This test never fixes production
 * code; it exposes.
 */
class ApiSurfaceSmokeTest extends TestCase
{
    use RefreshDatabase, RegressionFixtures;

    /** GET routes that are meant to answer WITHOUT a session. None today. */
    private const PUBLIC_GETS = [];

    /**
     * Routes whose non-2xx answer to a fully-permitted administrator is the
     * documented behaviour, uri => [status, why].
     */
    private const DOCUMENTED = [
        'api/v1/tally-sync/pending' => [403, 'agent-only: only a real agent token with the tally-sync:poll ability may collect vouchers'],
    ];

    /** The traceability gate's answer while the flag is off. */
    private const FLAG_OFF_STATUS = 404;

    /** Parameterised GET routes deliberately not read here, uri => why. */
    private const SKIPPED = [
        // Answers 404 until a scan is attached, and attaching one here would
        // write to the REAL local disk (this trait does not fake Storage).
        // The download is exercised end-to-end in SupplierBillTest with a
        // faked disk.
        'api/v1/procurement/supplier-bills/{supplier_bill}/attachment' => 'no attachment on the fixture bill; covered by SupplierBillTest',
    ];

    /**
     * Parameterised GETs whose answer for an id that does not exist is, by
     * design, not a 404 — uri => [status, why]. Everything else must 404.
     */
    private const MISSING_ID_ANSWERS = [
        // FinishedCartonService::lookup throws a ValidationException keyed on
        // carton_no so a scanner shows "No carton carries the code …" in
        // place, the way a mistyped scan should read.
        'api/v1/production/cartons/{cartonNo}' => [422, 'scan lookup answers a validation message on carton_no'],
        'api/v1/production/cartons/{cartonNo}/trace' => [422, 'the internal trace tier answers the same validation message'],
        // forMachine(int $workCenter) is not model-bound: an unknown machine
        // has no approved configurations, so the list is empty and 200.
        // Recorded here as an observation, not corrected by this smoke.
        'api/v1/production/work-centers/{work_center}/configurations' => [200, 'not model-bound; an unknown machine has an empty configuration list'],
    ];

    /** The floor under the walk — a route table that shrank below this is a broken walk, not a quiet app. */
    private const MINIMUM_PARAMETERLESS_GETS = 90;

    // ---- 1. unauthenticated ------------------------------------------------

    public function test_every_parameterless_get_is_401_without_a_session(): void
    {
        $routes = $this->parameterlessGets();
        $this->assertGreaterThanOrEqual(self::MINIMUM_PARAMETERLESS_GETS, count($routes));

        $wrong = [];
        foreach ($routes as $uri => $route) {
            $status = $this->getJson('/'.$uri)->getStatusCode();
            $expected = array_key_exists($uri, self::PUBLIC_GETS) ? 200 : 401;
            if ($status !== $expected) {
                $wrong[] = "{$uri} => {$status} (expected {$expected})";
            }
        }

        $this->assertSame([], $wrong, "GET routes answering something other than 401 to a stranger:\n".implode("\n", $wrong));
    }

    // ---- 2. an administrator, never a 5xx ----------------------------------

    public function test_every_parameterless_get_answers_an_administrator_without_a_5xx_on_an_empty_database(): void
    {
        $this->actAsAdministrator();
        $routes = $this->parameterlessGets();

        // Flag off: the gated routes must be the documented 404, nothing else.
        config(['production.traceability_enabled' => false]);
        $failures = $this->walk($routes, flagOn: false);

        // Flag on: the gated routes answer for real.
        config(['production.traceability_enabled' => true]);
        $failures = [...$failures, ...$this->walk($routes, flagOn: true)];

        $this->assertSame([], $failures, "Empty database:\n".implode("\n", $failures));
    }

    public function test_every_parameterless_get_answers_an_administrator_without_a_5xx_with_every_module_seeded(): void
    {
        $admin = $this->actAsAdministrator();
        $this->seedEveryModule($admin);
        $routes = $this->parameterlessGets();

        config(['production.traceability_enabled' => false]);
        $failures = $this->walk($routes, flagOn: false);

        config(['production.traceability_enabled' => true]);
        $failures = [...$failures, ...$this->walk($routes, flagOn: true)];

        $this->assertSame([], $failures, "Seeded database:\n".implode("\n", $failures));
    }

    // ---- 3. the parameterised reads ----------------------------------------

    public function test_every_parameterised_get_is_read_against_a_fixture_or_named_as_skipped(): void
    {
        $admin = $this->actAsAdministrator();
        $fx = $this->seedEveryModule($admin);
        config(['production.traceability_enabled' => true]);

        $covered = $this->coveredParameterisedGets($fx);
        $routes = $this->parameterisedGets();

        // The pin: every parameterised GET in the app is classified.
        $unclassified = array_values(array_diff(array_keys($routes), array_keys($covered), array_keys(self::SKIPPED)));
        $this->assertSame([], $unclassified, "Parameterised GET routes neither read here nor named in SKIPPED:\n".implode("\n", $unclassified));
        // And nothing is classified that no longer exists.
        $stale = array_values(array_diff([...array_keys($covered), ...array_keys(self::SKIPPED)], array_keys($routes)));
        $this->assertSame([], $stale, "Classified routes that no longer exist:\n".implode("\n", $stale));

        $failures = [];
        foreach ($covered as $uri => $url) {
            $response = $this->getJson($url);
            $problem = $this->problem($response, allowed: [422, 409], documented: null);
            if ($problem !== null) {
                $failures[] = "{$uri} ({$url}): {$problem}";
            }
        }

        $this->assertSame([], $failures, "Parameterised reads:\n".implode("\n", $failures));
    }

    public function test_a_parameterised_get_for_a_missing_id_is_a_404_not_a_5xx(): void
    {
        $this->actAsAdministrator();
        config(['production.traceability_enabled' => true]);

        $failures = [];
        foreach ($this->parameterisedGets() as $uri => $route) {
            $url = '/'.preg_replace('/\{[^}]+\}/', '999999', $uri);
            $status = $this->getJson($url)->getStatusCode();
            $expected = self::MISSING_ID_ANSWERS[$uri][0] ?? 404;
            if ($status !== $expected) {
                $failures[] = "{$uri} => {$status} (expected {$expected})";
            }
        }

        $this->assertSame([], $failures, "Missing-id reads answering something other than the 404 (or their documented status):\n".implode("\n", $failures));
    }

    // ---- the walk ------------------------------------------------------------

    /**
     * @param  array<string, RouteDefinition>  $routes
     * @return list<string>
     */
    private function walk(array $routes, bool $flagOn): array
    {
        $failures = [];
        foreach ($routes as $uri => $route) {
            $documented = self::DOCUMENTED[$uri][0] ?? null;
            if (! $flagOn && $this->isTraceabilityGated($route)) {
                $documented = self::FLAG_OFF_STATUS;
            }
            $problem = $this->problem($this->getJson('/'.$uri), allowed: [422, 409], documented: $documented);
            if ($problem !== null) {
                $failures[] = "{$uri} [flag ".($flagOn ? 'on' : 'off')."]: {$problem}";
            }
        }

        return $failures;
    }

    /**
     * null when the answer is acceptable: 2xx, one of the allowed
     * validation/blocked answers, or exactly the documented status; else
     * a sentence with the status and, for a 5xx, the exception's first line.
     *
     * @param  list<int>  $allowed
     */
    private function problem(TestResponse $response, array $allowed, ?int $documented): ?string
    {
        $status = $response->getStatusCode();

        if ($documented !== null) {
            return $status === $documented ? null : "expected the documented {$documented}, got {$status}";
        }
        if ($status >= 200 && $status < 300) {
            return null;
        }
        if (in_array($status, $allowed, true)) {
            return null;
        }

        $json = $response->json();
        $message = is_array($json) ? (string) ($json['message'] ?? '') : '';
        $where = is_array($json) && isset($json['exception'])
            ? " {$json['exception']} at ".basename((string) ($json['file'] ?? '?')).':'.($json['line'] ?? '?')
            : '';

        return "{$status}{$where} {$message}";
    }

    private function isTraceabilityGated(RouteDefinition $route): bool
    {
        return in_array('traceability', $route->gatherMiddleware(), true);
    }

    /** @return array<string, RouteDefinition> uri => route, for GET routes with no `{parameter}` */
    private function parameterlessGets(): array
    {
        return array_filter($this->gets(), fn (string $uri) => ! str_contains($uri, '{'), ARRAY_FILTER_USE_KEY);
    }

    /** @return array<string, RouteDefinition> uri => route, for GET routes with a `{parameter}` */
    private function parameterisedGets(): array
    {
        return array_filter($this->gets(), fn (string $uri) => str_contains($uri, '{'), ARRAY_FILTER_USE_KEY);
    }

    /** @return array<string, RouteDefinition> */
    private function gets(): array
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            /** @var RouteDefinition $route */
            if (str_starts_with($route->uri(), 'api/v1/') && in_array('GET', $route->methods(), true)) {
                $routes[$route->uri()] = $route;
            }
        }
        ksort($routes);

        return $routes;
    }

    /**
     * Every parameterised GET, resolved against the fixtures — uri => URL.
     *
     * @param  array<string, mixed>  $fx
     * @return array<string, string>
     */
    private function coveredParameterisedGets(array $fx): array
    {
        $entry = $fx['entry']->id;

        return [
            'api/v1/compliance/invoices/{invoice}/gst-breakdown' => "/api/v1/compliance/invoices/{$fx['invoice']->id}/gst-breakdown",
            'api/v1/crm/leads/{lead}/activities' => "/api/v1/crm/leads/{$fx['lead']->id}/activities",
            'api/v1/crm/quotations/{quotation}/pdf' => "/api/v1/crm/quotations/{$fx['quotation']->id}/pdf",
            'api/v1/hrms/employees/{employee}' => "/api/v1/hrms/employees/{$fx['employee']->id}",
            'api/v1/inventory/batches/{batch}/ledger' => "/api/v1/inventory/batches/{$fx['batch']->id}/ledger",
            'api/v1/inventory/items/{item}' => "/api/v1/inventory/items/{$fx['bottle']->id}",
            'api/v1/inventory/material-requests/{material_request}' => "/api/v1/inventory/material-requests/{$fx['materialRequest']->id}",
            'api/v1/inventory/store-issues/{store_issue}' => "/api/v1/inventory/store-issues/{$fx['storeIssue']->id}",
            'api/v1/inventory/material-lots/{lot}/cost-versions' => "/api/v1/inventory/material-lots/{$fx['lot']->id}/cost-versions",
            'api/v1/inventory/material-lots/{material_lot}' => "/api/v1/inventory/material-lots/{$fx['lot']->id}",
            'api/v1/inventory/serial-numbers/{serial_number}/history' => "/api/v1/inventory/serial-numbers/{$fx['serial']->id}/history",
            // The configuration lifecycle's authoritative read — what the
            // delete confirm dialog fetches before it offers the button.
            'api/v1/inventory/warehouses/{warehouse}' => "/api/v1/inventory/warehouses/{$fx['fg']->id}",
            'api/v1/payroll/payslips/{payslip}' => "/api/v1/payroll/payslips/{$fx['payslip']->id}",
            'api/v1/procurement/goods-receipts/{goods_receipt}' => "/api/v1/procurement/goods-receipts/{$fx['grn']->id}",
            'api/v1/procurement/supplier-bills/{supplier_bill}' => "/api/v1/procurement/supplier-bills/{$fx['bill']->id}",
            // Vendor and Customer joined the Configuration Lifecycle Contract
            // on 20-Aug; their show() is what the delete confirm dialog reads
            // for the authoritative `can` block.
            'api/v1/procurement/vendors/{vendor}' => "/api/v1/procurement/vendors/{$fx['vendor']->id}",
            'api/v1/sales/customers/{customer}' => "/api/v1/sales/customers/{$fx['customer']->id}",
            'api/v1/procurement/purchase-orders/{purchase_order}' => "/api/v1/procurement/purchase-orders/{$fx['po']->id}",
            'api/v1/procurement/purchase-orders/{purchase_order}/trace' => "/api/v1/procurement/purchase-orders/{$fx['po']->id}/trace",
            'api/v1/production/cartons/{cartonNo}' => "/api/v1/production/cartons/{$fx['cartonNo']}",
            'api/v1/production/cartons/{cartonNo}/trace' => "/api/v1/production/cartons/{$fx['cartonNo']}/trace",
            'api/v1/production/configurations/{production_configuration}' => "/api/v1/production/configurations/{$fx['configuration']->id}",
            'api/v1/production/downtime-reasons/{downtime_reason}' => "/api/v1/production/downtime-reasons/{$fx['downtimeReason']->id}",
            'api/v1/production/molds/{mold}' => "/api/v1/production/molds/{$fx['mold']->id}",
            'api/v1/production/products/{item}/variants' => "/api/v1/production/products/{$fx['bottle']->id}/variants",
            'api/v1/production/scrap-reasons/{scrap_reason}' => "/api/v1/production/scrap-reasons/{$fx['scrapReason']->id}",
            'api/v1/production/shift-production-entries/{shift_production_entry}/cartons' => "/api/v1/production/shift-production-entries/{$entry}/cartons",
            'api/v1/production/shift-production-entries/{shift_production_entry}/day-bin' => "/api/v1/production/shift-production-entries/{$entry}/day-bin",
            'api/v1/production/shift-production-entries/{shift_production_entry}/voucher-preview' => "/api/v1/production/shift-production-entries/{$entry}/voucher-preview",
            'api/v1/production/shifts/{shift}' => "/api/v1/production/shifts/{$fx['shift']->id}",
            'api/v1/production/standards/{standard}' => "/api/v1/production/standards/{$fx['standard']->id}",
            'api/v1/production/standards/{standard}/packagings/{packaging}' => "/api/v1/production/standards/{$fx['standard']->id}/packagings/{$fx['packaging']->id}",
            'api/v1/production/standards/{standard}/item-candidates' => "/api/v1/production/standards/{$fx['standard']->id}/item-candidates",
            'api/v1/production/standards/{standard}/machine-exceptions' => "/api/v1/production/standards/{$fx['standard']->id}/machine-exceptions",
            'api/v1/production/work-centers/{work_center}' => "/api/v1/production/work-centers/{$fx['machine']->id}",
            'api/v1/production/work-centers/{work_center}/configurations' => "/api/v1/production/work-centers/{$fx['machine']->id}/configurations",
            'api/v1/production/work-centers/{work_center}/day-bin' => "/api/v1/production/work-centers/{$fx['machine']->id}/day-bin",
            'api/v1/quality/instruments/{instrument}/calibrations' => "/api/v1/quality/instruments/{$fx['instrument']->id}/calibrations",
            'api/v1/quality/spc-characteristics/{spc_characteristic}/chart' => "/api/v1/quality/spc-characteristics/{$fx['characteristic']->id}/chart",
            'api/v1/quality/spc-characteristics/{spc_characteristic}/measurements' => "/api/v1/quality/spc-characteristics/{$fx['characteristic']->id}/measurements",
            'api/v1/sales/deliveries/{delivery}' => "/api/v1/sales/deliveries/{$fx['delivery']->id}",
            'api/v1/sales/invoices/{invoice}' => "/api/v1/sales/invoices/{$fx['invoice']->id}",
            'api/v1/sales/sales-orders/{sales_order}' => "/api/v1/sales/sales-orders/{$fx['order']->id}",
            'api/v1/sales/sales-orders/{sales_order}/cost-insight' => "/api/v1/sales/sales-orders/{$fx['order']->id}/cost-insight",
            'api/v1/tally-sync/entries/{tally_sync_entry}' => "/api/v1/tally-sync/entries/{$fx['syncEntry']->id}",
            'api/v1/tally-sync/stock-snapshots/{tally_stock_snapshot}' => "/api/v1/tally-sync/stock-snapshots/{$fx['snapshot']->id}",
        ];
    }
}
