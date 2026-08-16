<?php

namespace App\Modules\TallySync\Services;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * ONE answer to "is this request the local sync agent?" — shared by the
 * event recorder (which types actors) and the entry resource (which decides
 * who receives a payload's rates), so the two can never disagree about who
 * the agent is.
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
