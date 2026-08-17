<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Exports\CsvStreamer;
use App\Modules\Core\Exports\ExportBlockedException;
use App\Modules\Core\Exports\ExportCapExceededException;
use App\Modules\Core\Exports\ExportKind;
use App\Modules\Core\Exports\ExportRegistry;
use App\Modules\Core\Models\ExportRun;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Download / Export Center's one write path. Every run — streamed or
 * refused — leaves an ExportRun, so the audit says who tried what.
 *
 * run() in order: a BLOCKED kind refuses (409) before anything is counted;
 * then ONE count over the kind's query against its cap — over the cap the
 * server refuses (422) with the sentence naming both numbers, never a
 * truncated file; else the rows are streamed as CSV, one at a time, and
 * the run is stamped completed (row count, sha256 of the bytes) when the
 * last byte is out.
 */
class ExportService
{
    public function __construct(
        private readonly ExportRegistry $registry,
        private readonly CsvStreamer $streamer,
    ) {}

    /**
     * The kinds this reader may run (a blocked kind is listed with its reason).
     *
     * @return list<array<string, mixed>>
     */
    public function catalogue(?Authenticatable $reader): array
    {
        return $this->registry->catalogue($reader);
    }

    /**
     * @param  array<string, mixed>  $filters  the validated body (ExportRequest)
     *
     * @throws ExportBlockedException
     * @throws ExportCapExceededException
     */
    public function run(ExportKind $kind, array $filters, Authenticatable $user): StreamedResponse
    {
        // A per-run copy: a kind may memoise between count() and rows() (the
        // report kinds do), and the registry's instance is long-lived — the
        // memo must die with the run, never leak into the next request or
        // the next test.
        $kind = clone $kind;
        $fileName = $this->fileName($kind);

        if ($kind->status() === ExportKind::STATUS_BLOCKED) {
            $reason = $kind->blockedReason() ?? 'This export is blocked.';
            $this->record($user, $kind, $filters, $fileName, 0, $reason);

            throw new ExportBlockedException($kind, $reason);
        }

        $matched = $kind->count($filters, $user);
        $cap = $kind->rowCap();

        if ($matched > $cap) {
            $refusal = new ExportCapExceededException($kind, $matched, $cap);
            $this->record($user, $kind, $filters, $fileName, $matched, $refusal->getMessage());

            throw $refusal;
        }

        $run = $this->record($user, $kind, $filters, $fileName, $matched, null);

        return $this->streamer->stream(
            $fileName,
            $kind->columns($user),
            $kind->rows($filters, $user),
            function (int $rows, string $sha256) use ($run): void {
                $run->forceFill(['row_count' => $rows, 'sha256' => $sha256, 'completed' => true])->save();
            },
        );
    }

    /**
     * The caller's own runs, newest first, config('exports.runs_shown') of them.
     *
     * @return Collection<int, ExportRun>
     */
    public function runsFor(Authenticatable $user): Collection
    {
        return ExportRun::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit((int) config('exports.runs_shown', 50))
            ->get();
    }

    /**
     * `{kind}-{YYYYMMDD-HHMM}.csv` in FACTORY time — the file is named for
     * the moment the person asked, on the clock they read. app.timezone is
     * UTC and stays UTC (CLAUDE.md); the wall clock is localised here.
     */
    public function fileName(ExportKind $kind): string
    {
        $stamp = now()->setTimezone(config('tally-sync.factory_timezone'))->format('Ymd-Hi');

        return "{$kind->key()}-{$stamp}.csv";
    }

    /** @param  array<string, mixed>  $filters */
    private function record(Authenticatable $user, ExportKind $kind, array $filters, string $fileName, int $rowCount, ?string $refusalReason): ExportRun
    {
        return ExportRun::query()->create([
            'user_id' => $user->getAuthIdentifier(),
            'kind' => $kind->key(),
            'filters' => $filters,
            'row_count' => $rowCount,
            'file_name' => $fileName,
            'sha256' => null,
            'completed' => false,
            'refusal_reason' => $refusalReason,
            'created_at' => now(),
        ]);
    }
}
