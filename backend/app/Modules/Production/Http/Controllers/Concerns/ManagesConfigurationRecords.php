<?php

namespace App\Modules\Production\Http\Controllers\Concerns;

use App\Modules\Production\Exceptions\ConfigurationActionUnavailableException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The Configuration Lifecycle Contract's `can` block, as the floor's master
 * screens serve it.
 *
 * TWO THINGS ARE INTERSECTED HERE, and neither is re-derived.
 *
 * 1 · WHAT THE RECORD ALLOWS — ConfigurationLifecycle::abilities(), asked of
 *     the module's service. Structural eligibility plus the hard-delete
 *     tier: is it already retired, does it soft-delete, is it referenced,
 *     may this user hard-delete at all. None of that is recomputed here.
 *
 * 2 · WHAT THIS USER'S ROLE ALLOWS — the module permission the WRITE routes
 *     actually sit behind. This half exists because of the machine master:
 *     reading a machine needs `production.view` and CHANGING one needs
 *     `machine-master.manage`, deliberately (routes/api.php). Without the
 *     intersection a supervisor's machine list would come back
 *     `can.edit: true` and every button would 403 — a lie no other entity
 *     in this module can tell, since for the other four the read and the
 *     write sit behind the same grant and the intersection is the identity.
 *
 * `delete` follows the mechanism's own three-valued reading — TRUE (may,
 * and provably unused), NULL (undetermined, ask `show`), FALSE (a decision
 * no counting would change). A user without the module's write permission
 * is answered FALSE rather than NULL for exactly the mechanism's stated
 * reason: that is not an unknown.
 */
trait ManagesConfigurationRecords
{
    /**
     * The write answer, memoised PER USER — not once per instance.
     *
     * Laravel caches the resolved controller ON THE ROUTE OBJECT
     * (Illuminate\Routing\Route::getController(): `$this->controller ??=
     * ...`), and a Route lives as long as the RouteCollection does. So one
     * controller instance can serve several requests inside one long-lived
     * process — every test that calls the same endpoint twice, and every
     * request under Octane or any persistent worker. A single `?bool` cached
     * the FIRST caller's permission and then answered it for the NEXT one,
     * which is a `can` block computed for somebody else. Keyed by user id it
     * still costs one permission read per user per process, which is what the
     * memoisation was for.
     *
     * @var array<string, bool>
     */
    private array $configurationMayWrite = [];

    /**
     * The permission this entity's WRITE routes require —
     * "production.manage", or "machine-master.manage" for the machine
     * master. Named by the controller so it can never drift from the route
     * group it describes.
     */
    abstract protected function configurationWritePermission(): string;

    /**
     * The noun a stale-button refusal prints — "machine", "mould", "shift".
     *
     * Concrete, not abstract, on purpose: this trait is shared by more
     * controllers than the five floor masters, and a new abstract method
     * would break every one of them for a message. Override it where the
     * entity has a name worth printing; the default is honest if plain.
     */
    protected function configurationNoun(): string
    {
        return 'record';
    }

    /**
     * Archive, refusing a STALE BUTTON as a 422 instead of crashing.
     *
     * ConfigurationLifecycle already enforces its own `can` block, but it
     * does so with a LogicException, which renders as a 500 over HTTP — and
     * a double Archive (two people on one master screen, or one stale tab)
     * is an ordinary thing for a user to do, not a programming error. So
     * the mechanism's OWN answer is asked first and rendered as a business
     * refusal. The verdict is not recomputed here and the mechanism still
     * enforces it behind this.
     */
    protected function archiveRecord(object $service, Model $model, ?string $reason): Model
    {
        if (! $service->abilities($model, resolveDelete: false)['archive']) {
            throw ConfigurationActionUnavailableException::archive($this->configurationNoun());
        }

        return $service->archive($model, $reason);
    }

    /** Activate, with the same stale-button refusal. */
    protected function activateRecord(object $service, Model $model, ?string $reason): Model
    {
        if (! $service->abilities($model, resolveDelete: false)['activate']) {
            throw ConfigurationActionUnavailableException::activate($this->configurationNoun());
        }

        return $service->activate($model, $reason);
    }

    /**
     * The `can` block for one record, stamped onto the model the
     * PurchaseOrderResource way so the resource prints it rather than
     * asking a second time.
     *
     * @param  object  $service  the module service using ManagesConfigurationLifecycle
     * @param  bool  $resolveDelete  false on index — 8-30 COUNTs per row is
     *                               what `delete: null` exists to avoid;
     *                               true on show and after every action,
     *                               where the answer must be authoritative.
     *
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @return TModel
     */
    protected function withAbilities(Request $request, object $service, Model $model, bool $resolveDelete = true): Model
    {
        $user = $request->user();

        $model->can = $this->mayWrite($request)
            ? $service->abilities($model, $resolveDelete, $user)
            : ['edit' => false, 'activate' => false, 'archive' => false, 'delete' => false];

        return $model;
    }

    /**
     * The same, for a page of records. Always with `resolveDelete: false`:
     * a list of masters must not pay the dependency sweep per row.
     *
     * @template TCollection of iterable<int, Model>
     *
     * @param  TCollection  $records
     * @return TCollection
     */
    protected function withAbilitiesForEach(Request $request, object $service, iterable $records): iterable
    {
        foreach ($records as $record) {
            $this->withAbilities($request, $service, $record, resolveDelete: false);
        }

        return $records;
    }

    /**
     * Does the acting user hold the module permission the write routes
     * require? Answered once per request rather than once per row: an index
     * of 100 machines must not ask the permission layer 100 times for an
     * answer that cannot change inside one request.
     */
    private function mayWrite(Request $request): bool
    {
        $user = $request->user();
        $key = ($user?->getAuthIdentifier() ?? 'guest').'|'.$this->configurationWritePermission();

        return $this->configurationMayWrite[$key] ??= $user !== null
            && method_exists($user, 'hasAnyPermission')
            && $user->hasAnyPermission([$this->configurationWritePermission()]);
    }
}
