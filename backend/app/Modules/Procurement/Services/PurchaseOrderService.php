<?php

namespace App\Modules\Procurement\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Procurement\Events\PurchaseOrderCancelled;
use App\Modules\Procurement\Events\PurchaseOrderClosed;
use App\Modules\Procurement\Events\PurchaseOrderSent;
use App\Modules\Procurement\Exceptions\PurchaseOrderLifecycleException;
use App\Modules\Procurement\Exceptions\RequisitionOverOrderException;
use App\Modules\Procurement\Models\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Modules\Procurement\Models\PurchaseOrderRevision;
use App\Modules\Procurement\Models\PurchaseOrderSchedule;
use App\Modules\Procurement\Models\PurchaseRequisition;
use App\Modules\TallySync\Services\TallySyncLinkService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;

/**
 * The purchase order: its list, its creation, and — Phase 6 — its whole
 * lifecycle as append-only actions plus the two Tally-facing hooks.
 *
 * THE STATE MACHINE, in one place (abilities()):
 *   Draft ──send──▶ Sent ──GRN──▶ PartiallyReceived ──GRN──▶ Closed
 *     │ amend (Draft only; prior lines kept as a revision)
 *     │ cancel (Draft | Sent with ZERO receipts)      close (Sent | Partial)
 *   A Tally-originated mirror (source 'tally') refuses amend/close/cancel:
 *   the ERP never rewrites Tally's book. GoodsReceiptService refuses an
 *   arrival against anything but Sent | PartiallyReceived, so a Closed or
 *   Cancelled order cannot grow a receipt.
 * The resource's `can` prints abilities() and each action enforces the
 * same predicate under a row lock, so a button and its refusal cannot
 * disagree.
 *
 * TALLY: send() announces PurchaseOrderSent, close() PurchaseOrderClosed
 * and cancel() PurchaseOrderCancelled — all AFTER commit — and this module
 * knows nothing else about Tally. TallySync's listeners answer by calling
 * recordTallyStaging() — the ONLY writer of purchase_orders.tally_staging
 * — with disabled (the flag is off; owner gate Q35) / refused (reasons) /
 * enqueued (entry_id) / dismissed (a staged voucher the agent had not
 * collected, withdrawn because the order was cancelled or closed), or with
 * the unchanged staging plus an `after` note when the agent already holds
 * it. The `tally` link on every row is TallySyncLinkService's
 * (status + flags + link, never the payload).
 */
class PurchaseOrderService
{
    /** Loaded on every order the list hands back, so the resource never lazy-loads. */
    private const WITH = ['vendor', 'lines.item', 'lines.schedules'];

    /** Counted on every order the list hands back — the resource's receipts_count / revisions_count and abilities()' receipt check. */
    private const WITH_COUNT = ['receipts', 'revisions'];

    /** How many orders cursor() reads — and decorates — per query: a page of the export, never the whole file. */
    private const EXPORT_CHUNK = 500;

    /**
     * The states purchase_orders.tally_staging may carry (addendum 6, plus
     * 'dismissed' — the staged voucher was withdrawn before the agent
     * collected it because the order was cancelled or short-closed).
     */
    public const STAGING_STATES = ['disabled', 'refused', 'enqueued', 'dismissed'];

    public function __construct(
        private readonly ProcurementDocumentQuery $query,
        private readonly TallySyncLinkService $tallyLinks,
        private readonly RequisitionCoverageService $coverage,
    ) {}

    /**
     * The list, filtered (Phase 4.5, mirroring Sales' Phase 3.5 lists).
     * $filters is the validated ListPurchaseOrdersRequest input; an empty
     * array is the unfiltered list every earlier caller still gets — newest
     * first, same page size. Every row is stamped with its Purchase Order
     * TallyLink (one query for the page).
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        // withQueryString(): the paginator's own links carry the request's
        // query string, so an API client walking links.next stays on the
        // same query (as the Sales lists do).
        $page = $this->listQuery($filters)->paginate($perPage)->withQueryString();
        $this->decorateMany($page->getCollection());

        return $page;
    }

    /**
     * Every matching order, in the list's order, one at a time — the Export
     * Center's read (PurchaseOrdersExport / PurchaseOrderLinesExport): the
     * SAME filters and the SAME ordering as paginate(), off the same
     * builder, so a file can never carry rows the screen would not, nor in
     * another order. Builder::lazy, not Builder::cursor(): cursor() skips
     * the eager loads the resource prints. Each chunk decorated as a page is.
     *
     * @param  array<string, mixed>  $filters
     * @return LazyCollection<int, PurchaseOrder>
     */
    public function cursor(array $filters = []): LazyCollection
    {
        return $this->listQuery($filters)
            ->lazy(self::EXPORT_CHUNK)
            ->chunk(self::EXPORT_CHUNK)
            ->flatMap(function (LazyCollection $chunk): LazyCollection {
                $this->decorateMany($chunk);

                return $chunk;
            });
    }

    /**
     * How many orders the list would carry — one COUNT over the filtered
     * query (the export's cap check; also the list's meta.total).
     *
     * @param  array<string, mixed>  $filters
     */
    public function count(array $filters = []): int
    {
        return $this->filtered($filters)->count();
    }

    /**
     * How many LINES the matching orders carry between them — one COUNT
     * with the filtered orders as a subquery (the line export's cap check).
     *
     * @param  array<string, mixed>  $filters
     */
    public function linesCount(array $filters = []): int
    {
        return PurchaseOrderLine::query()
            ->whereIn('purchase_order_id', $this->filtered($filters)->select('purchase_orders.id'))
            ->count();
    }

    /**
     * Orders with stock still to arrive, soonest expected first (undated
     * last) — the dashboard's "stock coming in" read. Drafts are excluded:
     * nothing is coming until an order has been sent.
     *
     * @return Collection<int, PurchaseOrder>
     */
    public function upcoming(int $limit = 5): Collection
    {
        return PurchaseOrder::query()
            ->with(['vendor', 'lines.item'])
            ->whereIn('status', [PurchaseOrderStatus::Sent, PurchaseOrderStatus::PartiallyReceived])
            ->orderByRaw('expected_date IS NULL, expected_date')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function openCount(): int
    {
        return PurchaseOrder::query()
            ->whereIn('status', [
                PurchaseOrderStatus::Draft,
                PurchaseOrderStatus::Sent,
                PurchaseOrderStatus::PartiallyReceived,
            ])
            ->count();
    }

    /**
     * One order as the show endpoint returns it (Phase 6, P6-02): the list's
     * relations plus its revisions and receipts (summary rows), the counts,
     * and its TallyLink. The chain behind it is PurchaseOrderTraceService —
     * a separate read.
     */
    public function show(PurchaseOrder $order): PurchaseOrder
    {
        $order->load([...self::WITH, 'revisions', 'receipts.lines']);
        $order->loadCount(self::WITH_COUNT);
        $this->decorateMany([$order]);

        // Each arrival's Receipt Note link too (one query), so the order's
        // receipts summary can say how each voucher is doing.
        $links = $this->tallyLinks->forMany(
            (new GoodsReceiptNote)->getMorphClass(),
            $order->receipts->map(fn (GoodsReceiptNote $receipt) => $receipt->id)->all(),
        );
        foreach ($order->receipts as $receipt) {
            $receipt->tallyLink = $links[$receipt->id] ?? null;
        }

        return $order;
    }

    /**
     * @param  array{vendor_id: int, purchase_requisition_id?: int, order_date: string, expected_date?: string, notes?: string, lines: array<int, array{item_id: int, quantity: string, unit_price: string}>}  $data
     */
    public function create(array $data, ?int $createdBy): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $createdBy) {
            $order = PurchaseOrder::create([
                'vendor_id' => $data['vendor_id'],
                'purchase_requisition_id' => $data['purchase_requisition_id'] ?? null,
                'status' => PurchaseOrderStatus::Draft,
                'order_date' => $data['order_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $createdBy,
                // 'tally' = a read-only mirror of the order that lives in
                // Tally (the PO/schedule source of truth). It is corrected
                // in Tally and re-mirrored, never edited here.
                'source' => $data['source'] ?? 'erp',
                'tally_order_no' => $data['tally_order_no'] ?? null,
            ]);

            $this->writeLines($order, $data['lines']);

            // NO COVERAGE GUARD HERE, and that is the owner's rule rather
            // than an omission: a DRAFT reserves nothing, so a draft's lines
            // are not in the sum and a guard on this path could never fire.
            // A guard that cannot fire is worse than none — it reads as a
            // protection that is not there. The refusal lives in send(),
            // where an order first starts holding quantity.
            //
            // A Tally mirror arrives already sent — it IS the live order;
            // draft/send is the ERP-native lifecycle only. It therefore
            // starts holding quantity immediately and must carry sent_at,
            // or cancelling it later would hand back balance it had spent.
            if (($data['source'] ?? 'erp') === 'tally') {
                $order->update(['status' => PurchaseOrderStatus::Sent, 'sent_at' => now()]);
            }

            return $this->decorate($order);
        });
    }

    /**
     * Draft → Sent, and the ONE announcement Procurement makes about it:
     * PurchaseOrderSent, dispatched AFTER the transaction committed
     * (DB::afterCommit) so a listener that reads the order back sees it
     * Sent and never inside a transaction that might still roll back. This
     * method knows nothing about Tally — TallySync listens (see class note).
     * Refused for anything but a Draft (a mirror is born Sent).
     */
    public function send(PurchaseOrder $order): PurchaseOrder
    {
        $sent = DB::transaction(function () use ($order) {
            $fresh = $this->lockedForAction($order);

            if (! $this->abilities($fresh)['send']) {
                throw InvalidStatusTransitionException::make(
                    'purchase order',
                    $fresh->status->value,
                    PurchaseOrderStatus::Sent->value,
                );
            }

            // sent_at is stamped with the status, in one update: the two are
            // the same fact, and a Sent order without one would later read as
            // never-sent if it were cancelled.
            $fresh->update(['status' => PurchaseOrderStatus::Sent, 'sent_at' => now()]);

            // AFTER the flip, never before: the order must be in its own sum.
            // Sending is where a purchase order starts holding quantity
            // against its requisition, so it is where the combined quantity
            // is checked.
            $this->guardRequisitionCoverage($fresh);

            DB::afterCommit(fn () => event(new PurchaseOrderSent($fresh)));

            return $fresh;
        });

        return $this->decorate($sent);
    }

    /**
     * Replace a DRAFT's lines and schedules (Phase 6, P6-01). The prior
     * lines — item, quantity, rate, quantity_received, schedules — are kept
     * as a kind-'amend' row in purchase_order_revisions before anything is
     * removed, inside the same transaction; the new lines obey create()'s
     * rules (schedules may not promise more than the line). Refused after
     * Sent: what the vendor holds is not amended here — short-close or
     * cancel; and once PO→Tally is on, amending a sent order would be a NEW
     * category of Tally write (Alter) — an owner question, not built.
     * Refused on a Tally mirror.
     *
     * @param  array{lines: array<int, array<string, mixed>>, reason?: ?string}  $data
     */
    public function amend(PurchaseOrder $order, array $data, ?int $amendedBy): PurchaseOrder
    {
        $amended = DB::transaction(function () use ($order, $data, $amendedBy) {
            $fresh = $this->lockedForAction($order);

            if ($fresh->isTallyMirror()) {
                throw PurchaseOrderLifecycleException::tallyMirror($fresh);
            }
            if (! $this->abilities($fresh)['amend']) {
                throw PurchaseOrderLifecycleException::amendNotDraft($fresh);
            }

            $fresh->load(['lines.item', 'lines.schedules']);
            $this->recordRevision($fresh, PurchaseOrderRevision::KIND_AMEND, $this->linesSnapshot($fresh), $amendedBy, $data['reason'] ?? null);

            // A Draft has never been received (GoodsReceiptService only
            // books against Sent | PartiallyReceived), so no receipt line,
            // lot or allocation points at these rows.
            $lineIds = $fresh->lines->pluck('id')->all();
            PurchaseOrderSchedule::query()->whereIn('purchase_order_line_id', $lineIds)->delete();
            PurchaseOrderLine::query()->whereIn('id', $lineIds)->delete();
            $fresh->unsetRelation('lines');

            // Amend is Draft-only, and a draft holds nothing, so there is
            // nothing here for the coverage rule to guard: whatever quantity
            // is typed, it is checked when the draft is SENT.
            $this->writeLines($fresh, $data['lines']);

            return $fresh;
        });

        return $this->decorate($amended);
    }

    /**
     * Short-close an order the vendor holds (Sent | PartiallyReceived):
     * status Closed with closed_reason / closed_by / closed_at, and what was
     * still open per line at that moment kept as a kind-'close' revision
     * row. Refused for a Draft (send or cancel it), for Closed / Cancelled,
     * and on a Tally mirror. Touches no stock and posts no Tally of its
     * own: it announces PurchaseOrderClosed AFTER the transaction committed
     * (DB::afterCommit, exactly as send() does) and TallySync decides what
     * that means for a voucher it staged.
     */
    public function close(PurchaseOrder $order, string $reason, ?int $closedBy): PurchaseOrder
    {
        $closed = DB::transaction(function () use ($order, $reason, $closedBy) {
            $fresh = $this->lockedForAction($order);

            if ($fresh->isTallyMirror()) {
                throw PurchaseOrderLifecycleException::tallyMirror($fresh);
            }
            if (! $this->abilities($fresh)['close']) {
                throw PurchaseOrderLifecycleException::closeNotOpen($fresh);
            }

            $fresh->load('lines.item');
            $this->recordRevision($fresh, PurchaseOrderRevision::KIND_CLOSE, $this->remainingSnapshot($fresh), $closedBy, $reason);

            $fresh->update([
                'status' => PurchaseOrderStatus::Closed,
                'closed_reason' => $reason,
                'closed_by' => $closedBy,
                'closed_at' => now(),
            ]);

            DB::afterCommit(fn () => event(new PurchaseOrderClosed($fresh)));

            return $fresh;
        });

        return $this->decorate($closed);
    }

    /**
     * Cancel an order nothing has arrived against (Draft | Sent with ZERO
     * receipts): status Cancelled — the enum case that had no writer until
     * now — with cancelled_reason / cancelled_by / cancelled_at. Refused
     * once a receipt exists (PartiallyReceived, or Closed by receipt — the
     * order is short-closed instead), for Closed / Cancelled, and on a
     * Tally mirror. Judged and written under the row lock, so an arrival
     * committing between the read and the write cannot leave a cancelled
     * order with a receipt (GoodsReceiptService locks the same row). Posts
     * no Tally of its own: it announces PurchaseOrderCancelled AFTER the
     * transaction committed (DB::afterCommit, exactly as send() does) and
     * TallySync decides what that means for a voucher it staged.
     */
    public function cancel(PurchaseOrder $order, string $reason, ?int $cancelledBy): PurchaseOrder
    {
        $cancelled = DB::transaction(function () use ($order, $reason, $cancelledBy) {
            $fresh = $this->lockedForAction($order);

            if ($fresh->isTallyMirror()) {
                throw PurchaseOrderLifecycleException::tallyMirror($fresh);
            }
            if (! $this->abilities($fresh)['cancel']) {
                throw PurchaseOrderLifecycleException::cancelNotOpen($fresh, (int) $fresh->receipts_count);
            }

            $fresh->update([
                'status' => PurchaseOrderStatus::Cancelled,
                'cancelled_reason' => $reason,
                'cancelled_by' => $cancelledBy,
                'cancelled_at' => now(),
            ]);

            DB::afterCommit(fn () => event(new PurchaseOrderCancelled($fresh)));

            return $fresh;
        });

        return $this->decorate($cancelled);
    }

    /**
     * THE ONE state-machine predicate: what may be done to this order right
     * now. Printed by PurchaseOrderResource as `can` and enforced by
     * send() / amend() / close() / cancel() under a row lock — one truth,
     * so the frontend never re-derives it. Uses receipts_count when the
     * caller counted it (the list does), else one COUNT.
     *
     * @return array{amend: bool, close: bool, cancel: bool, send: bool}
     */
    public function abilities(PurchaseOrder $order): array
    {
        $status = $order->status;
        $mirror = $order->isTallyMirror();
        $receipts = (int) ($order->receipts_count ?? $order->receipts()->count());

        return [
            'amend' => ! $mirror && $status === PurchaseOrderStatus::Draft,
            'close' => ! $mirror && in_array($status, [PurchaseOrderStatus::Sent, PurchaseOrderStatus::PartiallyReceived], true),
            'cancel' => ! $mirror && $receipts === 0 && in_array($status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Sent], true),
            'send' => ! $mirror && $status === PurchaseOrderStatus::Draft,
        ];
    }

    /**
     * Record what the Tally side made of this order — the ONLY writer of
     * purchase_orders.tally_staging (addendum 6). Called by TallySync's
     * PurchaseOrderSent / PurchaseOrderClosed / PurchaseOrderCancelled
     * listeners (a cross-module write, through this service).
     *
     * STATES: 'disabled' (tally-sync.purchase_orders_enabled is off — the
     * default; owner gate Q35) · 'refused' (the enqueue named why) ·
     * 'enqueued' (entry_id) · 'dismissed' (the staged voucher was withdrawn
     * before the agent collected it — the order was cancelled or closed).
     *
     * REASON CODES, the whole documented set — it must match what the
     * emitters actually emit:
     *   purchase_orders_disabled   the flag is off (state 'disabled')
     *   party_unmapped             the vendor has no Tally ledger name
     *   item_unmapped              a line's item has no Tally identity
     *   purchase_ledger_unmapped   no ledger is mapped to the Purchase role
     *   godown_unresolved          no single Tally godown to allocate to
     *   no_lines                   the order has no lines to order
     *   cancelled_before_delivery  withdrawn: cancelled while still pending
     *   closed_before_delivery     withdrawn: short-closed while pending
     *
     * `after` — 'cancelled_after_delivery' | 'closed_after_delivery' — is
     * added to the UNCHANGED staging when the agent already holds the
     * voucher: the ERP no longer owns its fate, and what Tally should be
     * told is owner question Q48.
     *
     * The latest word replaces the earlier one; `at` is stamped here unless
     * the caller carries the earlier one forward. Writes this one column
     * and nothing else — no Tally, no status change.
     *
     * @param  array{state: string, reasons?: list<array{code: string, detail?: ?string}>, entry_id?: ?int, at?: ?string, after?: ?string}  $staging
     */
    public function recordTallyStaging(PurchaseOrder $order, array $staging): void
    {
        $state = (string) ($staging['state'] ?? '');
        if (! in_array($state, self::STAGING_STATES, true)) {
            throw new InvalidArgumentException(
                'tally_staging.state must be one of '.implode('|', self::STAGING_STATES).", got \"{$state}\".",
            );
        }

        $reasons = [];
        foreach ($staging['reasons'] ?? [] as $reason) {
            $reasons[] = [
                'code' => (string) ($reason['code'] ?? 'unknown'),
                'detail' => isset($reason['detail']) ? (string) $reason['detail'] : null,
            ];
        }

        $record = [
            'state' => $state,
            'reasons' => $reasons,
            'at' => isset($staging['at']) ? (string) $staging['at'] : now()->toIso8601String(),
        ];
        if (isset($staging['entry_id'])) {
            $record['entry_id'] = (int) $staging['entry_id'];
        }
        // Present only when the order ended after the agent had collected
        // its voucher — absent (never null) otherwise, so "no `after`" and
        // "an `after` nobody set" cannot be confused.
        if (isset($staging['after'])) {
            $record['after'] = (string) $staging['after'];
        }

        // One column, nothing else on the order changes.
        $order->forceFill(['tally_staging' => $record])->save();
    }

    // ---- internals --------------------------------------------------------------

    /**
     * The order re-read UNDER A ROW LOCK with its receipt/revision counts —
     * what every lifecycle action judges. The lock is what serialises a
     * lifecycle action against an arrival (GoodsReceiptService locks the
     * same row) and against another action on the same order.
     */
    private function lockedForAction(PurchaseOrder $order): PurchaseOrder
    {
        return PurchaseOrder::query()
            ->whereKey($order->getKey())
            ->withCount(self::WITH_COUNT)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * MANY ORDERS AGAINST ONE REQUISITION, NEVER MORE THAN IT ASKED FOR.
     *
     * A requisition may be answered by as many purchase orders as the buyer
     * needs — split across vendors, or ordered in tranches — but the
     * combined quantity of every order that HOLDS quantity against it
     * (RequisitionCoverageService::reserves()) may not exceed what it asked
     * for, per item. Not in a FormRequest: a request can only see the one
     * order being typed, and the rule is about all of them together.
     *
     * CALLED FROM send(), AND ONLY FROM send(). On the owner's rule a draft
     * reserves nothing, so create() and amend() have nothing to check — the
     * quantity a buyer types becomes real at the moment the order goes to
     * the vendor, and that is the moment this runs. The accepted cost, named
     * when the rule was chosen: two drafts may each be typed for the whole
     * requisition, and the second is refused at Send rather than at typing.
     *
     * THE LOCK IS THE RULE. Inside the caller's transaction, it takes a row
     * lock on the REQUISITION before summing. send() already locks the
     * ORDER row (lockedForAction), but two DIFFERENT drafts against one
     * requisition being sent at the same moment lock two different orders —
     * they would both read the same total, both find room, and both commit.
     * The requisition is the thing they contend for, so the requisition is
     * what is locked; the second transaction blocks until the first commits,
     * then re-sums and sees it. The precise defect
     * PurchaseRequisitionService::decide() already carries this lock for
     * (Approve and Reject raced on one draft and both landed).
     *
     * A TALLY MIRROR IS RECORDED, NOT REFUSED. `source: tally` is a
     * read-only reflection of an order that already exists in Tally (the
     * book the ERP does not argue with — DEC-20260812-002 and
     * StorePurchaseOrderRequest's class docblock, where the retired-vendor
     * and archived-item rules are scoped away for this same reason). An ERP
     * refusal cannot unmake that order; it can only leave the ERP unable to
     * show it, and an invisible order is exactly what breaks the
     * remaining-quantity tracking the decision asks for. So a mirror is
     * counted towards the requisition — it consumes balance and is visible
     * on the line — but never rejected by this guard. An ERP-entered order
     * that follows one is refused normally, on the true total.
     */
    private function guardRequisitionCoverage(PurchaseOrder $order): void
    {
        if ($order->purchase_requisition_id === null || $order->isTallyMirror()) {
            return;
        }

        $requisition = PurchaseRequisition::query()
            ->lockForUpdate()
            ->find($order->purchase_requisition_id);

        if ($requisition === null) {
            return;
        }

        // Off the database, not off whatever the caller had loaded: the rows
        // this transaction just wrote have to be in the sum.
        $requisition->unsetRelation('lines')->unsetRelation('purchaseOrders');

        $overOrdered = $this->coverage->overOrderedItems($requisition);

        if ($overOrdered !== []) {
            throw RequisitionOverOrderException::forItems($requisition->id, $overOrdered);
        }
    }

    /**
     * Write an order's lines and their delivery schedules — create()'s
     * loop, shared with amend(). Item/due-date delivery windows are the
     * mirror of Tally's order allocations; their sum may not exceed the
     * line: a schedule promising more than was ordered is a typo, not a
     * plan.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function writeLines(PurchaseOrder $order, array $lines): void
    {
        foreach ($lines as $line) {
            $created = $order->lines()->create([
                'item_id' => $line['item_id'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'quantity_received' => 0,
            ]);

            $total = '0.0000';
            foreach ($line['schedules'] ?? [] as $schedule) {
                $total = bcadd($total, (string) $schedule['quantity'], 4);
                $created->schedules()->create([
                    'due_date' => $schedule['due_date'],
                    'quantity' => $schedule['quantity'],
                    'quantity_received' => 0,
                    'tally_reference' => $schedule['tally_reference'] ?? null,
                ]);
            }

            if (bccomp($total, (string) $created->quantity, 4) > 0) {
                throw new InvalidStatusTransitionException(
                    "the delivery schedules for one line promise {$total} against an ordered {$created->quantity} — correct the schedule quantities",
                );
            }
        }
    }

    /**
     * Append one revision row: revision_no = the order's next number across
     * both kinds. Inside the caller's transaction, under the caller's lock.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function recordRevision(PurchaseOrder $order, string $kind, array $lines, ?int $by, ?string $reason): void
    {
        $next = ((int) $order->revisions()->max('revision_no')) + 1;

        $order->revisions()->create([
            'revision_no' => $next,
            'kind' => $kind,
            'lines_json' => $lines,
            'amended_by' => $by,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    /**
     * The lines as they stand — the kind-'amend' snapshot. Carries the rate
     * (Owner/Accounts data; the resource gates it) and the item's sku/name
     * at the time, since the rows themselves are about to go.
     *
     * @return list<array<string, mixed>>
     */
    private function linesSnapshot(PurchaseOrder $order): array
    {
        return $order->lines->map(fn (PurchaseOrderLine $line) => [
            'purchase_order_line_id' => $line->id,
            'item_id' => $line->item_id,
            'item' => $line->item === null ? null : ['id' => $line->item->id, 'sku' => $line->item->sku, 'name' => $line->item->name],
            'quantity' => (string) $line->quantity,
            'unit_price' => (string) $line->unit_price,
            'quantity_received' => (string) $line->quantity_received,
            'schedules' => $line->schedules->map(fn ($schedule) => [
                'due_date' => $schedule->due_date?->toDateString(),
                'quantity' => (string) $schedule->quantity,
                'quantity_received' => (string) $schedule->quantity_received,
                'tally_reference' => $schedule->tally_reference,
            ])->values()->all(),
        ])->values()->all();
    }

    /**
     * What was still open per line — the kind-'close' snapshot. No rate:
     * a close records quantities, not money.
     *
     * @return list<array<string, mixed>>
     */
    private function remainingSnapshot(PurchaseOrder $order): array
    {
        return $order->lines->map(fn (PurchaseOrderLine $line) => [
            'purchase_order_line_id' => $line->id,
            'item_id' => $line->item_id,
            'item' => $line->item === null ? null : ['id' => $line->item->id, 'sku' => $line->item->sku, 'name' => $line->item->name],
            'quantity' => (string) $line->quantity,
            'quantity_received' => (string) $line->quantity_received,
            'remaining' => bcsub((string) $line->quantity, (string) $line->quantity_received, 4),
        ])->values()->all();
    }

    /** The relations and counts the resource prints plus the TallyLink, on ONE order. */
    private function decorate(PurchaseOrder $order): PurchaseOrder
    {
        $order->load(self::WITH);
        $order->loadCount(self::WITH_COUNT);
        $this->decorateMany([$order]);

        return $order;
    }

    /**
     * Stamp each order with its Purchase Order TallyLink (or null) — one
     * query for the whole set (TallySyncLinkService::forMany) — and its
     * abilities() (no query: receipts_count is already counted).
     *
     * @param  iterable<int, PurchaseOrder>  $orders
     */
    private function decorateMany(iterable $orders): void
    {
        $orders = SupportCollection::make($orders);
        if ($orders->isEmpty()) {
            return;
        }

        $links = $this->tallyLinks->forMany(
            (new PurchaseOrder)->getMorphClass(),
            $orders->map(fn (PurchaseOrder $order) => $order->id)->all(),
        );

        foreach ($orders as $order) {
            $order->tallyLink = $links[$order->id] ?? null;
            $order->can = $this->abilities($order);
        }
    }

    /**
     * The list's builder: every filter applied, the relations and counts
     * the resource prints, then the list's order — what paginate() pages
     * and cursor() streams.
     *
     * @param  array<string, mixed>  $filters
     */
    private function listQuery(array $filters): Builder
    {
        $query = $this->filtered($filters)->with(self::WITH)->withCount(self::WITH_COUNT);
        $this->query->applySort($query, $filters['sort'] ?? null, ['order_date', 'expected_date']);

        return $query;
    }

    /**
     * The filtered orders, nothing loaded and nothing ordered — the one
     * builder listQuery(), count() and linesCount() all start from.
     *
     * @param  array<string, mixed>  $filters
     */
    private function filtered(array $filters): Builder
    {
        $query = PurchaseOrder::query();
        $this->applyFilters($query, $filters);

        return $query;
    }

    /**
     * Every filter of ListPurchaseOrdersRequest. `status` is one value or a
     * list (Phase 6 — a whereIn). `q` matches the order number in any
     * spelling ("PO-12", "po 12", "12"), the Tally order number of a mirror,
     * or the vendor's name or code — never notes. The date range is on
     * order_date, a plain date.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['vendor_id'])) {
            $query->where('vendor_id', (int) $filters['vendor_id']);
        }

        if (! empty($filters['status'])) {
            $statuses = is_array($filters['status'])
                ? array_values(array_filter($filters['status'], fn ($status) => $status !== null && $status !== ''))
                : [$filters['status']];
            if ($statuses !== []) {
                $query->whereIn('status', $statuses);
            }
        }

        $this->query->applyDateRange($query, 'order_date', $filters['from'] ?? null, $filters['to'] ?? null);

        if (! empty($filters['item_id'])) {
            $query->whereHas('lines', fn (Builder $lines) => $lines->where('item_id', (int) $filters['item_id']));
        }

        if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
            $term = trim((string) $filters['q']);
            $id = $this->query->documentId($term, 'PO');

            $query->where(function (Builder $any) use ($term, $id) {
                if ($id !== null) {
                    $any->orWhere('purchase_orders.id', $id);
                }
                $any->orWhere(fn (Builder $tally) => $this->query->whereLike($tally, 'tally_order_no', $term));
                $any->orWhereHas('vendor', fn (Builder $vendor) => $this->query->whereVendorMatches($vendor, $term));
            });
        }
    }
}
