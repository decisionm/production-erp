<?php

namespace App\Modules\Compliance\Services;

use App\Modules\Compliance\Http\Requests\ListGstRegistrationsRequest;
use App\Modules\Compliance\Models\GstRegistration;
use App\Support\Lists\ListSort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class GstRegistrationService
{
    /**
     * Primary first, then by state, when no sort is asked for — what this
     * list always was, and a two-column default ListSort cannot spell, so
     * it is kept here. A named sort replaces the whole order.
     */
    public function paginate(int $perPage = 20, ?string $sort = null): LengthAwarePaginator
    {
        $query = GstRegistration::query();

        if ($sort === null || trim($sort) === '') {
            $query->orderByDesc('is_primary')->orderBy('state_name')->orderByDesc('id');
        } else {
            ListSort::apply($query, $sort, ListGstRegistrationsRequest::SORTABLE);
        }

        return $query->paginate($perPage);
    }

    /**
     * The registration billing documents (like a Quotation PDF letterhead)
     * should reference — until the app supports picking a billing branch
     * per transaction, there's exactly one "primary" state.
     */
    public function primary(): ?GstRegistration
    {
        return GstRegistration::query()->where('is_primary', true)->first();
    }

    public function create(array $data): GstRegistration
    {
        return DB::transaction(function () use ($data) {
            if (! empty($data['is_primary'])) {
                $this->clearExistingPrimary();
            }

            return GstRegistration::create([
                'is_active' => true,
                'is_primary' => false,
                ...$data,
            ]);
        });
    }

    public function update(GstRegistration $registration, array $data): GstRegistration
    {
        return DB::transaction(function () use ($registration, $data) {
            if (! empty($data['is_primary'])) {
                $this->clearExistingPrimary();
            }

            $registration->update($data);

            return $registration;
        });
    }

    /**
     * Only one registration can be primary — it stands in for "which state
     * is this company billing from" in GST computation until the app
     * supports picking a billing branch per transaction.
     */
    private function clearExistingPrimary(): void
    {
        GstRegistration::query()->where('is_primary', true)->update(['is_primary' => false]);
    }
}
