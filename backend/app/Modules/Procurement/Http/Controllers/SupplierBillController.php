<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Item;
use App\Modules\Procurement\Http\Requests\ListSupplierBillsRequest;
use App\Modules\Procurement\Http\Requests\StoreSupplierBillRequest;
use App\Modules\Procurement\Http\Resources\SupplierBillResource;
use App\Modules\Procurement\Models\SupplierBill;
use App\Modules\Procurement\Services\ProcurementDocumentQuery;
use App\Modules\Procurement\Services\SupplierBillService;
use App\Modules\TallySync\Models\Ledger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Thin per the module pattern — every rule lives in SupplierBillService.
 * The whole controller rides module:finance (FC-06: purchase rates are
 * Owner/Accounts only), see routes/api.php.
 */
class SupplierBillController extends Controller
{
    public function __construct(private readonly SupplierBillService $bills) {}

    public function index(ListSupplierBillsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        return SupplierBillResource::collection(
            $this->bills->paginate((int) ($filters['per_page'] ?? 20), $filters),
        );
    }

    public function show(SupplierBill $supplierBill): SupplierBillResource
    {
        return SupplierBillResource::make($this->bills->show($supplierBill));
    }

    public function store(StoreSupplierBillRequest $request): SupplierBillResource
    {
        return SupplierBillResource::make($this->bills->create($request->validated(), $request->user()?->id));
    }

    public function update(StoreSupplierBillRequest $request, SupplierBill $supplierBill): SupplierBillResource
    {
        return SupplierBillResource::make($this->bills->update($supplierBill, $request->validated()));
    }

    public function record(Request $request, SupplierBill $supplierBill): SupplierBillResource
    {
        return SupplierBillResource::make($this->bills->record($supplierBill, $request->user()?->id));
    }

    public function cancel(Request $request, SupplierBill $supplierBill): SupplierBillResource
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return SupplierBillResource::make($this->bills->cancel($supplierBill, $validated['reason']));
    }

    public function attach(Request $request, SupplierBill $supplierBill): SupplierBillResource
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:'.implode(',', SupplierBillService::ATTACHMENT_MIMES),
                'max:'.SupplierBillService::ATTACHMENT_MAX_KB,
            ],
        ]);

        return SupplierBillResource::make($this->bills->attach($supplierBill, $request->file('file')));
    }

    public function download(SupplierBill $supplierBill): StreamedResponse
    {
        abort_if($supplierBill->attachment_path === null, 404, 'This bill has no attachment.');

        return Storage::disk('local')->download(
            $supplierBill->attachment_path,
            $supplierBill->attachment_name ?? basename($supplierBill->attachment_path),
        );
    }

    /**
     * Item options for the bill's line picker, served INSIDE the finance
     * gate (Codex round 2): an Accounts login without inventory access got
     * a 403 from /inventory/items, an empty picker, and no way to enter an
     * unmatched bill — which the backend expressly supports (Q64). Identity
     * only: id, sku, name, uom — no stock figures, no costs. `q` narrows
     * server-side; the cap is named in the response so a truncated list is
     * never mistaken for the whole catalogue.
     */
    public function itemOptions(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $limit = 200;

        $query = Item::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->limit($limit);

        if ($q !== '') {
            // The shared grammar's contains-match — portable across MySQL
            // and the sqlite suite (which has no default LIKE escape), the
            // typed % and _ taken as characters.
            $grammar = app(ProcurementDocumentQuery::class);
            $query->where(function ($either) use ($grammar, $q) {
                $grammar->whereLike($either, 'name', $q);
                $either->orWhere(fn ($sku) => $grammar->whereLike($sku, 'sku', $q));
            });
        }

        $items = $query->get(['id', 'sku', 'name', 'uom']);

        return response()->json([
            'data' => $items,
            'meta' => ['limit' => $limit, 'truncated' => $items->count() === $limit],
        ]);
    }

    /**
     * Ledger names for the bill's purchase-ledger picker — the accountant
     * SELECTS from the ledgers the masters pull already brought over; the
     * ERP derives nothing (Q39 open). With no `q`, the natural candidates:
     * ledgers whose Tally group names purchases. With one, a contains-match
     * over every ledger name, so nothing is unfindable. Names only — no
     * balances, no rates.
     */
    public function ledgerOptions(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $query = Ledger::query()->orderBy('name')->limit(200);

        if ($q !== '') {
            app(ProcurementDocumentQuery::class)->whereLike($query, 'name', $q);
        } else {
            $query->where('tally_group_name', 'like', '%Purchase%');
        }

        return response()->json([
            'data' => $query->get(['name', 'tally_group_name'])
                ->map(fn (Ledger $ledger) => ['name' => $ledger->name, 'group' => $ledger->tally_group_name])
                ->values(),
        ]);
    }
}
