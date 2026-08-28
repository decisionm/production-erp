<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Procurement\Models\Vendor;
use App\Support\Configuration\DependencyCheck;
use App\Support\Configuration\HardDeleteAuthority;
use App\Support\Configuration\ManagesConfigurationLifecycle;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;

class VendorService
{
    use ManagesConfigurationLifecycle;

    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Vendor::query()
            ->orderBy('name')
            ->paginate($perPage);
    }

    /**
     * The prefix and width of a minted vendor code — "V-0001".
     *
     * `vendors.code` is unique and max 32; a four-digit number leaves the
     * whole of that limit unused and simply gets longer past 9999 rather than
     * wrapping or truncating.
     */
    private const MINTED_PREFIX = 'V-';

    private const MINTED_WIDTH = 4;

    /** How many times a mint may lose the race to a concurrent create before giving up. */
    private const MINT_ATTEMPTS = 5;

    /**
     * A vendor's code is MINTED here when the caller does not bring one.
     *
     * The form used to demand a code from the person filling it in, who had no
     * convention to follow — the live master records what that produced:
     * `V-DEMO-KPXL`, hand-typed on 24-Jul with a random suffix.
     *
     * NOT a slug of the name, which is what WarehouseService::uniqueCodeFrom()
     * and ItemService::uniqueSkuFrom() do, and rightly so for a handful of
     * godowns and for an item whose SKU is read on its own. Measured against
     * the 633 Sundry Creditors ledgers already mirrored in this database, 48
     * supplier names slug past this column's 32 characters, and truncating
     * them to fit collides immediately. A slug also freezes the spelling a
     * name had on its first day, so correcting a name leaves a code that
     * disagrees with it. Every screen showing a vendor code shows the name
     * beside it, so a code that repeats the name earns nothing.
     *
     * A code the caller DOES bring is kept exactly as given. `/api/v1` is a
     * reusable product surface, not this SPA's private detail, so an existing
     * client that posts its own code keeps working unchanged.
     */
    public function create(array $data): Vendor
    {
        $code = isset($data['code']) ? trim((string) $data['code']) : '';

        if ($code !== '') {
            return Vendor::create(['is_active' => true, ...$data, 'code' => $code]);
        }

        // Two people saving the form in the same instant would read the same
        // highest number and mint the same code; the unique index catches the
        // loser, who re-reads and takes the next one. Retried rather than
        // locked because the alternative — a gap lock on a table with no
        // counter row — does not port to the sqlite the tests run on.
        for ($attempt = 1; ; $attempt++) {
            try {
                return Vendor::create(['is_active' => true, ...$data, 'code' => $this->mintCode()]);
            } catch (QueryException $collision) {
                if ($attempt >= self::MINT_ATTEMPTS || ! $this->isDuplicateCode($collision)) {
                    throw $collision;
                }
            }
        }
    }

    /**
     * The next code in the sequence: one past the highest number already
     * spoken for.
     *
     * withTrashed, because `vendors.code` is unique across soft-deleted rows —
     * an archived `V-0007` still owns its number. Whether it SHOULD is
     * PENDING Q52(b), an open owner question about every master, and a code
     * generator is not the place to answer it: this keeps today's behaviour
     * exactly.
     *
     * Only `V-` followed by digits counts. The live master's `VEN-RESIN`,
     * `VEN-CAPS`, `VEN-LABEL` and `V-DEMO-KPXL` are outside the sequence and
     * never shift it.
     */
    private function mintCode(): string
    {
        $highest = Vendor::withTrashed()
            ->where('code', 'like', self::MINTED_PREFIX.'%')
            ->pluck('code')
            ->map(function (string $code): int {
                $suffix = substr($code, strlen(self::MINTED_PREFIX));

                return $suffix !== '' && ctype_digit($suffix) ? (int) $suffix : 0;
            })
            ->max() ?? 0;

        return self::MINTED_PREFIX.str_pad((string) ($highest + 1), self::MINTED_WIDTH, '0', STR_PAD_LEFT);
    }

    /**
     * Was this the unique index on `code` rejecting the mint, or something
     * else? Anything else is re-thrown: a retry loop that swallowed every
     * database error would turn a real fault into five silent attempts and
     * then a misleading message.
     *
     * SQLSTATE 23000 is integrity-constraint violation across both drivers;
     * the column name narrows it to this index rather than, say, a foreign
     * key. Matched on the message because neither MySQL nor sqlite reports
     * the constraint name in a portable place.
     */
    private function isDuplicateCode(QueryException $exception): bool
    {
        return $exception->getCode() === '23000'
            && str_contains(strtolower($exception->getMessage()), 'code');
    }

    public function update(Vendor $vendor, array $data): Vendor
    {
        $vendor->update($data);

        return $vendor;
    }

    protected function configurationLabel(): string
    {
        return 'vendor';
    }

    /**
     * WHAT REFERENCES A VENDOR — two columns, both RESTRICT.
     *
     * Written from the schema, not from memory, because this list IS the
     * guard: anything omitted here is something the refusal will not name.
     *
     * RESTRICT — the database would refuse the delete too; declared so the
     * refusal counts the rows and names them in business words instead of
     * surfacing a foreign-key error:
     *   purchase_orders.vendor_id      (2026_07_18_160519, restrictOnDelete)
     *   subcontract_orders.vendor_id   (2026_07_19_071003, restrictOnDelete)
     *
     * NOT a dependency, and worth writing down because it reads like one:
     * goods_receipt_notes has NO vendor_id. A GRN reaches its vendor through
     * purchase_order_id, so purchase_orders above already covers every
     * received delivery. Adding a GRN check here would double-count.
     *
     * A vendor carries no Tally identity the ERP owns: tally_ledger_name is a
     * NAME Accounts types in, never a guid the ERP resolves, and no voucher is
     * keyed on it — so archiving a vendor mutates nothing in Tally
     * (DEC-20260817-002 rule 4).
     *
     * @return list<DependencyCheck>
     */
    protected function dependencyChecks(): array
    {
        return [
            DependencyCheck::table('purchase_orders', 'vendor_id')
                ->label('purchase order'),
            DependencyCheck::table('subcontract_orders', 'vendor_id')
                ->label('subcontract order'),
        ];
    }

    protected function configurationHardDeleteAuthorisation(): ?Closure
    {
        return HardDeleteAuthority::callback();
    }
}
