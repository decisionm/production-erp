<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Models\Enums\StoreIssueStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The store-issue list's query string.
 *
 * These three read actions were the only doors in this module with no
 * FormRequest at all: the controller called `$request->string('status')` and
 * `$request->integer('per_page')` straight on raw input, so `?status[]=issued`
 * or `?as_of[]=x` was an **uncaught TypeError — a 500** rather than a 422, and
 * `?per_page=999999` was uncapped. Not reachable from today's screens, which
 * send one scalar; `routes/api.php` is a real product surface all the same
 * (CLAUDE.md decision 3), and "no client sends that" is not a validation rule.
 *
 * Deliberately PERMISSIVE about meaning and strict only about shape: an
 * unknown status was silently ignored before and is refused now, but nothing
 * that a working screen sends today changes behaviour.
 */
class ListStoreIssuesRequest extends FormRequest
{
    /** The page size a caller may ask for, bounded the way the queue's is. */
    public const PER_PAGE_MAX = 200;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', Rule::enum(StoreIssueStatus::class)],
            'material_request_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'item_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'issued_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'issued_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:issued_from'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'between:1,'.self::PER_PAGE_MAX],
        ];
    }
}
