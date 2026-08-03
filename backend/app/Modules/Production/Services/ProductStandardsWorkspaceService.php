<?php

namespace App\Modules\Production\Services;

use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\ProductionConfiguration;
use App\Modules\Production\Models\ProductionStandard;
use App\Modules\Production\Models\WorkCenter;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * The Product Standards workspace read: one row per standard carrying
 * everything the single product-configuration screen shows — the figures, the
 * packing, the active recipe, the Tally identity, the machine exceptions, and
 * the NUMBERED gaps that stand between this product and a shift.
 *
 * Why one endpoint rather than the page fetching five: the page's whole
 * purpose is to answer "can this product run, and if not, what exactly is
 * missing and where do I go?" for eighty products at a glance. Answered from
 * five endpoints, that question costs eighty round trips or a client-side
 * join, and the version the supervisor reads drifts from the version the
 * Start Batch gate enforces. Answered here, the gap list on the screen is
 * literally the gate's own vocabulary (ProductReadinessService::SENTENCES),
 * computed by the gate's own service.
 *
 * PAGINATION IS REAL. The rows the client receives are one page of them, the
 * total is the real total, and the pager works. The gap computation itself
 * cannot be pushed into SQL — it reads the standard-outranks-item precedence
 * and the resolved packaging option, and an SQL restatement of those rules
 * would be a second implementation to keep in step — so the filtered set is
 * assessed in PHP once and that single pass serves both the page slice and
 * the header's ready/incomplete counts. At this factory's scale (about a
 * hundred standards) that is a few milliseconds; if this master ever grows by
 * an order of magnitude, the pass is the thing to revisit, not the endpoint.
 */
class ProductStandardsWorkspaceService
{
    /** The page sizes the workspace offers. */
    public const PER_PAGE_OPTIONS = [25, 50, 100];

    public const VIEW_READY = 'ready';

    public const VIEW_INCOMPLETE = 'incomplete';

    public const VIEW_ALL = 'all';

    public function __construct(
        private readonly ProductReadinessService $readiness,
        private readonly ProductionStandardResolver $resolver,
        private readonly ProductionConfigurationService $configurations,
    ) {}

    /**
     * A requested page size reduced to one the workspace offers — never
     * refused. A stale browser tab still asking for 200 rows is a live
     * factory's screen, and answering it with a 422 turns a page-size
     * mismatch into a blank product master. 200 becomes 100, 10 becomes 25,
     * and the paginator reports the size it actually used.
     */
    public function resolvePerPage(mixed $requested): int
    {
        $requested = (int) $requested;

        $allowed = array_filter(self::PER_PAGE_OPTIONS, fn (int $option) => $option <= $requested);

        return $allowed === [] ? self::PER_PAGE_OPTIONS[0] : max($allowed);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{page: LengthAwarePaginator, summary: array{ready: int, incomplete: int, all: int}}
     */
    public function workspace(array $filters = [], int $perPage = 25, int $page = 1): array
    {
        $assessed = $this->assessedRows($filters);

        // The chips count the filtered set MINUS the view, so switching to
        // Incomplete does not rewrite the number that told you to switch.
        $summary = [
            'ready' => $assessed->where('ready', true)->count(),
            'incomplete' => $assessed->where('ready', false)->count(),
            'all' => $assessed->count(),
        ];

        $view = $this->view($filters['view'] ?? null);

        $rows = (match ($view) {
            self::VIEW_READY => $assessed->where('ready', true),
            self::VIEW_INCOMPLETE => $assessed->where('ready', false),
            default => $assessed,
        })->values();

        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values()->all(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );

        // The page links carry the filters. A "next page" that silently drops
        // the view and the search is a pager that walks you out of the screen
        // you were working in.
        return [
            'page' => $paginator->withQueryString(),
            'summary' => $summary,
            // Overlapping approved configurations, surfaced on the page that
            // owns them. Not a block and not a per-row flag: it is one banner
            // saying "these products have two live machine settings that
            // disagree, and one of them is wrong". Before this, the resolver
            // simply picked one and no screen anywhere admitted a choice had
            // been made.
            'configuration_overlaps' => $this->configurations->overlappingApproved(),
        ];
    }

    private function view(mixed $requested): string
    {
        $view = is_string($requested) ? strtolower(trim($requested)) : '';

        return in_array($view, [self::VIEW_READY, self::VIEW_INCOMPLETE, self::VIEW_ALL], true)
            ? $view
            // Production-ready is the default view because it answers the
            // question the floor asks ("what can I run?"); Incomplete is the
            // question the office asks, and it is one click away.
            : self::VIEW_READY;
    }

    /**
     * Every standard matching the database-expressible filters, each one
     * turned into a workspace row with its gaps already computed.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function assessedRows(array $filters): Collection
    {
        $standards = $this->filtered($filters);

        $itemIds = $standards->pluck('item_id')->filter()->unique()->values()->all();

        $exceptions = $this->exceptionsByItem($itemIds);
        $recipes = $this->activeRecipesByItem($itemIds);

        return $standards->map(function (ProductionStandard $standard) use ($exceptions, $recipes) {
            $item = $standard->item;
            $packaging = $this->resolver->resolvePackaging($standard);
            $gaps = $this->readiness->configurationGaps($item, $standard, $packaging);

            // toArray() first, so every key this endpoint already returned —
            // the standard's own columns, its item, its packagings — is still
            // there byte for byte and the workspace keys are additions.
            return $standard->toArray() + [
                'gaps' => $gaps,
                'ready' => $gaps === [],
                'resolved_packaging_id' => $packaging?->id,
                'machine_exceptions' => $exceptions[$standard->item_id] ?? [],
                'active_recipe' => $recipes[$standard->item_id] ?? null,
                'tally' => $this->tally($standard),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return EloquentCollection<int, ProductionStandard>
     */
    private function filtered(array $filters): EloquentCollection
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return ProductionStandard::query()
            ->with(['item', 'packagings'])
            // The three filters the old index already honoured. Kept working
            // unchanged: the Start Batch "configure this product" return link
            // arrives carrying item_id, and a supervisor sent back from a
            // half-started batch must land on the row they were sent for.
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['item_id'] ?? null, fn ($q, $v) => $q->where('item_id', $v))
            ->when(! empty($filters['matched_only']), fn ($q) => $q->whereNotNull('item_id'))
            // One box, both names: the factory calls a product "90ML RIB" and
            // Tally calls it "B.90ml Rib Pet Bottle Clear-8.5gms". Searching
            // only one of them means half the factory cannot find anything.
            ->when($search !== '', fn ($q) => $q->where(fn ($sub) => $sub
                ->where('source_product_name', 'like', "%{$search}%")
                ->orWhereHas('item', fn ($iq) => $iq
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%"))))
            // Standards with no Tally identity: either no item at all, or an
            // item Tally has never heard of. Both are the same job.
            ->when(! empty($filters['missing_tally']), fn ($q) => $q->where(fn ($sub) => $sub
                ->whereNull('item_id')
                ->orWhereHas('item', fn ($iq) => $iq->whereNull('tally_stock_item_guid'))))
            ->when($filters['packing_mode'] ?? null, fn ($q, $v) => $q->whereHas(
                'packagings',
                fn ($pq) => $pq->where('mode', $v),
            ))
            ->when(
                $filters['work_center_id'] ?? null,
                fn ($q, $v) => $this->constrainToMachine($q, (int) $v),
            )
            ->orderBy('source_product_name')
            ->orderBy('cavities')
            // The tiebreaker that makes paging stable: two variants of one
            // product with the same cavity count are otherwise ordered by
            // whatever the database feels like, and a row can appear on two
            // pages or on none.
            ->orderBy('id')
            ->get();
    }

    /**
     * Standards whose cavity count this machine can actually run.
     *
     * Read in PHP rather than SQL because `permitted_cavities` is a JSON
     * column and the JSON predicates differ between sqlite (tests) and MySQL
     * (the factory) — the one place where a query that passes here would fail
     * there.
     *
     * A machine with NO stated limits excludes nothing, which is every
     * machine today: the factory's workbook leaves every cavity field empty,
     * and a filter that answered "nothing fits MC-04" because MC-04's limits
     * are unknown would read as a broken machine. By the same rule a standard
     * with no cavity count is not excluded either — its cavities are a gap to
     * be filled, not a reason to hide it from the machine it will run on.
     */
    private function constrainToMachine($query, int $workCenterId)
    {
        $machine = WorkCenter::query()->find($workCenterId);

        if ($machine === null) {
            return $query;
        }

        $permitted = is_array($machine->permitted_cavities)
            ? array_map('intval', $machine->permitted_cavities)
            : [];

        if ($permitted !== []) {
            return $query->where(fn ($q) => $q->whereIn('cavities', $permitted)->orWhereNull('cavities'));
        }

        return $query
            ->when($machine->min_cavities !== null, fn ($q) => $q->where(
                fn ($sub) => $sub->where('cavities', '>=', $machine->min_cavities)->orWhereNull('cavities'),
            ))
            ->when($machine->max_cavities !== null, fn ($q) => $q->where(
                fn ($sub) => $sub->where('cavities', '<=', $machine->max_cavities)->orWhereNull('cavities'),
            ));
    }

    /**
     * The machine exceptions for every product on the page, in one query
     * rather than one per row.
     *
     * Fetched through the configuration service rather than queried here, so
     * this list and the one the expanded row fetches from
     * standards/{standard}/machine-exceptions are the same list in the same
     * machine order. Two queries for one thing is two orderings waiting to
     * diverge.
     *
     * @param  list<int>  $itemIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function exceptionsByItem(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return $this->configurations
            ->forItems($itemIds, ['workCenter', 'mold'])
            ->groupBy('item_id')
            ->map(fn (EloquentCollection $rows) => $rows
                ->map(fn (ProductionConfiguration $configuration) => [
                    'id' => $configuration->id,
                    'work_center' => [
                        'id' => $configuration->work_center_id,
                        'code' => $configuration->workCenter?->code,
                        'name' => $configuration->workCenter?->name,
                    ],
                    'status' => $configuration->status?->value,
                    'colour' => $configuration->colour,
                    'mold' => $configuration->mold_id === null ? null : [
                        'id' => $configuration->mold_id,
                        'name' => $configuration->mold?->name,
                    ],
                    'effective_from' => $configuration->effective_from?->toDateString(),
                    'effective_to' => $configuration->effective_to?->toDateString(),
                    'default_cycle_time' => $configuration->default_cycle_time,
                    'default_cavities' => $configuration->default_cavities,
                    'unit_weight_grams' => $configuration->unit_weight_grams,
                ])
                ->values()
                ->all())
            ->all();
    }

    /**
     * The item's active recipe, READ-ONLY and by reference.
     *
     * Recipes are item-level and BomService::activeFor is their live truth;
     * this shows which one is in force and nothing more. A configuration may
     * still pin a specific bom_id, and the workspace never copies a recipe or
     * offers to edit one here — Bills of Material owns that, and a second
     * editing surface for one master is how two versions of a recipe start
     * disagreeing.
     *
     * @param  list<int>  $itemIds
     * @return array<int, array{id: int, name: string, version: ?string}>
     */
    private function activeRecipesByItem(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return Bom::query()
            ->whereIn('item_id', $itemIds)
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'item_id', 'name', 'version'])
            // BomService::create() supersedes the previous active recipe, so
            // a second one for the same item is not supposed to exist — but
            // the importer and any direct write can leave one, and this
            // screen must then name the recipe the RUN will use, not a
            // different one. activeFor() takes the first row it finds, so
            // this takes the first too: unique() keeps the earliest, where
            // keyBy() would have kept the latest and shown a recipe no batch
            // consumes.
            ->unique('item_id')
            ->keyBy('item_id')
            ->map(fn (Bom $bom) => [
                'id' => $bom->id,
                'name' => $bom->name,
                'version' => $bom->version,
            ])
            ->all();
    }

    /**
     * The product's Tally identity, and the exact consequence when it has
     * none.
     *
     * Production is NOT blocked by this and the sentence says so, because
     * that is the factory's actual rule: a shift runs, the entry is recorded,
     * and only the voucher waits. A screen that said "not ready" here would
     * be refusing work the floor is allowed to do; a screen that said nothing
     * would let an accountant discover it a week later.
     *
     * @return array{attached: bool, guid_present: bool, sentence: ?string}
     */
    private function tally(ProductionStandard $standard): array
    {
        $item = $standard->item;
        $attached = $item !== null;
        $guidPresent = $item?->tally_stock_item_guid !== null;

        $sentence = match (true) {
            ! $attached => 'Production can run; the voucher will not sync until this product is attached to a Tally stock item.',
            // Attached, but to an item Tally does not carry — a LOCAL-
            // fixture, or a real item whose GUID has not synced. The gap list
            // stays silent about the fixture on purpose (see
            // ProductReadinessService::configurationGaps); the consequence
            // still has to be stated, because it is true either way.
            ! $guidPresent => 'Production can run; the voucher will not sync until this product exists in Tally.',
            default => null,
        };

        return ['attached' => $attached, 'guid_present' => $guidPresent, 'sentence' => $sentence];
    }
}
