<?php

namespace App\Modules\TallySync\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Issues/revokes the Sanctum tokens the local sync agent authenticates
 * with — see TALLY-SYNC-MASTER-PLAN.md §5 and tally-sync-agent/README.md.
 * Every token is scoped to exactly tally-sync:poll + tally-sync:report,
 * never broader, and all of them belong to one auto-provisioned,
 * password-less service user rather than a real staff account — so
 * revoking a departing employee's account can never accidentally take the
 * agent down, and the agent never appears as a "user" anyone can log in
 * as. One user, many tokens: separate machines/installs each get their
 * own named, independently revocable token instead of sharing one.
 */
class AgentTokenService
{
    private const AGENT_EMAIL = 'tally-sync-agent@system.local';

    // poll = fetch pending vouchers, report = ack/fail them, items = push the
    // Tally stock-item master back up (masters pull, TALLY-SYNC-MASTER-PLAN.md §3).
    private const ABILITIES = ['tally-sync:poll', 'tally-sync:report', 'tally-sync:items'];

    public function listTokens(): Collection
    {
        return $this->agentUser()->tokens()->orderByDesc('created_at')->get();
    }

    /**
     * @return array{token: PersonalAccessToken, plainTextToken: string}
     */
    public function issueToken(string $name): array
    {
        $result = $this->agentUser()->createToken($name, self::ABILITIES);

        return [
            'token' => $result->accessToken,
            'plainTextToken' => $result->plainTextToken,
        ];
    }

    public function revokeToken(int $tokenId): void
    {
        $this->agentUser()->tokens()->where('id', $tokenId)->delete();
    }

    private function agentUser(): User
    {
        return User::query()->firstOrCreate(
            ['email' => self::AGENT_EMAIL],
            [
                'name' => 'Tally Sync Agent',
                // Never used to log in — no login route exists for this
                // account, and it's excluded from the Users page (see
                // UserService::paginate()) — but User::password can't be
                // null, so it gets an unguessable random value nobody ever
                // sees or needs.
                'password' => Str::random(64),
                'is_active' => true,
                'is_system' => true,
            ],
        );
    }
}
