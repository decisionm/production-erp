<?php

namespace App\Support\Configuration;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * WHICH COLUMN says a configuration record is in service, and what its
 * values mean — because this schema answers that question two ways.
 *
 * Most masters carry a boolean `is_active`. But Mold, Asset and
 * MeasuringInstrument (and everything shaped like them) carry a BackedEnum
 * `status` with three or more cases, and a mechanism that assumes a boolean
 * gets them WRONG IN BOTH DIRECTIONS: it reads a `retired` mould as active
 * (any non-empty string is truthy), and archiving one writes `false` into a
 * status column. So the caller declares the shape here, once, and
 * ConfigurationLifecycle asks this object instead of guessing.
 *
 * TWO PREDICATES, NOT ONE, and that is the point on a status master.
 * `is_active` has exactly two states, so "not active" and "retired" are the
 * same sentence. `MoldStatus` has three — Active, UnderRepair, Retired — so
 * they are different sentences: an under-repair mould is NOT active (it may
 * be activated) and is NOT retired (it may still be archived). Deriving
 * either from the other would strand it in a state it could never leave.
 *
 * This object is about the LIFECYCLE axis only — in service, or not. What a
 * given status may be SELECTED for on the floor is a separate question,
 * owned by the module's validation and (for `under_repair` at Start Batch)
 * by an open owner question. Nothing here answers it.
 */
class ActiveFlag
{
    private function __construct(
        public readonly string $column,
        private readonly bool $isBoolean,
        private readonly BackedEnum|string|int|null $activeValue = null,
        private readonly BackedEnum|string|int|null $retiredValue = null,
    ) {}

    /** The ordinary master: a boolean column, true = in service. */
    public static function boolean(string $column = 'is_active'): self
    {
        return new self($column, true);
    }

    /**
     * A status master: the column, the case that means IN SERVICE, and the
     * case archiving writes. Any other case is neither — not active, not
     * retired — and stays reachable in both directions.
     */
    public static function status(
        string $column,
        BackedEnum|string|int $active,
        BackedEnum|string|int $retired,
    ): self {
        return new self($column, false, $active, $retired);
    }

    /** Sugar so a module may still declare just a column name. */
    public static function from(self|string|null $flag): ?self
    {
        return match (true) {
            $flag instanceof self => $flag,
            is_string($flag) => self::boolean($flag),
            default => null,
        };
    }

    public function isActive(Model $model): bool
    {
        if ($this->isBoolean) {
            $this->refuseAnEnumColumn($model);

            return (bool) $model->getAttribute($this->column);
        }

        return $this->raw($model->getAttribute($this->column)) === $this->raw($this->activeValue);
    }

    public function isRetired(Model $model): bool
    {
        if ($this->isBoolean) {
            return ! $this->isActive($model);
        }

        return $this->raw($model->getAttribute($this->column)) === $this->raw($this->retiredValue);
    }

    public function markActive(Model $model): void
    {
        if ($this->isBoolean) {
            $this->refuseAnEnumColumn($model);
        }

        $model->setAttribute($this->column, $this->isBoolean ? true : $this->activeValue);
    }

    public function markRetired(Model $model): void
    {
        if ($this->isBoolean) {
            $this->refuseAnEnumColumn($model);
        }

        $model->setAttribute($this->column, $this->isBoolean ? false : $this->retiredValue);
    }

    /**
     * A status column declared as if it were a boolean is the exact defect
     * this class exists for — `false` written into an enum, and every
     * non-empty case read as "in service". A module that gets the
     * declaration wrong is told so, loudly, instead of quietly retiring
     * nothing: the wrong answer must not be reachable by forgetting.
     */
    private function refuseAnEnumColumn(Model $model): void
    {
        $cast = $model->getCasts()[$this->column] ?? null;
        $value = $model->getAttribute($this->column);

        if ($value instanceof BackedEnum || (is_string($cast) && enum_exists($cast))) {
            throw new LogicException(sprintf(
                '%s::$%s is a status enum, not a boolean flag — declare ActiveFlag::status(\'%s\', active: ..., retired: ...) so archive() writes a real case.',
                $model::class,
                $this->column,
                $this->column,
            ));
        }
    }

    private function raw(BackedEnum|string|int|null $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return $value === null ? null : (string) $value;
    }
}
