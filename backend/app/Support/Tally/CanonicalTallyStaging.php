<?php

namespace App\Support\Tally;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use JsonException;

/**
 * The `tally_staging` cast for purchase orders and goods receipts — an
 * 'array' cast with ONE canonical key order on every read and write.
 *
 * WHY IT EXISTS: MySQL's JSON column type stores a binary form and hands
 * objects back with the keys REORDERED (by length, then bytes), while a
 * freshly written model still holds the writer's insertion order and
 * sqlite hands back exactly what was stored. So the same receipt
 * serialized twice — once straight after creation, once re-read on an
 * idempotent replay — carried identical fields in two different orders,
 * and the replay contract ("a replay returns the original receipt in
 * full", asserted STRICTLY) failed on MySQL alone. The fix is one
 * canonical order at the cast, not a looser test: the API's promise is
 * byte-stable responses, and the test stays strict to keep it.
 *
 * The canonical order is the writers' own
 * (PurchaseOrderService/GoodsReceiptService::recordTallyStaging):
 * state · reasons · at · entry_id · after, each reason as code · detail.
 * Unknown keys survive, appended in the order they arrived, so a future
 * writer's addition round-trips rather than vanishing.
 */
class CanonicalTallyStaging implements CastsAttributes
{
    private const KEY_ORDER = ['state', 'reasons', 'at', 'entry_id', 'after'];

    private const REASON_KEY_ORDER = ['code', 'detail'];

    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        try {
            $decoded = json_decode((string) $value, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? self::canonical($decoded) : null;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode(self::canonical((array) $value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $staging */
    private static function canonical(array $staging): array
    {
        $ordered = [];
        foreach (self::KEY_ORDER as $key) {
            if (array_key_exists($key, $staging)) {
                $ordered[$key] = $staging[$key];
                unset($staging[$key]);
            }
        }
        // Whatever a future writer adds, kept — after the known keys.
        $ordered += $staging;

        if (is_array($ordered['reasons'] ?? null)) {
            $ordered['reasons'] = array_map(
                fn ($reason) => is_array($reason) ? self::canonicalReason($reason) : $reason,
                array_values($ordered['reasons']),
            );
        }

        return $ordered;
    }

    /** @param array<string, mixed> $reason */
    private static function canonicalReason(array $reason): array
    {
        $ordered = [];
        foreach (self::REASON_KEY_ORDER as $key) {
            if (array_key_exists($key, $reason)) {
                $ordered[$key] = $reason[$key];
                unset($reason[$key]);
            }
        }

        return $ordered + $reason;
    }
}
