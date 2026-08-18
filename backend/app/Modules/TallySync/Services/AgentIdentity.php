<?php

namespace App\Modules\TallySync\Services;

use App\Models\User;
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
     * The agent's token when $actor is the agent, else null — the name on
     * it is the installation's label.
     */
    public static function token(?Authenticatable $actor): ?PersonalAccessToken
    {
        $token = $actor instanceof User ? $actor->currentAccessToken() : null;

        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        foreach (self::ABILITIES as $ability) {
            if ($token->can($ability)) {
                return $token;
            }
        }

        return null;
    }
}
