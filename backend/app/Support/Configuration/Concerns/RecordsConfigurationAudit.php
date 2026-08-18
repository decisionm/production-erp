<?php

namespace App\Support\Configuration\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The Audit column of the configuration lifecycle contract, in one place.
 *
 * A model that uses this concern records every create, edit, delete and
 * restore into `activity_log` under the `configuration` log name, with the
 * authenticated user as the causer and only the attributes that actually
 * changed — and stamps `created_by` / `updated_by` on the row itself so a
 * master list can show "last edited by" without joining the log.
 *
 * APPLIED MODEL BY MODEL, ON PURPOSE. There is no base class and no global
 * observer: a transaction, ledger row or posted document must never be
 * mirrored here. The stock ledger is append-only and already refuses its own
 * updates and deletes; narrating it into a second table would create a
 * shadow copy of factory history that nothing reconciles. Tests pin the
 * blast radius rather than trusting this comment.
 *
 * THREE THINGS THIS DELIBERATELY DOES NOT DO:
 *
 *  1 · It never logs a save whose only change is bookkeeping. The Tally
 *      masters pull re-stamps `tally_synced_at` on every item on every
 *      cycle; without `dontLogIfAttributesChangedOnly` (and `updated_at`
 *      belongs in that list — it is already dirty when the `updated` event
 *      fires) each pull would mint one row per item and the audit trail
 *      would be 99% noise within a week.
 *
 *  2 · It never writes a stamp when nobody is authenticated. The masters
 *      pull, console commands and seeders all write masters with no user
 *      behind them; stamping null there would ERASE the record of the last
 *      person who edited the row. Silence is the honest answer, and the
 *      activity row still records the change with a null causer.
 *
 *  3 · It never overwrites a `created_by` somebody set explicitly. The
 *      production standards and configurations services pass their own, and
 *      that value is the authoritative one.
 */
trait RecordsConfigurationAudit
{
    use LogsActivity;

    /**
     * The log name every configuration trail is written under, so the
     * configuration audit can be queried, retained and shown as one thing
     * rather than picked out of `default` by subject type.
     */
    public const CONFIGURATION_LOG_NAME = 'configuration';

    /** The stamps this concern writes. Recorded, never themselves audited. */
    public const AUDIT_STAMP_COLUMNS = ['created_by', 'updated_by'];

    protected static function bootRecordsConfigurationAudit(): void
    {
        static::creating(function (Model $model): void {
            $model->stampConfigurationAudit(true);
        });

        static::updating(function (Model $model): void {
            $model->stampConfigurationAudit(false);
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(self::CONFIGURATION_LOG_NAME)
            // The model's own fillable list IS the set of fields a person can
            // change, so it stays correct as modules grow a column.
            ->logFillable()
            ->logExcept(self::AUDIT_STAMP_COLUMNS)
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->dontLogIfAttributesChangedOnly($this->configurationAuditBookkeepingColumns())
            ->setDescriptionForEvent(
                fn (string $event): string => Str::snake(class_basename(static::class)).'.'.$event
            );
    }

    /**
     * Columns whose movement on its own is not an edit anybody audits —
     * timestamps, the stamps above, and the Tally sync bookkeeping the
     * masters pull rewrites every cycle. A model with a bookkeeping column
     * of its own overrides this and calls back into it.
     *
     * Listing a column here does NOT hide it: when it changes alongside a
     * real edit (Tally renames an item, so `name` and `tally_alter_id` move
     * together) the row is written and carries both.
     *
     * @return list<string>
     */
    protected function configurationAuditBookkeepingColumns(): array
    {
        return array_values(array_filter(array_merge(
            self::AUDIT_STAMP_COLUMNS,
            [$this->getCreatedAtColumn(), $this->getUpdatedAtColumn()],
            ['tally_synced_at', 'tally_alter_id'],
        )));
    }

    /**
     * Stamp who is writing this row, when there is a "who" to name.
     *
     * On update the stamp is only taken when something else is genuinely
     * dirty: Eloquent fires `updating` on every `save()` of an existing
     * model, including a save that changes nothing, and a stamp taken there
     * would turn a no-op into a real UPDATE (and a spurious `updated_at`).
     */
    public function stampConfigurationAudit(bool $creating): void
    {
        $userId = Auth::id();

        if ($userId === null) {
            return;
        }

        if ($creating) {
            if ($this->getAttribute('created_by') === null) {
                $this->setAttribute('created_by', $userId);
            }

            $this->setAttribute('updated_by', $userId);

            return;
        }

        if ($this->isDirty()) {
            $this->setAttribute('updated_by', $userId);
        }
    }
}
