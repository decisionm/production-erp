<?php

namespace App\Modules\Production\Exceptions;

use App\Exceptions\DomainException;
use App\Modules\Production\Models\ShiftProductionEntry;
use RuntimeException;

/**
 * A second Start Batch on a machine that is already running one.
 *
 * The old behaviour raised a bare "cannot transition from idle to
 * in_progress", which told the supervisor nothing they could use: the
 * common cause is that the machine looks idle on THEIR screen (a batch
 * started in the previous shift, or by someone else) and the useful answer
 * is not "no" but "here is what is already running on it". So the exception
 * carries the existing batch, and the SPA shows it instead of an error —
 * which also makes a double-tap harmless rather than confusing.
 */
class MachineBusyException extends RuntimeException implements DomainException
{
    public function __construct(
        string $message,
        private readonly ShiftProductionEntry $entry,
    ) {
        parent::__construct($message);
    }

    public static function make(ShiftProductionEntry $entry): self
    {
        $machine = $entry->workCenter?->name ?? 'This machine';
        $product = $entry->item?->name ?? 'a product';

        return new self(
            "{$machine} is already running batch {$entry->batch_number} ({$product}). ".
            'Complete or hand over that batch before starting a new one.',
            $entry,
        );
    }

    public function errorCode(): string
    {
        return 'machine_busy';
    }

    /**
     * The running batch, so the client can route straight to it rather than
     * asking the supervisor to go and find it.
     */
    public function payload(): array
    {
        return [
            'active_batch' => [
                'id' => $this->entry->id,
                'batch_number' => $this->entry->batch_number,
                'item' => $this->entry->item?->name,
                'work_center' => $this->entry->workCenter?->name,
                'shift' => $this->entry->shift?->name,
                'production_date' => $this->entry->production_date,
                'started_at' => $this->entry->created_at?->toIso8601String(),
            ],
        ];
    }
}
