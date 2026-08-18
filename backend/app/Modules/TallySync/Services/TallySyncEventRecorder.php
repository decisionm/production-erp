<?php

namespace App\Modules\TallySync\Services;

use App\Models\User;
use App\Modules\TallySync\Models\Enums\TallySyncEventKind;
use App\Modules\TallySync\Models\TallySyncEntry;
use App\Modules\TallySync\Models\TallySyncEvent;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

/**
 * The only writer of tally_sync_events.
 *
 * One method, and it APPENDS. There is deliberately no update, no delete
 * and no "correct the last event" — the value of a sync history is that
 * yesterday's failure is still readable after today's retry, which is
 * exactly what the entry's own columns cannot promise (error_message and
 * delivered_at are overwritten on every retry; TALLY-SYNC-CHAIN.md §2).
 *
 * TRANSACTIONS: record() is a plain create(). Called from inside a caller's
 * DB transaction it joins that transaction, so an event is never left
 * behind for a mutation that rolled back; called outside one it commits on
 * its own. It never opens a transaction of its own and never swallows a
 * database error into a silent no-op — a history that quietly skips rows
 * is worse than none.
 *
 * NOTHING here touches an entry, a payload, a voucher or Tally. Recording
 * an event changes no status and moves no stock; it is a fact on the
 * record that later readers (the Control Center) consult.
 */
class TallySyncEventRecorder
{
    /**
     * @param  array<string, mixed>  $details  counts, ids, messages — never a token, a rate, or item lines
     */
    public function record(
        TallySyncEventKind $event,
        ?TallySyncEntry $entry,
        array $details = [],
        string $direction = TallySyncEvent::DIRECTION_ERP_TO_TALLY,
        ?Authenticatable $actor = null,
    ): TallySyncEvent {
        [$actorType, $actorId, $actorLabel] = $this->describeActor($actor);

        return TallySyncEvent::create([
            'tally_sync_entry_id' => $entry?->getKey(),
            'event' => $event->value,
            'direction' => $direction,
            'occurred_at' => now(),
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'actor_label' => $actorLabel,
            'details' => $details,
        ]);
    }

    /**
     * Who did this, in the three shapes the table knows.
     *
     * The sync AGENT authenticates with a Sanctum personal access token
     * carrying the agent's abilities (AgentIdentity: tally-sync:poll or
     * tally-sync:report), and an installation is known by that token's NAME
     * (one token per site by convention — the same identity agentLog()
     * writes to the file). A staff member browsing the SPA carries
     * Sanctum's TransientToken instead — not a token anyone issued and not
     * a PersonalAccessToken — so they are recorded as the user they are.
     * So is a person driving the API with a personal access token that
     * LACKS the agent's abilities (CLAUDE.md #3 supports exactly that
     * client): the token's CLASS alone used to type them "agent", which
     * would light the Control Center's "Agent last action" from a laptop.
     * A token that carries no name identifies no installation, and falls
     * back to the user too. Nobody at all is the system: an enqueue fired
     * by a domain event, a backfill.
     *
     * The label is the token's name or the user's name. Never the token.
     *
     * @return array{0: string, 1: int|string|null, 2: ?string}
     */
    private function describeActor(?Authenticatable $actor): array
    {
        if ($actor === null) {
            return [TallySyncEvent::ACTOR_SYSTEM, null, null];
        }

        $token = AgentIdentity::token($actor);

        if ($token !== null && is_string($token->name) && $token->name !== '') {
            return [TallySyncEvent::ACTOR_AGENT, $actor->getAuthIdentifier(), Str::limit($token->name, 120, '')];
        }

        $label = $actor instanceof User && is_string($actor->name) ? Str::limit($actor->name, 120, '') : null;

        return [TallySyncEvent::ACTOR_USER, $actor->getAuthIdentifier(), $label];
    }
}
