<?php

namespace App\Modules\Finance\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Finance\Exceptions\UnbalancedJournalEntryException;
use App\Modules\Finance\Models\Enums\JournalEntryStatus;
use App\Modules\Finance\Models\JournalEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Balance is enforced at creation, not deferred to posting — an entry that
 * doesn't balance is never allowed to exist as a draft in the first place.
 * post() is therefore just a status transition, nothing more to validate.
 */
class JournalEntryService
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return JournalEntry::query()
            ->with(['lines.glAccount'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{entry_date: string, reference?: string, memo?: string, lines: array<int, array{gl_account_id: int, debit: string, credit: string, memo?: string}>}  $data
     */
    public function create(array $data, ?int $createdBy): JournalEntry
    {
        return DB::transaction(function () use ($data, $createdBy) {
            $totalDebit = '0.0000';
            $totalCredit = '0.0000';

            foreach ($data['lines'] as $line) {
                $totalDebit = bcadd($totalDebit, (string) ($line['debit'] ?? 0), 4);
                $totalCredit = bcadd($totalCredit, (string) ($line['credit'] ?? 0), 4);
            }

            if (bccomp($totalDebit, $totalCredit, 4) !== 0) {
                throw UnbalancedJournalEntryException::make($totalDebit, $totalCredit);
            }

            $entry = JournalEntry::create([
                'entry_date' => $data['entry_date'],
                'reference' => $data['reference'] ?? null,
                'memo' => $data['memo'] ?? null,
                'status' => JournalEntryStatus::Draft,
                'created_by' => $createdBy,
            ]);

            foreach ($data['lines'] as $line) {
                $entry->lines()->create([
                    'gl_account_id' => $line['gl_account_id'],
                    'debit' => $line['debit'] ?? 0,
                    'credit' => $line['credit'] ?? 0,
                    'memo' => $line['memo'] ?? null,
                ]);
            }

            return $entry->load(['lines.glAccount']);
        });
    }

    public function post(JournalEntry $entry): JournalEntry
    {
        if ($entry->status !== JournalEntryStatus::Draft) {
            throw InvalidStatusTransitionException::make(
                'journal entry',
                $entry->status->value,
                JournalEntryStatus::Posted->value,
            );
        }

        $entry->update(['status' => JournalEntryStatus::Posted]);

        return $entry;
    }
}
