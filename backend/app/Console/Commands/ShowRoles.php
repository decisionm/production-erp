<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * One read-only answer to "which roles exist on LIVE, and what can they see?".
 *
 * Roles are created at runtime through the Roles screen, never seeded, so
 * the only way to know a live role's shape is to read it off the live
 * database — not dev fixtures (the 09-Aug shift-rail defect came from
 * trusting those). The question this was written for is FC-06: until the
 * Procurement line resources were gated, any role holding procurement.*
 * without finance.* read purchase rates it was never meant to see. Such a
 * role is flagged here so the exposure window can be judged from evidence.
 *
 * Strictly read-only by construction: two SELECTs, no state change, no
 * options that write — safe against the live database at any hour.
 */
class ShowRoles extends Command
{
    protected $signature = 'roles:show';

    protected $description = 'Read-only: every role, its user count and its permissions; flags roles that hold procurement.* without finance.*.';

    public const string RATE_FLAG = 'SEES-RATES-VIA-PROCUREMENT-BEFORE-FIX';

    public function handle(): int
    {
        $roles = Role::query()
            ->withCount('users')
            ->with('permissions')
            ->orderBy('name')
            ->get();

        if ($roles->isEmpty()) {
            $this->warn('No roles in this database.');

            return self::SUCCESS;
        }

        $flagged = 0;
        foreach ($roles as $role) {
            $permissions = $role->permissions->pluck('name')->sort()->values();

            // The FC-06 shape: reaches the Procurement endpoints (any
            // procurement.*) but holds neither finance permission — the gate
            // the rate fields hang off. Before the resource fix that role read
            // unit_price/unit_cost straight off the payload.
            $seesRatesViaProcurement = $permissions->contains(fn (string $name) => Str::startsWith($name, 'procurement.'))
                && ! $permissions->contains(fn (string $name) => Str::startsWith($name, 'finance.'));
            if ($seesRatesViaProcurement) {
                $flagged++;
            }

            $this->line(sprintf(
                '%s  users=%d  permissions: %s%s',
                $role->name,
                (int) $role->users_count,
                $permissions->isEmpty() ? '(none)' : $permissions->implode(', '),
                $seesRatesViaProcurement ? '  '.self::RATE_FLAG : '',
            ));
        }

        $this->newLine();
        if ($flagged === 0) {
            $this->info('VERDICT: no role holds procurement.* without finance.* — no role could read purchase rates through the ungated Procurement payloads.');
        } else {
            $this->warn(sprintf('VERDICT: %d role(s) hold procurement.* without finance.* — flagged above; each could read purchase rates off the Procurement payloads until the resource gate shipped.', $flagged));
        }

        return self::SUCCESS;
    }
}
