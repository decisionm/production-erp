<?php

namespace App\Modules\Finance\Services;

use App\Modules\Core\Services\AppSettingService;
use App\Modules\Sales\Models\Customer;
use App\Modules\TallySync\Http\Controllers\TallySettingsController;
use App\Modules\TallySync\Models\TallyPendingSalesOrder;
use App\Modules\TallySync\Models\TallyReceivableBill;
use Illuminate\Support\Carbon;

/**
 * WHAT EACH CLIENT OWES, HOW LONG THEY HAVE OWED IT, AND WHAT IS STILL TO SHIP
 * THEM — one row per client, from the position the agent mirrored out of Tally.
 *
 * READ-ONLY AND DERIVED. It owns no table. It reads the two Tally mirrors and
 * joins them to `customers` by the recorded ledger link, and every number it
 * returns is computed at read time from the stored position.
 *
 * IT LIVES IN FINANCE, BESIDE AccountsReceivableService, because that is what
 * it is: a receivables report. The two are deliberately separate and answer
 * different questions — this one reads the position mirrored out of TALLY,
 * where this factory actually raises its sales; its neighbour reads the ERP's
 * own `invoices`, which hold a handful of rows on this instance. Neither
 * blends into the other, and the page names which it is showing.
 *
 * AGE IS NEVER STORED, ALWAYS COMPUTED. A bill that was 29 days overdue when
 * the pull ran is 30 the next morning; a stored age is a number that is wrong
 * by the time anybody reads it. The stored fact is the DUE DATE, which does not
 * change, and `asOf` below is the day the reader is asking about.
 *
 * THE BUCKETS COUNT DAYS PAST DUE, NOT DAYS SINCE THE BILL. The page exists so
 * somebody can chase what is late, and a 90-day-old bill on 120-day terms is
 * not late. Tally's own ageing screen defaults to age-since-bill-date, so the
 * two will not agree column for column and the page says which it is showing.
 * If Accounts want the Tally default instead, that is a one-line change here
 * and a label change on the page — it is deliberately not a hidden setting.
 *
 * A BILL WITH NO DUE DATE IS ITS OWN BUCKET, never folded into "current" and
 * never into "90+". Tally permits a party with no credit terms, and both of
 * those answers would be this service asserting a term the factory never set.
 *
 * SIGNS SURVIVE. A client in credit shows a negative outstanding rather than
 * being flipped positive or dropped: "this client is in credit" is something
 * the person chasing them needs to see.
 */
class ClientOutstandingService
{
    public function __construct(private readonly AppSettingService $settings) {}

    /** Days past due, upper bound inclusive. The last bucket is unbounded. */
    public const BUCKETS = [
        'current' => [null, 0],
        'd1_30' => [1, 30],
        'd31_60' => [31, 60],
        'd61_90' => [61, 90],
        'd90_plus' => [91, null],
    ];

    /**
     * One row per client, ordered by what is most overdue.
     *
     * @return array{
     *     as_of: ?string,
     *     synced_at: ?string,
     *     company: ?string,
     *     clients: array<int, array<string, mixed>>,
     *     totals: array<string, mixed>
     * }
     */
    public function report(?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->startOfDay();

        // SCOPED TO THE BOUND COMPANY, because the SYNC is. A pull replaces
        // one company's rows, and the agent's 409 guard can only refuse a
        // foreign pull once a company is actually bound — so before that, two
        // companies' rows can coexist here. An unscoped read would sum both
        // into one total and label it with whichever row came back first.
        // With nothing bound there is nothing to scope to, and everything is
        // read: that is the honest answer for a single-company instance that
        // has not been through Tally settings yet.
        $company = $this->settings->get(TallySettingsController::KEY_COMPANY);

        $bills = TallyReceivableBill::query()
            ->when($company !== null, fn ($q) => $q->where('tally_company', $company))
            ->get();

        $orders = TallyPendingSalesOrder::query()
            ->when($company !== null, fn ($q) => $q->where('tally_company', $company))
            ->get();

        // The link is by ledger GUID where the customer carries one, and by
        // ledger NAME otherwise — the import writes both, but a customer
        // created by hand may have only the name. A party matching neither is
        // still reported, under the Tally ledger's own name: an unlinked
        // client owing money must not disappear off the page.
        $customersByGuid = Customer::query()
            ->whereNotNull('tally_ledger_guid')
            ->get()
            ->keyBy('tally_ledger_guid');

        $customersByLedgerName = Customer::query()
            ->whereNotNull('tally_ledger_name')
            ->get()
            ->keyBy(fn (Customer $c) => mb_strtolower(trim((string) $c->tally_ledger_name)));

        /** @var array<string, array<string, mixed>> $clients */
        $clients = [];

        foreach ($bills as $bill) {
            $key = $this->partyKey($bill->party_ledger_guid, $bill->party_ledger_name);
            $clients[$key] ??= $this->blankClient($bill->party_ledger_name, $bill->party_ledger_guid, $customersByGuid, $customersByLedgerName);

            $daysPastDue = $this->daysPastDue($bill->due_date, $today);
            $bucket = $this->bucketFor($daysPastDue);

            $amount = (string) $bill->closing_amount;

            $clients[$key]['outstanding_amount'] = bcadd($clients[$key]['outstanding_amount'], $amount, 4);
            $clients[$key]['ageing'][$bucket] = bcadd($clients[$key]['ageing'][$bucket], $amount, 4);

            // The factory's measured all-parties Tally report supplies one
            // closing balance per client, but no bill reference or dates. It
            // is a real outstanding amount and must appear; it is NOT a bill
            // and must not manufacture an invoice count or ageing detail.
            $hasBillDetail = $bill->bill_reference !== null
                || $bill->bill_date !== null
                || $bill->due_date !== null;

            if (! $hasBillDetail) {
                $clients[$key]['balance_only'] = true;
            } else {
                $clients[$key]['bill_count']++;
            }

            if ($daysPastDue !== null && $daysPastDue > 0) {
                $clients[$key]['overdue_amount'] = bcadd($clients[$key]['overdue_amount'], $amount, 4);
                $clients[$key]['oldest_overdue_days'] = max($clients[$key]['oldest_overdue_days'] ?? 0, $daysPastDue);
            }

            if ($hasBillDetail) {
                $clients[$key]['bills'][] = [
                    'bill_reference' => $bill->bill_reference,
                    'bill_date' => $bill->bill_date?->toDateString(),
                    'due_date' => $bill->due_date?->toDateString(),
                    'closing_amount' => $amount,
                    'opening_amount' => $bill->opening_amount === null ? null : (string) $bill->opening_amount,
                    // The number the page's "Outstanding days" column shows. Null
                    // when Tally states no due date — the column reads "—", it does
                    // not read 0, which would mean "due today".
                    'days_past_due' => $daysPastDue,
                    'days_since_bill' => $this->daysBetween($bill->bill_date, $today),
                    'bucket' => $bucket,
                ];
            }
        }

        foreach ($orders as $order) {
            $key = $this->partyKey($order->party_ledger_guid, $order->party_ledger_name);
            $clients[$key] ??= $this->blankClient($order->party_ledger_name, $order->party_ledger_guid, $customersByGuid, $customersByLedgerName);

            if ($order->pending_amount !== null) {
                $clients[$key]['pending_order_amount'] = bcadd(
                    $clients[$key]['pending_order_amount'],
                    (string) $order->pending_amount,
                    4,
                );
            } else {
                // A pending line Tally priced no value for is still a real
                // pending order. It is counted and flagged, never dropped and
                // never given a made-up value.
                $clients[$key]['pending_orders_without_value']++;
            }

            $clients[$key]['pending_order_count']++;

            $clients[$key]['pending_orders'][] = [
                'order_reference' => $order->order_reference,
                'order_date' => $order->order_date?->toDateString(),
                'due_date' => $order->due_date?->toDateString(),
                'stock_item_name' => $order->stock_item_name,
                'pending_quantity' => $order->pending_quantity === null ? null : (string) $order->pending_quantity,
                'quantity_unit' => $order->quantity_unit,
                'pending_amount' => $order->pending_amount === null ? null : (string) $order->pending_amount,
                'days_overdue' => $this->daysPastDue($order->due_date, $today),
            ];
        }

        $rows = array_values($clients);

        // Most overdue first, then largest outstanding — the order somebody
        // working a collections list actually wants.
        usort($rows, function (array $a, array $b): int {
            $byDays = ($b['oldest_overdue_days'] ?? -1) <=> ($a['oldest_overdue_days'] ?? -1);

            return $byDays !== 0 ? $byDays : bccomp($b['outstanding_amount'], $a['outstanding_amount'], 4);
        });

        $first = $bills->first() ?? $orders->first();

        return [
            'as_of' => $first?->as_of?->toDateString(),
            'synced_at' => $first?->tally_synced_at?->toIso8601String(),
            'company' => $first?->tally_company,
            'clients' => $rows,
            'totals' => $this->totals($rows),
        ];
    }

    /**
     * A party is identified by its ledger GUID when Tally gave one, and by its
     * name otherwise. Mixing the two under one key would merge two different
     * parties whose names happen to match; keeping them apart is the safer
     * failure, and the page shows both rows for a person to reconcile.
     */
    private function partyKey(?string $guid, string $name): string
    {
        return $guid !== null && $guid !== '' ? 'guid:'.$guid : 'name:'.mb_strtolower(trim($name));
    }

    /** @return array<string, mixed> */
    private function blankClient(string $ledgerName, ?string $guid, $byGuid, $byName): array
    {
        $customer = ($guid !== null && $guid !== '' ? $byGuid->get($guid) : null)
            ?? $byName->get(mb_strtolower(trim($ledgerName)));

        return [
            // The ERP customer where one is linked, so the page can offer a
            // link through to the customer record; null where nobody has
            // linked this ledger yet, which is an honest and visible state.
            'customer_id' => $customer?->id,
            'customer_code' => $customer?->code,
            'customer_name' => $customer?->name,
            // THE ADDRESS A FOLLOW-UP DRAFT FILLS ITSELF IN WITH, once a
            // ledger has been matched to a customer. It is null on every row
            // on this instance today — nobody has linked a Tally party yet —
            // and that is exactly why it is here: the follow-up control
            // composes the same draft with or without it, and this half stops
            // being empty by itself as Accounts match the ledgers up.
            // IT GRANTS NOBODY THE POWER TO MAIL A CLIENT. Nothing on this
            // page sends: the draft opens in the operator's own mail client
            // and a person decides whether to send it.
            'customer_email' => $this->contactEmail($customer),
            'party_ledger_name' => $ledgerName,
            'party_ledger_guid' => $guid,
            'is_linked' => $customer !== null,
            // True when Tally supplied only the party closing balance. The UI
            // names that limitation instead of showing a made-up bill count.
            'balance_only' => false,
            'outstanding_amount' => '0.0000',
            'overdue_amount' => '0.0000',
            'pending_order_amount' => '0.0000',
            'pending_order_count' => 0,
            'pending_orders_without_value' => 0,
            'bill_count' => 0,
            'oldest_overdue_days' => null,
            'ageing' => array_map(fn () => '0.0000', self::BUCKETS) + ['no_due_date' => '0.0000'],
            'bills' => [],
            'pending_orders' => [],
        ];
    }

    /**
     * The linked customer's address, or null. A blank or whitespace-only
     * column is NOT an address — it would compose a recipient that is not
     * one — so it is reported exactly as no linked customer is: null. The
     * reader has one thing to test, not three.
     */
    private function contactEmail(?Customer $customer): ?string
    {
        $email = trim((string) $customer?->email);

        return $email === '' ? null : $email;
    }

    /**
     * Days past the due date, negative when it has not arrived yet, null when
     * Tally states no due date at all.
     */
    private function daysPastDue(?Carbon $dueDate, Carbon $today): ?int
    {
        return $dueDate === null ? null : $dueDate->copy()->startOfDay()->diffInDays($today, false);
    }

    private function daysBetween(?Carbon $date, Carbon $today): ?int
    {
        return $date === null ? null : $date->copy()->startOfDay()->diffInDays($today, false);
    }

    /** Which ageing column this bill belongs in. */
    private function bucketFor(?int $daysPastDue): string
    {
        if ($daysPastDue === null) {
            return 'no_due_date';
        }

        foreach (self::BUCKETS as $name => [$from, $to]) {
            $aboveFloor = $from === null || $daysPastDue >= $from;
            $belowCeiling = $to === null || $daysPastDue <= $to;

            if ($aboveFloor && $belowCeiling) {
                return $name;
            }
        }

        return 'd90_plus';
    }

    /** @return array<string, mixed> */
    private function totals(array $rows): array
    {
        $ageing = array_map(fn () => '0.0000', self::BUCKETS) + ['no_due_date' => '0.0000'];
        $outstanding = '0.0000';
        $overdue = '0.0000';
        $pending = '0.0000';
        $bills = 0;
        $pendingOrders = 0;

        foreach ($rows as $row) {
            $outstanding = bcadd($outstanding, $row['outstanding_amount'], 4);
            $overdue = bcadd($overdue, $row['overdue_amount'], 4);
            $pending = bcadd($pending, $row['pending_order_amount'], 4);
            $bills += $row['bill_count'];
            $pendingOrders += $row['pending_order_count'];

            foreach ($row['ageing'] as $bucket => $amount) {
                $ageing[$bucket] = bcadd($ageing[$bucket], $amount, 4);
            }
        }

        return [
            'clients' => count($rows),
            'outstanding_amount' => $outstanding,
            'overdue_amount' => $overdue,
            'pending_order_amount' => $pending,
            'bill_count' => $bills,
            'pending_order_count' => $pendingOrders,
            'ageing' => $ageing,
        ];
    }
}
