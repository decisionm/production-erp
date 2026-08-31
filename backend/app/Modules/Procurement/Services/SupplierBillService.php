<?php

namespace App\Modules\Procurement\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Procurement\Models\Enums\SupplierBillStatus;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use App\Modules\Procurement\Models\SupplierBill;
use App\Modules\Procurement\Models\SupplierBillLine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Recording the vendor's invoice — see the migration's docblock for the
 * boundaries (a record of the paper, never a computed tax, never a Tally
 * voucher; Q39/Q41/Q28 open).
 *
 * THE ONE ARITHMETIC ENFORCED is the bill's own:
 *   subtotal = Σ line amounts, and
 *   total    = subtotal + CGST + SGST + IGST + rounding
 * both to the paisa (bc math on strings, decimal columns — CLAUDE.md). A
 * bill that does not add up is refused with the gap NAMED, because it is a
 * typo today and a dispute in a quarter. Line amount vs qty × rate is
 * deliberately NOT refused — vendors round per line — the screen shows the
 * variance instead.
 *
 * LIFECYCLE: draft (editable) → recorded (who/when stamped, read-only) or
 * cancelled (reason kept, row kept). Nothing is ever hard-deleted, and
 * nothing here moves stock or queues anything for Tally.
 */
class SupplierBillService
{
    private const WITH = ['vendor', 'purchaseOrder', 'lines.item', 'lines.goodsReceiptNoteLine', 'createdBy', 'recordedBy'];

    /** Attachment rules: what a scanned bill actually is, capped at 10 MB. */
    public const ATTACHMENT_MIMES = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    public const ATTACHMENT_MAX_KB = 10240;

    public function __construct(private readonly ProcurementDocumentQuery $query) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        $query = SupplierBill::query()->with(self::WITH);
        $this->applyFilters($query, $filters);
        $this->query->applySort($query, $filters['sort'] ?? null, ['bill_date']);

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * `q` matches "BILL-12" in any spelling, the vendor's own invoice
     * number, or the vendor by name or code.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['vendor_id'])) {
            $query->where('vendor_id', (int) $filters['vendor_id']);
        }

        if (! empty($filters['purchase_order_id'])) {
            $query->where('purchase_order_id', (int) $filters['purchase_order_id']);
        }

        if (! empty($filters['status'])) {
            $statuses = is_array($filters['status'])
                ? array_values(array_filter($filters['status'], fn ($status) => $status !== null && $status !== ''))
                : [$filters['status']];
            if ($statuses !== []) {
                $query->whereIn('status', $statuses);
            }
        }

        $this->query->applyDateRange($query, 'bill_date', $filters['from'] ?? null, $filters['to'] ?? null);

        if (isset($filters['q']) && trim((string) $filters['q']) !== '') {
            $term = trim((string) $filters['q']);
            $id = $this->query->documentId($term, 'BILL');

            $query->where(function (Builder $any) use ($term, $id) {
                if ($id !== null) {
                    $any->orWhere('supplier_bills.id', $id);
                }
                $any->orWhere(fn (Builder $number) => $this->query->whereLike($number, 'bill_number', $term));
                $any->orWhereHas('vendor', fn (Builder $vendor) => $this->query->whereVendorMatches($vendor, $term));
            });
        }
    }

    public function show(SupplierBill $bill): SupplierBill
    {
        return $bill->load(self::WITH);
    }

    /**
     * @param  array<string, mixed>  $data  validated StoreSupplierBillRequest input
     */
    public function create(array $data, ?int $createdBy): SupplierBill
    {
        $this->guardArithmetic($data);
        $this->guardMatching($data);

        try {
            return DB::transaction(function () use ($data, $createdBy) {
                $bill = SupplierBill::create([
                    ...collect($data)->except('lines')->all(),
                    'created_by' => $createdBy,
                ]);

                foreach ($data['lines'] as $line) {
                    $bill->lines()->create($line);
                }

                // refresh(): status comes from the column default; the
                // in-memory model does not know it until read back.
                return $bill->refresh()->load(self::WITH);
            });
        } catch (QueryException $exception) {
            // Two simultaneous POSTs can both pass the request's unique rule
            // before either row exists; the schema's (vendor_id, bill_number)
            // unique then throws here. The refusal must reach the accountant
            // in the same words the sequential path uses — never a 500.
            if ($this->isDuplicateBillNumber($exception)) {
                throw ValidationException::withMessages([
                    'bill_number' => 'This vendor already has a bill with this invoice number — the double-payment path this screen exists to refuse.',
                ]);
            }

            throw $exception;
        }
    }

    /**
     * Replace the draft wholesale — the paper is the source, the draft is
     * the typing. Refused outside Draft: a recorded bill is a record.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(SupplierBill $bill, array $data): SupplierBill
    {
        $this->guardArithmetic($data);
        $this->guardMatching($data);

        try {
            return DB::transaction(function () use ($bill, $data) {
                // Re-read UNDER A ROW LOCK (Codex round 1): a concurrent
                // record() or cancel() between an out-of-transaction status
                // check and this write would let a draft edit rewrite a bill
                // that is already a record. The guard runs on what the lock
                // sees, not on what the route model saw.
                $locked = SupplierBill::query()->lockForUpdate()->findOrFail($bill->id);
                $this->guardStatus($locked, SupplierBillStatus::Draft, 'edit');

                $locked->update(collect($data)->except('lines')->all());
                $locked->lines()->delete();
                foreach ($data['lines'] as $line) {
                    $locked->lines()->create($line);
                }

                return $locked->load(self::WITH);
            });
        } catch (QueryException $exception) {
            // The same race create() translates (Codex round 2): two draft
            // edits can concurrently move DIFFERENT bills onto the same
            // (vendor, bill_number) — both pass the request rule, the schema
            // unique throws for the loser, and a 500 is not an answer.
            if ($this->isDuplicateBillNumber($exception)) {
                throw ValidationException::withMessages([
                    'bill_number' => 'This vendor already has a bill with this invoice number — the double-payment path this screen exists to refuse.',
                ]);
            }

            throw $exception;
        }
    }

    public function record(SupplierBill $bill, ?int $recordedBy): SupplierBill
    {
        return DB::transaction(function () use ($bill, $recordedBy) {
            // Same lock as update() — record() and a draft edit must
            // serialise, whichever lands first.
            $locked = SupplierBill::query()->lockForUpdate()->findOrFail($bill->id);
            $this->guardStatus($locked, SupplierBillStatus::Draft, 'record');

            $locked->forceFill([
                'status' => SupplierBillStatus::Recorded,
                'recorded_by' => $recordedBy,
                'recorded_at' => now(),
            ])->save();

            return $locked->load(self::WITH);
        });
    }

    public function cancel(SupplierBill $bill, string $reason): SupplierBill
    {
        return DB::transaction(function () use ($bill, $reason) {
            // The same lock update()/record()/attach() take (Cursor final
            // pass): cancel() racing record() must not overwrite `recorded`
            // on what the route model still saw as a draft — the guard runs
            // on what the lock sees.
            $locked = SupplierBill::query()->lockForUpdate()->findOrFail($bill->id);

            if ($locked->status === SupplierBillStatus::Cancelled) {
                throw InvalidStatusTransitionException::make('supplier bill', $locked->status->value, SupplierBillStatus::Cancelled->value);
            }

            $locked->forceFill([
                'status' => SupplierBillStatus::Cancelled,
                'cancelled_reason' => $reason,
            ])->save();

            return $locked->load(self::WITH);
        });
    }

    /**
     * One attachment per bill — the scan of the paper. Replacing while
     * Draft is ordinary (a better scan); a recorded bill keeps its file.
     */
    public function attach(SupplierBill $bill, UploadedFile $file): SupplierBill
    {
        return DB::transaction(function () use ($bill, $file) {
            // The update()/record() lock, for the same reason (Codex round
            // 2): an upload racing record() must not replace the scan on a
            // bill that just became read-only — the guard runs on what the
            // lock sees.
            $locked = SupplierBill::query()->lockForUpdate()->findOrFail($bill->id);
            $this->guardStatus($locked, SupplierBillStatus::Draft, 'attach a file to');

            // Store the NEW scan first, delete the old one only after the
            // row points at the replacement (Codex on 073a8c2): deleting
            // first left a rolled-back bill advertising a scan that no
            // longer existed.
            $previous = $locked->attachment_path;
            $path = $file->store("supplier-bills/{$locked->id}", 'local');
            $locked->forceFill([
                'attachment_path' => $path,
                'attachment_name' => $file->getClientOriginalName(),
            ])->save();

            if ($previous !== null && $previous !== $path) {
                // AFTER COMMIT (Codex final pass): a rollback between the
                // delete and the commit would put the OLD path back on the
                // row with its file already gone. Deferred, a rollback
                // leaves both files and the row consistent; the orphaned
                // new file is the cheap failure.
                DB::afterCommit(fn () => Storage::disk('local')->delete($previous));
            }

            return $locked->load(self::WITH);
        });
    }

    // ---- guards -------------------------------------------------------------

    /**
     * The bill's own arithmetic, to the paisa. The gap is NAMED in the
     * refusal so the accountant knows which figure to re-read.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardArithmetic(array $data): void
    {
        $lineSum = '0.0000';
        foreach ($data['lines'] as $line) {
            $lineSum = bcadd($lineSum, (string) $line['amount'], 4);
        }

        if (bccomp($lineSum, (string) $data['subtotal'], 4) !== 0) {
            throw ValidationException::withMessages([
                'subtotal' => "The lines sum to {$lineSum}, not the subtotal typed ({$data['subtotal']}). One of the two is mistyped — the paper knows which.",
            ]);
        }

        $expected = (string) $data['subtotal'];
        foreach (['cgst', 'sgst', 'igst', 'rounding'] as $key) {
            $expected = bcadd($expected, (string) ($data[$key] ?? '0'), 4);
        }

        if (bccomp($expected, (string) $data['total'], 4) !== 0) {
            throw ValidationException::withMessages([
                'total' => "Subtotal + taxes + rounding is {$expected}, not the total typed ({$data['total']}). A bill that does not add up cannot be recorded.",
            ]);
        }
    }

    /**
     * A line matched to a GRN line must be matched to THIS bill's chain:
     * the same purchase order (when the bill names one) and the same item.
     * Nothing forces matching — a bill for something never on a PO is Q64's
     * open territory and stays recordable unmatched.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardMatching(array $data): void
    {
        foreach ($data['lines'] as $index => $line) {
            if (empty($line['goods_receipt_note_line_id'])) {
                continue;
            }

            $grnLine = GoodsReceiptNoteLine::query()
                ->with('goodsReceiptNote.purchaseOrder')
                ->find((int) $line['goods_receipt_note_line_id']);

            if ($grnLine === null) {
                throw ValidationException::withMessages([
                    "lines.{$index}.goods_receipt_note_line_id" => 'That receipt line does not exist.',
                ]);
            }

            if ((int) $grnLine->item_id !== (int) $line['item_id']) {
                throw ValidationException::withMessages([
                    "lines.{$index}.goods_receipt_note_line_id" => 'That receipt line is for a different item than this bill line.',
                ]);
            }

            // The VENDOR check runs whether or not the bill names a PO
            // (Codex round 1): a bill with no order reference could
            // otherwise match another vendor's arrival — and paying vendor A
            // against vendor B's receipt is precisely the confusion the
            // matching column exists to prevent.
            $receiptVendorId = $grnLine->goodsReceiptNote?->purchaseOrder?->vendor_id;
            if ($receiptVendorId !== null && (int) $receiptVendorId !== (int) $data['vendor_id']) {
                throw ValidationException::withMessages([
                    "lines.{$index}.goods_receipt_note_line_id" => 'That receipt line belongs to a different vendor than this bill names.',
                ]);
            }

            if (! empty($data['purchase_order_id'])
                && (int) $grnLine->goodsReceiptNote->purchase_order_id !== (int) $data['purchase_order_id']) {
                throw ValidationException::withMessages([
                    "lines.{$index}.goods_receipt_note_line_id" => 'That receipt line belongs to a different purchase order than this bill names.',
                ]);
            }

            $this->guardBilledOnce($grnLine, $index, $data['id'] ?? null);
            $this->guardWithinAccepted($grnLine, (string) $line['quantity'], $index);
        }
    }

    /**
     * ONE BILL PER RECEIPT LINE (DEC-20260831-008).
     *
     * Nothing stopped the same arrival being billed twice — two bills, or two
     * lines of one bill, could both point at one receipt line and the ERP
     * recorded both without complaint. On a matching column that exists to
     * stop a vendor being paid for material once, that was the gap with money
     * in it.
     *
     * Enforced in the service and not by a unique index, because
     * goods_receipt_note_line_id is nullable (an unmatched bill is legitimate
     * — a bill for something never on a purchase order stays recordable) and
     * a unique index over a nullable column would let a second NULL through
     * while giving a misleading error for the real case.
     */
    private function guardBilledOnce(GoodsReceiptNoteLine $grnLine, int|string $index, ?int $editingBillId): void
    {
        $already = SupplierBillLine::query()
            ->where('goods_receipt_note_line_id', $grnLine->id)
            // An edit of the bill that already holds this line is not a
            // duplicate of itself.
            ->when($editingBillId !== null, fn ($q) => $q->where('supplier_bill_id', '!=', $editingBillId))
            ->with('supplierBill')
            ->first();

        if ($already !== null) {
            $bill = $already->supplierBill?->bill_number ?? 'another bill';

            throw ValidationException::withMessages([
                "lines.{$index}.goods_receipt_note_line_id" => "That receipt line has already been billed on {$bill}. Each goods receipt line is billed once.",
            ]);
        }
    }

    /**
     * A BILL MAY CHARGE ONLY FOR WHAT QUALITY ACCEPTED (DEC-20260831-008).
     *
     * The billable quantity of a receipt line is its QC-ACCEPTED quantity,
     * not what physically arrived — material rejected at incoming inspection
     * is never paid for. Before this, item, vendor and purchase order were
     * all checked and the QUANTITY was not, so a bill for 600 against 500
     * received recorded cleanly.
     *
     * A receipt line Quality has not inspected yet is billable in full, the
     * same reading RequisitionCoverageService takes of uninspected stock: the
     * material is in the store and on the books. Blocking the invoice until
     * QC has run would stop Accounts recording a bill that has genuinely
     * arrived, which is a worse failure than the one this prevents — and the
     * rejected quantity, when it comes, has its own remedy (a debit note).
     */
    private function guardWithinAccepted(GoodsReceiptNoteLine $grnLine, string $billed, int|string $index): void
    {
        $inspections = $grnLine->incomingInspections()->get();

        $billable = $inspections->isEmpty()
            ? (string) $grnLine->quantity
            : $inspections->reduce(fn (string $sum, $i) => bcadd($sum, (string) $i->accepted_quantity, 4), '0');

        if (bccomp($billed, $billable, 4) > 0) {
            $note = $inspections->isEmpty()
                ? "that receipt line received {$billable}"
                : "Quality accepted {$billable} of the {$grnLine->quantity} received";

            throw ValidationException::withMessages([
                "lines.{$index}.quantity" => "This line bills {$billed}, but {$note}. A bill may charge only for the quantity Quality accepted.",
            ]);
        }
    }

    /**
     * Was this QueryException the (vendor_id, bill_number) unique index?
     * SQLSTATE 23000 (MySQL/SQLite) / 23505 (Postgres) narrowed to OUR index
     * by the message naming bill_number — a different integrity error still
     * surfaces as itself.
     */
    private function isDuplicateBillNumber(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');

        return in_array($sqlState, ['23000', '23505'], true)
            && str_contains($exception->getMessage(), 'bill_number');
    }

    private function guardStatus(SupplierBill $bill, SupplierBillStatus $required, string $verb): void
    {
        if ($bill->status !== $required) {
            throw ValidationException::withMessages([
                'status' => "Only a draft bill can be changed — this one is {$bill->status->value}. "
                    .($verb === 'record' ? 'It has already been recorded or cancelled.' : 'Cancel it and enter a new one if the paper says otherwise.'),
            ]);
        }
    }
}
