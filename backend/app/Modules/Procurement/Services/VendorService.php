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

    public function __construct(private readonly ProcurementDocumentQuery $query) {}

    /**
     * The vendor list, optionally narrowed by a search term.
     *
     * SEARCH IS NOT OPTIONAL FURNITURE HERE. The master held four demo rows for
     * months, so paging alone looked sufficient; the import from Tally ledgers
     * took it to 628 in a single run and the page became a wall — thirteen
     * screens and no way to type a supplier's name.
     *
     * The clause is ProcurementDocumentQuery::whereVendorMatches, already "the
     * one vendor clause every list's `q` shares", so this page and the
     * purchase-order filter can never disagree about what matching a vendor
     * means. Name OR code, because the code is what the printed paperwork
     * carries.
     *
     * Server-side, deliberately: filtering the current page in the browser
     * would search 50 rows out of 628 and answer "no such vendor" for one that
     * plainly exists — the defect four pickers in this repo were just fixed
     * for. A blank or whitespace term is NO search, not a search for nothing.
     *
     * `$classifications` narrows to vendors holding at least one of the given
     * classifications (DEC-20260902-026) — a filter, never a block, so an
     * unclassified vendor never disappears from the plain list. `$unclassified`
     * widens that same filter to also include vendors with none at all, or
     * — with no classifications passed — narrows to only those.
     */
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

    /**
     * The prefix and width of a minted vendor code — "V-0001".
     *
     * `vendors.code` is unique. The column itself is a plain string and far
     * wider than 32; the 32 is `StoreVendorRequest`'s cap on a SUPPLIED code,
     * which a minted code never passes through — it is kept as the yardstick
     * anyway so both kinds of code fit the same column comfortably. A
     * four-digit number leaves the whole of it unused and simply gets longer
     * past 9999 rather than wrapping or truncating.
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
     * the 633 Sundry Creditors ledgers mirrored in the rehearsal database, 48
     * supplier names slug past 32 characters and truncating them to fit
     * collides immediately. The counts stay and the names do not: 633 and 48
     * are measurements, while supplier identity is Owner/Accounts (FC-06) and
     * belongs in the ledger rather than here. A slug also freezes the spelling a
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
            $vendor = Vendor::create(['is_active' => true, ...$data, 'code' => $code]);

            return $this->syncClassifications($vendor, $data);
        }

        // Two people saving the form in the same instant would read the same
        // highest number and mint the same code; the unique index catches the
        // loser, who re-reads and takes the next one. Retried rather than
        // locked because the alternative — a gap lock on a table with no
        // counter row — does not port to the sqlite the tests run on.
        for ($attempt = 1; ; $attempt++) {
            try {
                $vendor = Vendor::create(['is_active' => true, ...$data, 'code' => $this->mintCode()]);

                return $this->syncClassifications($vendor, $data);
            } catch (QueryException $collision) {
                if ($attempt >= self::MINT_ATTEMPTS || ! $this->isDuplicateCode($collision)) {
                    throw $collision;
                }
            }
        }
    }

    /**
     * Replace a vendor's classifications wholesale with what was submitted —
     * DEC-20260902-026: set by a person, never derived from a Tally ledger
     * group. Absent from `$data` entirely (`sometimes` on the request rule),
     * an existing edit that doesn't touch classifications leaves them alone.
     */
    private function syncClassifications(Vendor $vendor, array $data): Vendor
    {
        if (array_key_exists('classifications', $data)) {
            $vendor->classifications()->delete();
            foreach (array_unique($data['classifications']) as $value) {
                $vendor->classifications()->create(['classification' => $value]);
            }
        }

        return $vendor;
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
     * SQLSTATE 23000 covers every integrity-constraint violation, so it alone
     * is far too wide: a NOT NULL failure on `name` carries it too, and the
     * message carries the whole INSERT — which names the `code` column on
     * every mint — so matching the column name alone would retry any of them
     * five times over. The UNIQUENESS wording is what narrows it. MySQL says
     * "Duplicate entry ... for key 'vendors.vendors_code_unique'"; sqlite says
     * "UNIQUE constraint failed: vendors.code". A NOT NULL violation says
     * "NOT NULL constraint failed" and matches neither. Read from the message
     * because neither driver reports the constraint name anywhere portable.
     */
    private function isDuplicateCode(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        // Cast: PDO sets the SQLSTATE as a string, but a driver or a wrapper
        // that hands it back as an int must not slip past the check.
        return (string) $exception->getCode() === '23000'
            && str_contains($message, 'code')
            && (str_contains($message, 'duplicate entry') || str_contains($message, 'unique constraint'));
    }

    public function update(Vendor $vendor, array $data): Vendor
    {
        $vendor->update($data);

        return $this->syncClassifications($vendor, $data);
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
     * vendor_classifications.vendor_id CASCADEs (2026_09_03_100200,
     * DEC-20260902-026) — the schema backstop (DependencyReport::cascadeGaps)
     * refuses any hard delete this check does not cover, so a classified
     * vendor is not provably unused: remove its classifications, or
     * deactivate instead.
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
            DependencyCheck::table('supplier_bills', 'vendor_id')
                ->label('supplier bill'),
            DependencyCheck::table('vendor_classifications', 'vendor_id')
                ->label('classification')
                ->cascadeSide(),
        ];
    }

    protected function configurationHardDeleteAuthorisation(): ?Closure
    {
        return HardDeleteAuthority::callback();
    }
}
