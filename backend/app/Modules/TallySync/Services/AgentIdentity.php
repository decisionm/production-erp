<?php

namespace App\Modules\TallySync\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * ONE answer to "is this request the local sync agent?" — shared by the
 * event recorder (which types actors) and the entry resource (which decides
 * who receives a payload's rates), so the two can never disagree about who
 * the agent is — and, built on it, ONE answer to "may this reader see
 * purchase details?" (mayReadPurchaseDetails), the FC-06 predicate the entry
 * resource judges BOTH halves of that rule against: rates AND supplier
 * identity. There is deliberately no second predicate anywhere in this
 * module: a gate that says "finance or the agent" for the rate and something
 * else for the vendor is a gate nobody can reason about.
 *
 * The agent authenticates with a Sanctum PERSONAL ACCESS TOKEN carrying the
 * abilities AgentTokenService issues (poll + report + masters), and an
 * installation is known by that token's name. Two things are deliberately
 * NOT the agent:
 *
 *   - a staff member browsing the SPA. Sanctum hands them a TransientToken
 *     whose can() answers TRUE for every ability, so User::tokenCan() is
 *     no test at all on a session — only a real PersonalAccessToken row
 *     counts here;
 *   - a personal access token WITHOUT the agent's abilities. CLAUDE.md #3
 *     supports token auth for any external client, and a person driving
 *     the API with such a token is a user with a token, not the factory
 *     PC — typing them "agent" would light "Agent last action" from a
 *     laptop.
 */
final class AgentIdentity
{
    /** The abilities that make a token the agent's: it fetches vouchers, or reports on them. */
    public const ABILITIES = ['tally-sync:poll', 'tally-sync:report'];

    public static function isAgent(?Authenticatable $actor): bool
    {
        return self::token($actor) !== null;
    }

    /**
     * FC-06 — "Purchase rates and supplier details are Owner/Accounts only.
     * Floor and sales logins never see what a material cost or who supplied
     * it." Who may: finance (finance.view / finance.manage — the permission
     * MaterialLotResource and GoodsReceiptNoteLineResource gate purchase
     * rates on; the Owner and Accounts hold it, the store does not) or the
     * sync AGENT, which must receive the whole payload to build the voucher
     * Tally is sent (receiptNote.ts writes PARTYLEDGERNAME, RATE and AMOUNT
     * from it). Judged on a real agent token, never on tokenCan() alone —
     * see isAgent(). Everyone else — a plain tally-sync.view reader, a
     * personal access token without the agent's abilities, no user at all —
     * may not.
     */
    public static function mayReadPurchaseDetails(?Authenticatable $actor): bool
    {
        if ($actor instanceof User && $actor->hasAnyPermission(['finance.view', 'finance.manage'])) {
            return true;
        }

        return self::isAgent($actor);
    }

    /**
     * When the agent last CHECKED IN — the newest last_used_at across every
     * token that carries an agent ability, which Sanctum's guard stamps on
     * each authenticated request (Guard::updateLastUsedAt).
     *
     * A DIFFERENT QUESTION from the Control Center's "Agent last action",
     * and the reason this exists: a poll that finds nothing to deliver
     * records no event, so last_action_at can stand still all night on an
     * agent that is alive and polling every 90 seconds. A token's
     * last_used_at moves on that poll.
     *
     * JUDGED ON ABILITIES, exactly as isAgent() and the poll endpoint are —
     * not on which user owns the token. AgentTokenService issues every
     * token to one service user by convention, but the /pending gate is
     * ability-based, so a token that CAN poll is an agent that really did
     * check in. Keying this on the service user instead would let a real,
     * actively-polling agent read as "never checked in", and a liveness
     * light is worst when it is falsely dark.
     *
     * NULL means no such token has ever been used — "never", never "down".
     * The caller must keep those apart.
     *
     * Names nothing: the answer is a timestamp, never a token id, a token
     * name, or the abilities on it.
     */
    public static function lastCheckedAt(): ?CarbonInterface
    {
        // Prefiltered to tokens that have been used at all — the set is one
        // row per factory install in practice, and can() is the same check
        // token() makes, so there is one definition of "agent ability" in
        // this class rather than a second SQL rendering of it.
        return PersonalAccessToken::query()
            ->whereNotNull('last_used_at')
            ->orderByDesc('last_used_at')
            ->cursor()
            ->first(fn (PersonalAccessToken $token) => self::hasAgentAbility($token))
            ?->last_used_at;
    }

    /** Whether this token carries any of the abilities that make a caller the agent. */
    private static function hasAgentAbility(PersonalAccessToken $token): bool
    {
        foreach (self::ABILITIES as $ability) {
            if ($token->can($ability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The agent's token when $actor is the agent, else null — the name on
     * it is the installation's label.
     */
    public static function token(?Authenticatable $actor): ?PersonalAccessToken
    {
        $token = $actor instanceof User ? $actor->currentAccessToken() : null;

        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        return self::hasAgentAbility($token) ? $token : null;
    }
}
