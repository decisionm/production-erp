<?php

namespace App\Modules\HRMS\Http\Resources;

use App\Modules\HRMS\Models\Enums\AttendanceImportIssue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceImportResource extends JsonResource
{
    /**
     * The review chips' numbers, ONE PER ISSUE KIND, built from the enum
     * rather than listed by hand.
     *
     * Written by hand once, and the two kinds added with the hours rule
     * (DEC-20260903-005) were promptly left out of it: the server counted
     * 68 days that the screen then showed as nothing at all. A count that
     * is spelled out per kind gets forgotten the next time too, so the
     * cases are the list.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = ['open' => (int) ($this->open_count ?? 0)];

        foreach (AttendanceImportIssue::cases() as $issue) {
            $counts[$issue->value] = (int) ($this->{$issue->value.'_count'} ?? 0);
        }

        $counts['resolved'] = (int) ($this->resolved_count ?? 0);
        $counts['clean'] = (int) ($this->clean_count ?? 0);

        return $counts;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'period_from' => $this->period_from?->toDateString(),
            'period_to' => $this->period_to?->toDateString(),
            'file_name' => $this->file_name,
            'status' => $this->status->value,
            'employee_count' => $this->employee_count,
            'day_count' => $this->day_count,
            'issue_count' => $this->issue_count,
            // How many issue lines still wait for a person — the number on
            // the Apply button. Counted by the service on every read.
            'open_count' => (int) ($this->open_count ?? 0),
            // The review chips' numbers: open issues by kind, answered
            // issues, and lines that never needed anyone.
            'counts' => $this->counts(),
            'uploaded_by' => $this->when($this->relationLoaded('uploader') && $this->uploader, fn () => [
                'id' => $this->uploader->id,
                'name' => $this->uploader->name,
            ]),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
