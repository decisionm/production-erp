<?php

namespace App\Modules\TallySync\Exports;

use App\Modules\Core\Exports\AbstractExportKind;
use App\Modules\TallySync\Http\Requests\ListTallySyncEntriesRequest;
use App\Modules\TallySync\Http\Resources\TallySyncEntryResource;
use App\Modules\TallySync\Services\TallySyncQueryService;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The sync queue as a file — GET /tally-sync/entries, downloaded: the SAME
 * filters (ListTallySyncEntriesRequest's rules, delegated), the SAME query
 * and order (TallySyncQueryService::cursor beside paginate), and every row
 * built THROUGH TallySyncEntryResource for THIS reader, so the resource's
 * gating is inherited exactly and can never drift from the screen.
 *
 * FC-06 ON THE FILE — exactly as on the screen. The resource withholds the
 * supplier's identity (root `party`) and Tally's rejection text
 * (`error_message`) on a supplier-party voucher for a reader who may not
 * read purchase details, and says so beside the null (`party_withheld`,
 * `error_withheld`). The file keeps both columns for every reader — a
 * tally-sync.view reader sees the CUSTOMER on a Delivery Note on screen and
 * must see it in the file — and writes the words "withheld (FC-06)" in the
 * cell the screen withholds, never a blank that would read as "no party" /
 * "no error". Nothing here re-judges permissions: the cell is what the
 * resource emitted for this reader, or its withheld note.
 */
class TallySyncEntriesExport extends AbstractExportKind
{
    public function __construct(private readonly TallySyncQueryService $queries) {}

    public function key(): string
    {
        return 'tally_sync_entries';
    }

    public function label(): string
    {
        return 'Tally sync entries';
    }

    public function module(): string
    {
        return 'tally-sync';
    }

    public function permissionAny(): array
    {
        return ['tally-sync.view', 'tally-sync.manage'];
    }

    /** The list endpoint's own grammar, not a copy of it. */
    public function filterRules(): array
    {
        return (new ListTallySyncEntriesRequest)->rules();
    }

    public const WITHHELD_CELL = 'withheld (FC-06)';

    public function columns(?Authenticatable $reader): array
    {
        return [
            'id' => 'id',
            'voucher_type' => 'tally_voucher_type',
            'category' => 'category.label',
            'voucher_number' => 'document_number',
            'voucher_date' => 'business_date',
            'status' => 'status',
            'party' => 'party',
            'attempts' => 'attempts',
            'error_message' => 'error_message',
            'created_at' => 'created_at',
            'delivered_at' => 'delivered_at',
            'synced_at' => 'synced_at',
            'held' => 'held',
        ];
    }

    /**
     * One resource row per entry, as the list endpoint would emit it for
     * this reader (TallySyncEntryResource::resolve — the same array, the
     * MissingValues of the show-only keys dropped), plus `held`: whether
     * the resource's own `hold` verdict is present — the boolean the file
     * carries where the screen shows the hold copy.
     */
    public function rows(array $filters, ?Authenticatable $reader): iterable
    {
        $request = $this->requestFor($reader);

        foreach ($this->queries->cursor($filters, $reader) as $entry) {
            $row = TallySyncEntryResource::make($entry)->resolve($request);
            $row['held'] = ($row['hold'] ?? null) !== null;
            // The screen's withheld notes become the cell's words.
            if (($row['party'] ?? null) === null && isset($row['party_withheld'])) {
                $row['party'] = self::WITHHELD_CELL;
            }
            if (($row['error_message'] ?? null) === null && isset($row['error_withheld'])) {
                $row['error_message'] = self::WITHHELD_CELL;
            }

            yield $row;
        }
    }

    public function count(array $filters, ?Authenticatable $reader): int
    {
        return $this->queries->count($filters, $reader);
    }
}
