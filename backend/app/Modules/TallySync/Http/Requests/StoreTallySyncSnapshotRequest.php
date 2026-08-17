<?php

namespace App\Modules\TallySync\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The agent's post-Tally snapshot report — POST /tally-sync/entries/{id}/snapshot
 * (Phase 4). Matches tally-sync-agent/src/snapshot.ts SnapshotBody:
 *
 *   xml           the exact XML posted, whole, up to 2 MB — ABSENT (or null)
 *                 when the agent's document was over that cap
 *   xml_sha256    sha256 hex of that XML — REQUIRED with or without the body;
 *                 when the body IS here the server recomputes it and refuses
 *                 a mismatch (422): a snapshot whose hash does not match its
 *                 own body is not a record of anything
 *   xml_bytes     the agent's own byte count — read ONLY when no body came
 *                 (with a body the server measures); optional
 *   attempt       the 1-based ordinal of THIS post as the agent counted it
 *                 (attempts at hand-out + 1); null → stored as 0, "not counted"
 *   tally         Tally's answer {success, created, errors, message?, raw?} —
 *                 null when nothing came back (inconclusive timeout)
 *   agent_version the agent's package.json version
 *   payload_hash  the payload_hash the cloud stamped on the /pending row,
 *                 echoed back for the payload_matches verdict
 *
 * The 64 KB caps on `raw` and `message` are BYTES as well as characters:
 * both land in TEXT columns (65535 bytes on MySQL), and the agent caps raw
 * by UTF-8 bytes for exactly that reason (SNAPSHOT_RAW_CAP_BYTES).
 */
class StoreTallySyncSnapshotRequest extends FormRequest
{
    /** 2 MiB — the agent omits the body above this and still sends the sha (SNAPSHOT_XML_CAP_BYTES). */
    public const XML_MAX_CHARS = 2 * 1024 * 1024;

    /** TEXT column capacity on MySQL, in bytes. */
    public const TEXT_MAX_BYTES = 65535;

    private const SHA256_HEX = '/^[0-9a-fA-F]{64}$/';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'xml' => ['nullable', 'string', 'max:'.self::XML_MAX_CHARS],
            'xml_sha256' => ['required', 'string', 'size:64', 'regex:'.self::SHA256_HEX],
            'xml_bytes' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'attempt' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'tally' => ['nullable', 'array'],
            'tally.success' => ['required_with:tally', 'boolean'],
            'tally.created' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'tally.errors' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'tally.message' => ['nullable', 'string', 'max:'.self::TEXT_MAX_BYTES, $this->fitsText()],
            'tally.raw' => ['nullable', 'string', 'max:'.self::TEXT_MAX_BYTES, $this->fitsText()],
            'agent_version' => ['nullable', 'string', 'max:32'],
            'payload_hash' => ['nullable', 'string', 'size:64', 'regex:'.self::SHA256_HEX],
        ];
    }

    /**
     * The sha the agent claims must be the sha of the body it sent. Judged
     * after the field rules, so a malformed sha reports as malformed and
     * not as "does not match".
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $xml = $this->input('xml');
                if (! is_string($xml) || $xml === '') {
                    return;
                }

                if (! hash_equals(hash('sha256', $xml), strtolower((string) $this->input('xml_sha256')))) {
                    $validator->errors()->add(
                        'xml_sha256',
                        'xml_sha256 does not match the sha256 of the xml body sent — the snapshot was not stored.',
                    );
                }
            },
        ];
    }

    /** A TEXT column holds 65535 BYTES; `max:` counts characters. Both caps hold. */
    private function fitsText(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && strlen($value) > self::TEXT_MAX_BYTES) {
                $fail("The {$attribute} field must not be longer than ".self::TEXT_MAX_BYTES.' bytes.');
            }
        };
    }
}
