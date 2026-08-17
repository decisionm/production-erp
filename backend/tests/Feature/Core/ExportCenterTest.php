<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Exports\AbstractExportKind;
use App\Modules\Core\Exports\CsvStreamer;
use App\Modules\Core\Exports\ExportCapExceededException;
use App\Modules\Core\Exports\ExportKind;
use App\Modules\Core\Exports\ExportRegistry;
use App\Modules\Core\Models\ExportRun;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The Download / Export Center's generic contract (MASTER-PLAN Phase 4.5),
 * pinned over stub kinds registered only here so no module's data shape is
 * in the way:
 *
 *   - the catalogue lists only the kinds the reader may run, a BLOCKED kind
 *     included (with its reason) for a reader with permission;
 *   - POST: 404 unknown kind · 403 no permission · 409 blocked with the
 *     reason · 422 over the cap with the exact sentence · else a streamed
 *     CSV attachment named {kind}-{YYYYMMDD-HHMM}.csv in factory time;
 *   - the bytes: UTF-8 BOM, CRLF, RFC-4180 quoting, the formula guard
 *     (mirroring frontend/src/lib/csv.ts) — and a negative number is NOT
 *     quoted or prefixed;
 *   - EVERY POST writes an ExportRun for the caller — streamed (completed,
 *     row count, sha256 of the bytes) and refused (reason) alike;
 *   - GET /exports/runs shows the caller's own runs only, newest first.
 */
class ExportCenterTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'CEC FORMAT = BLOCKED — SOURCE DOCUMENT REQUIRED';

    protected function setUp(): void
    {
        parent::setUp();

        $registry = app(ExportRegistry::class);
        $registry->register($this->stubKind('widgets', 'production', ['production.view', 'production.manage'], [
            ['id' => 1, 'name' => 'Cap, 28mm', 'note' => '=SUM(A1)', 'delta' => -0.4, 'meta' => ['group' => 'PET']],
            ['id' => 2, 'name' => 'Preform "A"', 'note' => '+91 98', 'delta' => 12, 'meta' => ['group' => 'PET']],
            ['id' => 3, 'name' => "two\nlines", 'note' => '-abc', 'delta' => -12.5, 'meta' => ['group' => null]],
            ['id' => 4, 'name' => 'at', 'note' => '@cmd', 'delta' => 0, 'meta' => ['group' => 'HDPE']],
            ['id' => 5, 'name' => 'tab', 'note' => "\tx", 'delta' => null, 'meta' => ['group' => 'HDPE']],
        ]));
        $registry->register($this->stubKind('ledgers', 'finance', ['finance.view', 'finance.manage'], [
            ['id' => 10, 'name' => 'Sales', 'note' => null, 'delta' => 1, 'meta' => []],
        ]));
        $registry->register($this->stubKind('cec', 'production', ['production.view'], [], blockedReason: self::REASON));
    }

    // ---- catalogue -----------------------------------------------------------

    public function test_the_catalogue_lists_only_the_kinds_the_reader_may_run_blocked_ones_included_with_their_reason(): void
    {
        $this->actAs(['production.view']);
        $rows = $this->getJson('/api/v1/exports')->assertOk()->json('data');
        $byKey = collect($rows)->keyBy('key');

        $this->assertSame(['widgets', 'cec'], $byKey->keys()->all(), 'production.view sees the two production kinds and not the finance one');
        $this->assertSame('available', $byKey['widgets']['status']);
        $this->assertNull($byKey['widgets']['blocked_reason']);
        $this->assertSame('production', $byKey['widgets']['module']);
        $this->assertSame((int) config('exports.row_cap'), $byKey['widgets']['row_cap']);
        $this->assertSame('blocked', $byKey['cec']['status']);
        $this->assertSame(self::REASON, $byKey['cec']['blocked_reason']);

        // The filter schema is derived from the kind's rules — one field
        // per top-level rule, typed for the form.
        $this->assertSame([
            ['name' => 'from', 'type' => 'date', 'required' => false, 'multiple' => false, 'options' => null],
            ['name' => 'status', 'type' => 'select', 'required' => false, 'multiple' => true, 'options' => ['pending', 'synced']],
            ['name' => 'limit', 'type' => 'integer', 'required' => false, 'multiple' => false, 'options' => null],
        ], $byKey['widgets']['filters']);

        $this->app['auth']->forgetGuards();

        $this->actAs(['finance.view']);
        $this->assertSame(['ledgers'], collect($this->getJson('/api/v1/exports')->assertOk()->json('data'))->pluck('key')->all());

        $this->app['auth']->forgetGuards();

        // A reader with no relevant permission at all: an empty catalogue, not a 403.
        $this->actAs([]);
        $this->getJson('/api/v1/exports')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_center_needs_a_login(): void
    {
        $this->getJson('/api/v1/exports')->assertUnauthorized();
        $this->postJson('/api/v1/exports/widgets')->assertUnauthorized();
        $this->getJson('/api/v1/exports/runs')->assertUnauthorized();
    }

    // ---- refusals -------------------------------------------------------------

    public function test_an_unknown_kind_is_404_a_kind_without_permission_403_and_a_blocked_kind_409_with_its_reason(): void
    {
        $user = $this->actAs(['production.view']);

        $this->postJson('/api/v1/exports/no_such_kind', [])->assertNotFound();
        $this->postJson('/api/v1/exports/ledgers', [])->assertForbidden();

        $blocked = $this->postJson('/api/v1/exports/cec', [])->assertStatus(409);
        $this->assertSame(['message' => self::REASON, 'kind' => 'cec'], $blocked->json());

        // The blocked attempt is on the record; the 404 and 403 wrote nothing
        // (no kind / no standing — there is nothing to audit against).
        $runs = ExportRun::query()->orderBy('id')->get();
        $this->assertCount(1, $runs);
        $this->assertSame($user->id, $runs[0]->user_id);
        $this->assertSame('cec', $runs[0]->kind);
        $this->assertFalse($runs[0]->completed);
        $this->assertSame(self::REASON, $runs[0]->refusal_reason);
        $this->assertSame(0, $runs[0]->row_count);
    }

    public function test_the_body_is_validated_against_the_kinds_own_rules(): void
    {
        $this->actAs(['production.view']);

        $this->postJson('/api/v1/exports/widgets', ['from' => 'yesterday'])->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->postJson('/api/v1/exports/widgets', ['status' => ['nope']])->assertUnprocessable()->assertJsonValidationErrors('status.0');
        $this->assertSame(0, ExportRun::query()->count(), 'a malformed body never reached the Center — nothing to audit');

        // A scalar where the rules say array is read as a one-element list,
        // as the module's own List…Request would read `?status=pending`.
        $this->postJson('/api/v1/exports/widgets', ['status' => 'pending'])->assertOk();
        $this->assertSame(['status' => ['pending']], ExportRun::query()->sole()->filters);
    }

    public function test_over_the_cap_the_server_refuses_with_the_exact_sentence_and_records_the_attempt(): void
    {
        config(['exports.row_cap' => 2]);
        $user = $this->actAs(['production.view']);

        $refused = $this->postJson('/api/v1/exports/widgets', ['from' => '2026-08-01'])->assertUnprocessable();
        $this->assertSame('5 rows match; the cap is 2 — narrow the range', $refused->json('message'));
        $this->assertSame(5, $refused->json('matched'));
        $this->assertSame(2, $refused->json('cap'));

        $run = ExportRun::query()->sole();
        $this->assertSame($user->id, $run->user_id);
        $this->assertSame('widgets', $run->kind);
        $this->assertFalse($run->completed);
        $this->assertSame(5, $run->row_count);
        $this->assertNull($run->sha256);
        $this->assertSame('5 rows match; the cap is 2 — narrow the range', $run->refusal_reason);
        $this->assertSame(['from' => '2026-08-01'], $run->filters);

        // The sentence carries thousands separators, as the Center shows it.
        config(['exports.row_cap' => 5000]);
        $this->assertSame('5,213 rows match; the cap is 5,000 — narrow the range', ExportCapExceededException::sentence(5213, 5000));

        // Exactly the cap is fine.
        config(['exports.row_cap' => 5]);
        $this->postJson('/api/v1/exports/widgets', [])->assertOk();
    }

    // ---- the file --------------------------------------------------------------

    public function test_the_file_is_a_streamed_csv_attachment_with_bom_crlf_quoting_and_the_formula_guard(): void
    {
        Carbon::setTestNow('2026-08-17 09:05:00'); // UTC → 14:35 IST
        $user = $this->actAs(['production.view']);

        $response = $this->postJson('/api/v1/exports/widgets', ['status' => ['pending']])->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertDownload('widgets-20260817-1435.csv');

        $bytes = $response->streamedContent();

        $this->assertStringStartsWith(CsvStreamer::BOM, $bytes, 'UTF-8 BOM so Excel reads it as UTF-8');
        $body = substr($bytes, strlen(CsvStreamer::BOM));
        $this->assertStringNotContainsString(CsvStreamer::BOM, $body, 'the BOM appears once');

        $expected = implode("\r\n", [
            'id,name,note,delta,group',
            '1,"Cap, 28mm",\'=SUM(A1),-0.4,PET',
            '2,"Preform ""A""",\'+91 98,12,PET',
            "3,\"two\nlines\",'-abc,-12.5,",
            '4,at,\'@cmd,0,HDPE',
            "5,tab,'\tx,,HDPE",
            '',
        ]);
        $this->assertSame($expected, $body);
        $this->assertStringNotContainsString("\n\r\n", $body, 'no bare LF line ends');

        // The run: completed, the streamed row count, the sha256 of exactly
        // these bytes, the caller, the filters, the file name.
        $run = ExportRun::query()->sole();
        $this->assertSame($user->id, $run->user_id);
        $this->assertTrue($run->completed);
        $this->assertSame(5, $run->row_count);
        $this->assertSame(hash('sha256', $bytes), $run->sha256);
        $this->assertNull($run->refusal_reason);
        $this->assertSame('widgets-20260817-1435.csv', $run->file_name);
        $this->assertSame(['status' => ['pending']], $run->filters);
        $this->assertSame('widgets', $run->kind);
    }

    public function test_the_file_name_is_stamped_in_factory_time_not_utc(): void
    {
        // 23:40 UTC on the 16th is 05:10 IST on the 17th.
        Carbon::setTestNow('2026-08-16 23:40:00');
        $this->actAs(['production.view']);

        $this->postJson('/api/v1/exports/widgets', [])->assertOk()->assertDownload('widgets-20260817-0510.csv');
    }

    // ---- runs -------------------------------------------------------------------

    public function test_the_runs_endpoint_shows_only_the_callers_own_runs_newest_first(): void
    {
        Carbon::setTestNow('2026-08-17 06:00:00');
        $me = $this->actAs(['production.view']);
        $this->postJson('/api/v1/exports/widgets', [])->assertOk()->streamedContent();
        Carbon::setTestNow('2026-08-17 06:01:00');
        $this->postJson('/api/v1/exports/cec', [])->assertStatus(409);
        Carbon::setTestNow('2026-08-17 06:02:00');
        config(['exports.row_cap' => 1]);
        $this->postJson('/api/v1/exports/widgets', ['from' => '2026-08-17'])->assertUnprocessable();
        config(['exports.row_cap' => 5000]);
        $this->app['auth']->forgetGuards();

        // Someone else's run, later than all of mine.
        Carbon::setTestNow('2026-08-17 06:03:00');
        $this->actAs(['production.view', 'finance.view']);
        $this->postJson('/api/v1/exports/ledgers', [])->assertOk()->streamedContent();
        $this->assertSame(4, ExportRun::query()->count());
        $this->app['auth']->forgetGuards();

        Sanctum::actingAs($me);
        $rows = $this->getJson('/api/v1/exports/runs')->assertOk()->json('data');

        $this->assertCount(3, $rows, 'my three runs, not the other user\'s');
        $this->assertSame(['widgets', 'cec', 'widgets'], array_column($rows, 'kind'));
        $this->assertSame(
            ['2026-08-17T06:02:00+00:00', '2026-08-17T06:01:00+00:00', '2026-08-17T06:00:00+00:00'],
            array_column($rows, 'created_at'),
            'newest first',
        );

        // The cap refusal.
        $this->assertFalse($rows[0]['completed']);
        $this->assertSame('5 rows match; the cap is 1 — narrow the range', $rows[0]['refusal_reason']);
        $this->assertSame(['from' => '2026-08-17'], $rows[0]['filters']);
        // The blocked attempt.
        $this->assertFalse($rows[1]['completed']);
        $this->assertSame(self::REASON, $rows[1]['refusal_reason']);
        // The download.
        $this->assertTrue($rows[2]['completed']);
        $this->assertSame(5, $rows[2]['row_count']);
        $this->assertSame(64, strlen($rows[2]['sha256']));
        $this->assertNull($rows[2]['refusal_reason']);
        $this->assertStringStartsWith('widgets-', $rows[2]['file_name']);
        $this->assertSame([], (array) $rows[2]['filters']);
    }

    public function test_the_runs_endpoint_is_capped_at_the_configured_count(): void
    {
        config(['exports.runs_shown' => 2]);
        $this->actAs(['production.view']);
        foreach (range(1, 3) as $ignored) {
            $this->postJson('/api/v1/exports/widgets', [])->assertOk()->streamedContent();
        }

        $this->getJson('/api/v1/exports/runs')->assertOk()->assertJsonCount(2, 'data');
    }

    // ---- registry -----------------------------------------------------------------

    public function test_a_kind_key_cannot_be_registered_twice(): void
    {
        $this->expectException(\LogicException::class);
        app(ExportRegistry::class)->register($this->stubKind('widgets', 'production', ['production.view'], []));
    }

    // ---- helpers --------------------------------------------------------------------

    /** @param  list<string>  $permissions */
    private function actAs(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * A kind over an in-memory list. `from` narrows nothing (the stub has
     * no dates) — it exists so a filter round-trips into the run record.
     *
     * @param  list<string>  $permissionAny
     * @param  list<array<string, mixed>>  $data
     */
    private function stubKind(string $key, string $module, array $permissionAny, array $data, ?string $blockedReason = null): ExportKind
    {
        return new class($key, $module, $permissionAny, $data, $blockedReason) extends AbstractExportKind
        {
            public function __construct(
                private readonly string $key,
                private readonly string $module,
                private readonly array $permissionAny,
                private readonly array $data,
                private readonly ?string $blockedReason,
            ) {}

            public function key(): string
            {
                return $this->key;
            }

            public function label(): string
            {
                return ucfirst($this->key);
            }

            public function module(): string
            {
                return $this->module;
            }

            public function permissionAny(): array
            {
                return $this->permissionAny;
            }

            public function filterRules(): array
            {
                return [
                    'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                    'status' => ['sometimes', 'nullable', 'array'],
                    'status.*' => ['in:pending,synced'],
                    'limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
                ];
            }

            public function status(): string
            {
                return $this->blockedReason === null ? self::STATUS_AVAILABLE : self::STATUS_BLOCKED;
            }

            public function blockedReason(): ?string
            {
                return $this->blockedReason;
            }

            public function columns(?Authenticatable $reader): array
            {
                return ['id' => 'id', 'name' => 'name', 'note' => 'note', 'delta' => 'delta', 'group' => 'meta.group'];
            }

            public function rows(array $filters, ?Authenticatable $reader): iterable
            {
                foreach (array_slice($this->data, 0, $filters['limit'] ?? null) as $row) {
                    yield $row;
                }
            }

            public function count(array $filters, ?Authenticatable $reader): int
            {
                return count(array_slice($this->data, 0, $filters['limit'] ?? null));
            }
        };
    }
}
