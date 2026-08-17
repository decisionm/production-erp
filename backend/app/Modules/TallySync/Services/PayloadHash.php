<?php

namespace App\Modules\TallySync\Services;

/**
 * THE ONE hash of a sync entry's payload — sha256 over its canonical JSON
 * (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, keys in stored order).
 *
 * Two callers, one function, so they can never disagree:
 *   - TallySyncEntryResource stamps it as `payload_hash` on the AGENT's
 *     /pending rows — the agent echoes it back on its snapshot, never
 *     recomputes it;
 *   - TallySyncSnapshotService compares that echo with the hash of the
 *     payload the cloud holds NOW to judge `payload_matches` — false means
 *     the payload was regenerated (a retry) after the XML was built from it.
 *
 * It is a fingerprint for "same payload?", not a signature: nothing here is
 * secret, and nothing about what reaches Tally depends on it.
 */
final class PayloadHash
{
    /** @param  array<string, mixed>  $payload */
    public static function of(array $payload): string
    {
        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
