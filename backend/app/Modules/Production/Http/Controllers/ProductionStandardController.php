<?php

namespace App\Modules\Production\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Http\Controllers\Concerns\ManagesConfigurationRecords;
use App\Modules\Production\Http\Requests\AttachProductionStandardItemRequest;
use App\Modules\Production\Http\Requests\ImportProductionStandardsRequest;
use App\Modules\Production\Http\Requests\IndexProductionStandardsRequest;
use App\Modules\Production\Http\Requests\StoreProductionStandardRequest;
use App\Modules\Production\Http\Resources\ProductionConfigurationResource;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Services\ProductionConfigurationService;
use App\Modules\Production\Services\ProductionStandardImportService;
use App\Modules\Production\Services\ProductionStandardService;
use App\Modules\Production\Services\ProductStandardsWorkspaceService;
use App\Support\Configuration\Http\ConfigurationReasonRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProductionStandardController extends Controller
{
    use ManagesConfigurationRecords;

    protected function configurationWritePermission(): string
    {
        return 'production.manage';
    }

    protected function configurationNoun(): string
    {
        return 'production standard';
    }

    public function __construct(
        private readonly ProductionStandardImportService $import,
        private readonly ProductionStandardService $standards,
        private readonly ProductStandardsWorkspaceService $workspace,
        private readonly ProductionConfigurationService $configurations,
    ) {}

    /**
     * The product-configuration workspace, one page at a time.
     *
     * Still a RAW Laravel paginator on the wire (`data` beside `total` and
     * `current_page`, no `meta` envelope) because that is what this endpoint
     * has always returned and the reader normalises it; `summary` is added
     * alongside for the header's chips. Every key a row carried before is
     * still on it — the workspace fields are additions, not a replacement.
     *
     * The default view is production-READY. That is a deliberate change of
     * behaviour: this endpoint used to hand back everything, and the page
     * sorted it out client-side after fetching 200 rows. A caller that wants
     * the old answer asks for `view=all`.
     */
    public function index(IndexProductionStandardsRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $result = $this->workspace->workspace(
            $filters,
            $this->workspace->resolvePerPage($request->query('per_page', 25)),
            max(1, (int) $request->query('page', 1)),
            $filters['sort'] ?? null,
        );

        // The row's `can` came out of the workspace as the RECORD's
        // eligibility. It is intersected with this user's grant here, for the
        // same reason show() does it: reading this list needs
        // `production.view` and every write behind those buttons needs
        // `production.manage`, so a read-only supervisor handed `edit: true`
        // would get a 403 from each one — and WS-4's row actions read this
        // block rather than re-deriving it.
        if (! $this->mayWrite($request)) {
            $result['page']->setCollection(
                $result['page']->getCollection()->map(fn (array $row): array => [
                    ...$row,
                    'can' => ['edit' => false, 'activate' => false, 'archive' => false, 'delete' => false],
                ]),
            );
        }

        return response()->json($result['page']->toArray() + [
            'summary' => $result['summary'],
            'configuration_overlaps' => $result['configuration_overlaps'],
            // Finished goods no standard covers, for this search. Alongside
            // the page rather than in it — see the service's own note on why
            // they are never paginated together.
            'unconfigured_items' => $result['unconfigured_items'],
        ]);
    }

    /**
     * The machine exceptions on one product — the workspace's expanded row.
     *
     * A convenience over the configurations the exceptions page already
     * serves, not a second source: it calls the same service, returns the
     * same resource, and every write (create, approve, deactivate, copy)
     * still happens through the configuration endpoints. Nothing about the
     * approval flow changes because the list can now be reached by product.
     *
     * A standard with no item attached has no exceptions by construction —
     * configurations are keyed on the item — so it answers with an empty
     * list rather than an error the page would have to special-case.
     */
    public function machineExceptions(ProductionStandard $standard): AnonymousResourceCollection
    {
        return ProductionConfigurationResource::collection(
            $standard->item_id === null
                ? collect()
                : $this->configurations->forItem($standard->item_id),
        );
    }

    /**
     * Standards coverage: which products the factory master actually
     * configures, and under which product name it configures them.
     *
     * The thinnest possible projection of the same data `index()` returns —
     * two scalar columns, no eager loads, no pagination — because its only
     * consumer is a picker that has to sort ~80 items into "set up" and "not
     * set up" before the supervisor's first keystroke. Fetching the real
     * standards (90 rows, each with its item and its packagings) to answer a
     * yes/no question would be several hundred kilobytes for one bit each.
     *
     * `source_product_name` rides along because the picker needs one more
     * thing: when a supervisor lands on an unconfigured legacy item, it can
     * offer the configured product of the SAME name as a replacement. That
     * offer is only ever made on an exact match after normalisation —
     * suggesting a near-miss would put the wrong product on the machine.
     *
     * No status filter, deliberately: an "unresolved" variant (two candidate
     * cycle times pending a factory answer) is still a configured product,
     * and Start Batch already shows those variants and lets the supervisor
     * choose. Filtering them out here would tell the picker a product is
     * unconfigured while the very next screen offers its standards.
     */
    public function coverage(): JsonResponse
    {
        // One query, two columns. Matched standards only — an unmatched one
        // names a product that has no item to pick in the first place.
        $rows = ProductionStandard::query()
            ->whereNotNull('item_id')
            ->orderBy('item_id')
            ->get(['item_id', 'source_product_name'])
            ->map(fn (ProductionStandard $standard) => [
                'item_id' => $standard->item_id,
                'source_product_name' => $standard->source_product_name,
            ])
            // Sibling variants of one product repeat the pair; the picker
            // only ever asks "does this exist", so send it once.
            ->unique(fn (array $row) => $row['item_id'].'|'.$row['source_product_name'])
            ->values();

        return response()->json(['data' => $rows]);
    }

    /**
     * The Tally items this standard's factory product name most resembles.
     *
     * 62 standards carry no item, and the page has to offer a way to finish
     * that job without a re-import. The ranking is the import diagnostic's own
     * — same scoring, same size-first ordering — so the app and the console
     * never disagree about the same question.
     *
     * `attached_to_same_product` is the one thing the ranking cannot know and
     * the person choosing needs: whether another standard of THIS product name
     * already points at that item. It is not a refusal — a mould covers every
     * colour of its bottle, and two variants legitimately share an item — but
     * seeing it is how a supervisor tells "the sibling variant" from "I am
     * about to pick the wrong bottle".
     */
    public function itemCandidates(ProductionStandard $standard): JsonResponse
    {
        $candidates = $this->import->itemCandidates((string) $standard->source_product_name);

        $takenByThisProduct = ProductionStandard::query()
            ->where('source_product_name', $standard->source_product_name)
            ->whereKeyNot($standard->getKey())
            ->whereNotNull('item_id')
            ->pluck('item_id')
            ->all();

        return response()->json([
            'data' => [
                'standard_id' => $standard->id,
                'source_product_name' => $standard->source_product_name,
                'candidates' => array_map(fn (array $candidate) => $candidate + [
                    'attached_to_same_product' => in_array($candidate['id'], $takenByThisProduct, true),
                ], $candidates),
            ],
        ]);
    }

    /**
     * Attach a Tally item to a standard — or, as an explicit confirmed
     * correction, re-point one that is already attached (DEC-20260810-003:
     * the identity is editable configuration; future runs only, history and
     * posted vouchers untouched).
     *
     * No detach counterpart, deliberately. Detaching would leave a row the
     * importer then treats as unmatched, and its orphan-adoption rule would
     * re-attach it on the next import of the workbook — so a detach would
     * appear to work and then silently undo itself. Until there is a way to
     * record "this row is deliberately unattached" that survives an import,
     * offering the button would be offering a lie.
     */
    public function attachItem(AttachProductionStandardItemRequest $request, ProductionStandard $standard): JsonResponse
    {
        $standard = $this->standards->attachItem(
            $standard,
            (int) $request->validated()['item_id'],
            $request->user(),
            (bool) ($request->validated()['confirm_reattach'] ?? false),
        );

        return response()->json(['data' => $standard]);
    }

    /**
     * Add a standard for a product the workbook never carried.
     */
    public function store(StoreProductionStandardRequest $request): JsonResponse
    {
        $standard = $this->standards->create($request->validated(), $request->user());

        return response()->json(['data' => $standard], 201);
    }

    /**
     * One standard, ARCHIVED ROWS INCLUDED, with the authoritative `can` —
     * the Configuration Lifecycle Contract's View. An archived standard is
     * reachable here on purpose: history has to stay readable, and Activate
     * could not undo an Archive otherwise.
     */
    public function show(int $standard): JsonResponse
    {
        $found = $this->resolve($standard);

        $this->withAbilities(request(), $this->standards, $found);

        return response()->json(['data' => $found->toArray() + [
            'is_archived' => $found->trashed(),
            'can' => $found->can,
        ]]);
    }

    /**
     * Take the standard out of service. It keeps its variant identity —
     * (item, product name, cavities, weight, cycle time) stays reserved, so
     * nobody may add a second copy of it (DEC-20260817-002 §2) — and nothing
     * is destroyed or posted to Tally.
     */
    public function archive(ConfigurationReasonRequest $request, int $standard): JsonResponse
    {
        $this->archiveRecord($this->standards, $this->resolve($standard), $request->reason());

        return $this->show($standard);
    }

    public function activate(ConfigurationReasonRequest $request, int $standard): JsonResponse
    {
        $this->activateRecord($this->standards, $this->resolve($standard), $request->reason());

        return $this->show($standard);
    }

    /**
     * Hard delete — Super Admin / Owner only, and only for a standard no
     * shift ever ran to and no packaging variant hangs off. Every refusal is
     * the shared 422 with counts and an Archive offer.
     */
    public function destroy(Request $request, int $standard): Response
    {
        $this->standards->delete($this->resolve($standard), $request->user());

        return response()->noContent();
    }

    private function resolve(int $standard): ProductionStandard
    {
        return ProductionStandard::withTrashed()
            ->with(['item', 'packagings'])
            ->find($standard) ?? abort(404);
    }

    /**
     * Import factory product standards. Dry run unless `dry_run=false`.
     */
    public function import(ImportProductionStandardsRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'data' => $this->import->import(
                $data['rows'],
                $data['dry_run'] ?? true,
                $request->user()?->id,
                $data['exact_only'] ?? true,
            ),
        ]);
    }
}
