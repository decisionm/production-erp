<?php

namespace App\Modules\Quality\Services;

use App\Exceptions\InvalidStatusTransitionException;
use App\Modules\Quality\Exceptions\CapaClosedException;
use App\Modules\Quality\Exceptions\IncompleteCapaException;
use App\Modules\Quality\Models\Capa;
use App\Modules\Quality\Models\Enums\CapaStatus;
use App\Support\Lists\ListSort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * start() and close() are pure status transitions; editing the narrative
 * fields (root cause, corrective/preventive action, owner, due date) goes
 * through update() instead and is allowed at any point before closure —
 * root cause analysis is rarely a one-shot write. close() is the one
 * place that actually enforces CAPA's substance: it refuses to close a
 * record that never got a documented root cause and both actions, rather
 * than letting "closed" become a meaningless status.
 */
class CapaService
{
    /** The columns the register sorts on besides id (ListCapasRequest validates the same list). */
    public const SORTABLE = ['title', 'status', 'due_date'];

    /**
     * Newest first unless `$sort` (a validated column, ListSort spelling)
     * says otherwise. `due_date` is nullable, so an undated CAPA sorts
     * last in either direction.
     */
    public function paginate(int $perPage = 20, ?string $sort = null): LengthAwarePaginator
    {
        $query = Capa::query()->with(['nonConformanceReport', 'ownerEmployee', 'createdBy']);

        return ListSort::apply($query, $sort, self::SORTABLE, '-id', ['due_date'])->paginate($perPage);
    }

    public function openCount(): int
    {
        return Capa::query()
            ->whereIn('status', [CapaStatus::Open, CapaStatus::InProgress])
            ->count();
    }

    /**
     * @param  array{non_conformance_report_id?: int, title: string, problem_statement: string, owner?: int, due_date?: string}  $data
     */
    public function create(array $data, ?int $createdBy): Capa
    {
        return Capa::create([
            'non_conformance_report_id' => $data['non_conformance_report_id'] ?? null,
            'title' => $data['title'],
            'problem_statement' => $data['problem_statement'],
            'owner' => $data['owner'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'status' => CapaStatus::Open,
            'created_by' => $createdBy,
        ])->load(['nonConformanceReport', 'ownerEmployee', 'createdBy']);
    }

    /**
     * @param  array{title?: string, problem_statement?: string, root_cause?: string, corrective_action?: string, preventive_action?: string, owner?: int, due_date?: string}  $data
     */
    public function update(Capa $capa, array $data): Capa
    {
        if ($capa->status === CapaStatus::Closed) {
            throw CapaClosedException::forUpdate($capa->id);
        }

        $capa->update($data);

        return $capa->load(['nonConformanceReport', 'ownerEmployee', 'createdBy']);
    }

    public function start(Capa $capa): Capa
    {
        if ($capa->status !== CapaStatus::Open) {
            throw InvalidStatusTransitionException::make('CAPA', $capa->status->value, CapaStatus::InProgress->value);
        }

        $capa->update(['status' => CapaStatus::InProgress]);

        return $capa;
    }

    public function close(Capa $capa, bool $verifiedEffective): Capa
    {
        if ($capa->status !== CapaStatus::InProgress) {
            throw InvalidStatusTransitionException::make('CAPA', $capa->status->value, CapaStatus::Closed->value);
        }

        if (! $capa->root_cause || ! $capa->corrective_action || ! $capa->preventive_action) {
            throw IncompleteCapaException::forCapa($capa->id);
        }

        $capa->update([
            'status' => CapaStatus::Closed,
            'verified_effective' => $verifiedEffective,
            'closed_date' => now()->toDateString(),
        ]);

        return $capa;
    }
}
