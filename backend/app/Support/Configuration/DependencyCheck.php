<?php

namespace App\Support\Configuration;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * ONE declared reason a configuration record may not be hard-deleted.
 *
 * A module says what references its master; it never writes the counting
 * query. Three kinds cover everything the audit found:
 *
 *   ::table('stock_balances', 'warehouse_id')   a foreign key (or several
 *                                               columns of the same table,
 *                                               counted as an OR)
 *   ::callable($fn, 'production_batches')       a reference no FK expresses
 *                                               (a JSON column, a cross-
 *                                               module Service question)
 *   ::attribute('tally_guid')                   the record's OWN attribute
 *                                               makes it undeletable — a
 *                                               Tally identity is Tally's,
 *                                               not ours to drop
 *   ::unprovable('legacy_stock_history')        past use CANNOT be proven
 *                                               for this data (see below)
 *
 * FAIL-CLOSED (DEC-20260817-002 §5). "Ever used" means historical use, not
 * merely a live foreign key. Where that cannot be proven — legacy rows
 * whose history was never recorded — the honest answer is "cannot prove
 * unused", and that verdict blocks the delete exactly like a positive
 * count. ::unprovable() states it declaratively; a ::callable() resolver
 * states it by returning null. Nothing here ever guesses.
 *
 * ->cascadeSide() marks a child the DATABASE would destroy on a real
 * DELETE (the audit's cascade asymmetry: eight parents cascade with no
 * backstop, so this application check is the ONLY guard). It IMPLIES
 * ->includeTrashed(), and that is not a nicety: a soft-deleted child is
 * still a physical row, so `ON DELETE CASCADE` destroys it just the same.
 * A withdrawn masterbatch dosing is exactly the row a past shift's prefill
 * has to stay explainable by.
 *
 * ->includeTrashed() on an ordinary check counts soft-deleted children too,
 * for masters whose history the module says must keep them alive.
 *
 * Labels are declared in the SINGULAR ("stock balance") and pluralised to
 * the count when printed, so one declaration reads correctly in
 * "1 stock balance" and "12 stock balances" alike.
 */
class DependencyCheck
{
    private const KIND_TABLE = 'table';

    private const KIND_CALLABLE = 'callable';

    private const KIND_ATTRIBUTE = 'attribute';

    private const KIND_UNPROVABLE = 'unprovable';

    private string $label;

    private bool $cascadeSide = false;

    private bool $includeTrashed = false;

    /** @var array<string, bool> table => has a deleted_at column */
    private static array $softDeleteColumns = [];

    /**
     * @param  list<string>  $columns
     */
    private function __construct(
        private readonly string $kind,
        private readonly string $code,
        private readonly ?string $table = null,
        private readonly array $columns = [],
        private readonly ?Closure $resolver = null,
        private readonly ?string $attribute = null,
    ) {
        $this->label = str_replace('_', ' ', $code);
    }

    /**
     * Rows of $table pointing at the record through $columns (several
     * columns are OR-ed: masterbatch_dosings references items twice).
     *
     * @param  string|list<string>  $columns
     */
    public static function table(string $table, string|array $columns, ?string $code = null): self
    {
        $columns = array_values((array) $columns);

        if ($columns === []) {
            throw new InvalidArgumentException("DependencyCheck::table({$table}) needs at least one column.");
        }

        return new self(self::KIND_TABLE, $code ?? $table, table: $table, columns: $columns);
    }

    /**
     * A reference no foreign key expresses. The resolver receives the
     * record and returns a count — or NULL for "cannot prove this record
     * was never used", which blocks the delete (fail-closed).
     *
     * The code is required, not derived: it is the 422's stable
     * machine-readable key and the UI branches on it.
     *
     * @param  callable(Model): (int|bool|null)  $resolver
     */
    public static function callable(callable $resolver, string $code): self
    {
        return new self(self::KIND_CALLABLE, $code, resolver: Closure::fromCallable($resolver));
    }

    /** The record's own attribute makes it undeletable when it is filled. */
    public static function attribute(string $name, ?string $code = null): self
    {
        return new self(self::KIND_ATTRIBUTE, $code ?? $name, attribute: $name);
    }

    /**
     * Past use cannot be proven for this data — the fail-closed verdict of
     * DEC-20260817-002 §5. Always blocks; never counts.
     */
    public static function unprovable(string $code): self
    {
        return new self(self::KIND_UNPROVABLE, $code);
    }

    /** The words the refusal prints, declared in the singular. */
    public function label(string $words): self
    {
        $this->label = $words;

        return $this;
    }

    /**
     * This child is destroyed by the database on a real DELETE — the ONLY
     * guard is this check. Implies includeTrashed(): a soft-deleted child
     * is still a physical row and cascades just the same.
     */
    public function cascadeSide(): self
    {
        $this->cascadeSide = true;
        $this->includeTrashed = true;

        return $this;
    }

    /** Count soft-deleted children too. */
    public function includeTrashed(): self
    {
        $this->includeTrashed = true;

        return $this;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function rawLabel(): string
    {
        return $this->label;
    }

    public function isCascadeSide(): bool
    {
        return $this->cascadeSide;
    }

    /**
     * How many references this record has RIGHT NOW — or null for "cannot
     * be proven", which the report treats as blocking.
     */
    public function count(Model $model): ?int
    {
        return match ($this->kind) {
            self::KIND_TABLE => $this->countRows($model),
            self::KIND_CALLABLE => $this->countResolved($model),
            self::KIND_ATTRIBUTE => filled($model->getAttribute($this->attribute)) ? 1 : 0,
            self::KIND_UNPROVABLE => null,
        };
    }

    private function countRows(Model $model): int
    {
        $connection = $model->getConnection();
        $key = $model->getKey();

        $query = $connection->table($this->table)->where(function ($group) use ($key) {
            foreach ($this->columns as $column) {
                $group->orWhere($column, $key);
            }
        });

        if (! $this->includeTrashed && $this->tableSoftDeletes($connection->getName(), $this->table)) {
            $query->whereNull('deleted_at');
        }

        return (int) $query->count();
    }

    private function countResolved(Model $model): ?int
    {
        $counted = ($this->resolver)($model);

        if ($counted === null) {
            return null;
        }

        return is_bool($counted) ? (int) $counted : (int) $counted;
    }

    private function tableSoftDeletes(?string $connection, string $table): bool
    {
        $cacheKey = ($connection ?? 'default').':'.$table;

        return self::$softDeleteColumns[$cacheKey] ??= Schema::connection($connection)->hasColumn($table, 'deleted_at');
    }
}
