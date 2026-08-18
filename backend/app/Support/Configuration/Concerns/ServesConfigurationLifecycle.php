<?php

namespace App\Support\Configuration\Concerns;

use App\Support\Configuration\ManagesConfigurationLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use LogicException;

/**
 * The CONTROLLER half of the Configuration Lifecycle Contract, written
 * once so every master screen answers the same way.
 *
 * A module controller injects its own Service (which uses
 * {@see ManagesConfigurationLifecycle}) and calls these three helpers from
 * three three-line actions. Nothing here decides anything: the service —
 * and behind it `ConfigurationLifecycle` — remains the only place delete,
 * archive and activate are judged. This trait only turns those answers into
 * HTTP.
 *
 * ## The seam that is easy to get wrong
 *
 * `abilities()` costs 8–30 COUNT queries when `resolveDelete` is true, so:
 *
 *   - `show` and the three ACTIONS attach an AUTHORITATIVE `can` via
 *     {@see withAbilities()} — this is what the confirm dialog fetches
 *     before offering Delete;
 *   - `index` attaches nothing, and the Resource falls back to the cheap
 *     `resolveDelete: false` block, where `delete` is `null` meaning
 *     "undetermined — ask" (or `false`, a real decision, for a user who
 *     holds no hard-delete tier at all: no amount of counting changes that).
 *
 * Get that backwards and either a 200-row list pays six thousand COUNTs, or
 * the Delete button on `show` is offered on a guess.
 *
 * ## Why LogicException becomes a 422
 *
 * `ConfigurationLifecycle::archive()` and `activate()` enforce their own
 * abilities and throw `LogicException` for "there is nothing to archive /
 * nothing to reactivate" — the stale-button case, which is a user-visible
 * state disagreement and not a bug. Left alone it renders as a 500, so it
 * is translated here, at the HTTP boundary, rather than by weakening the
 * mechanism. A refusal WITH REASONS (`ConfigurationInUseException`) is not
 * touched: it already renders as the contract's 422 through
 * bootstrap/app.php's DomainException handler, counts and all.
 */
trait ServesConfigurationLifecycle
{
    /**
     * Stamp the AUTHORITATIVE `can` block onto a record the Resource is
     * about to render — `show` and every action, never `index`.
     *
     * The attribute is read back by the Resource (`$this->can ?? …`), the
     * same idiom PurchaseOrderResource already uses. It is set in memory
     * only; nothing saves it.
     *
     * STAMP IT LAST. `can` is not a column, so a `save()` on the model AFTER
     * this call would try to write one and throw. Every action here writes
     * first and stamps second, and a converged action (an existing
     * `deactivate`, say) must be reordered to match rather than stamping
     * early and saving later.
     *
     * @param  object  $service  the module Service using ManagesConfigurationLifecycle
     */
    protected function withAbilities(Model $model, object $service, Request $request): Model
    {
        $model->can = $service->abilities($model, resolveDelete: true, user: $request->user());

        return $model;
    }

    /**
     * Run archive() or activate(), translating the mechanism's "there is
     * nothing to do here" into a 422 the SPA can show.
     *
     * @param  callable(): Model  $action
     */
    protected function runLifecycleAction(callable $action): Model
    {
        try {
            return $action();
        } catch (LogicException $exception) {
            abort(422, $exception->getMessage());
        }
    }
}
