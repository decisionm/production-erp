<?php

namespace App\Support\Configuration;

use Closure;
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
    public function abilities(Model $model, bool $resolveDelete = true): array
    {
        return $this->configurationLifecycle()->abilities($model, $resolveDelete);
    }

    public function delete(Model $model): void
    {
        $this->configurationLifecycle()->delete($model);
    }

    public function archive(Model $model, ?string $reason = null): Model
    {
        return $this->configurationLifecycle()->archive($model, $reason);
    }

    public function activate(Model $model, ?string $reason = null): Model
    {
        return $this->configurationLifecycle()->activate($model, $reason);
    }

    /** The Activate/Deactivate flag, or null for a master that has none. */
    protected function configurationActiveColumn(): ?string
    {
        return 'is_active';
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
        );
    }
}
