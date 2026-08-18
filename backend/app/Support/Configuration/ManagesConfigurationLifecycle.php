<?php

namespace App\Support\Configuration;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Thin sugar so a module Service declares only WHAT references its master
 * and inherits the whole Configuration Lifecycle Contract.
 *
 *   class WarehouseService
 *   {
 *       use ManagesConfigurationLifecycle;
 *
 *       protected function configurationLabel(): string
 *       {
 *           return 'warehouse';
 *       }
 *
 *       protected function dependencyChecks(): array
 *       {
 *           return [
 *               DependencyCheck::table('stock_balances', 'warehouse_id')
 *                   ->label('stock balance')->cascadeSide(),
 *           ];
 *       }
 *   }
 *
 * The trait holds NO policy of its own — every decision stays in
 * ConfigurationLifecycle, so a service cannot quietly acquire a different
 * one by overriding a method here.
 */
trait ManagesConfigurationLifecycle
{
    private ?ConfigurationLifecycle $configurationLifecycle = null;

    /**
     * Everything that may reference this module's master.
     *
     * @return list<DependencyCheck>
     */
    abstract protected function dependencyChecks(): array;

    /** The noun a refusal prints — "warehouse", "scrap reason". */
    abstract protected function configurationLabel(): string;

    public function dependencyReport(Model $model): DependencyReport
    {
        return $this->configurationLifecycle()->report($model);
    }

    /** @return array{edit: bool, activate: bool, archive: bool, delete: bool|null} */
    public function abilities(Model $model, bool $resolveDelete = true, ?Authenticatable $user = null): array
    {
        return $this->configurationLifecycle()->abilities($model, $resolveDelete, $user);
    }

    public function delete(Model $model, ?Authenticatable $user = null): void
    {
        $this->configurationLifecycle()->delete($model, $user);
    }

    public function archive(Model $model, ?string $reason = null): Model
    {
        return $this->configurationLifecycle()->archive($model, $reason);
    }

    public function activate(Model $model, ?string $reason = null): Model
    {
        return $this->configurationLifecycle()->activate($model, $reason);
    }

    /**
     * The Activate/Deactivate flag: a column name for the ordinary boolean
     * master, an ActiveFlag::status(...) for one whose state is a BackedEnum
     * `status` (Mold, Asset, MeasuringInstrument), null for a master with
     * neither.
     */
    protected function configurationActiveColumn(): ActiveFlag|string|null
    {
        return 'is_active';
    }

    /**
     * Who may hard-delete this module's master — DEC-20260817-002 §3, Super
     * Admin / Owner only. NULL means "nobody, yet": the mechanism refuses
     * every hard delete until a module answers this, because the repo has
     * no Super Admin role or permission to name today and the mechanism will
     * not invent one. The wiring wave answers it.
     *
     * @return ?Closure fn (?Authenticatable): bool
     */
    protected function configurationHardDeleteAuthorisation(): ?Closure
    {
        return null;
    }

    /** How a refusal names one record; null = name, then code, then the key. */
    protected function configurationNameUsing(): ?Closure
    {
        return null;
    }

    protected function configurationLifecycle(): ConfigurationLifecycle
    {
        return $this->configurationLifecycle ??= new ConfigurationLifecycle(
            label: $this->configurationLabel(),
            checks: $this->dependencyChecks(),
            activeColumn: $this->configurationActiveColumn(),
            nameUsing: $this->configurationNameUsing(),
            canHardDelete: $this->configurationHardDeleteAuthorisation(),
        );
    }
}
