<?php

namespace App\Modules\Production\Exceptions;

use App\Exceptions\DomainException;
use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Services\ProductVariantService;
use RuntimeException;

/**
 * Start Batch, refused: the selected packing posts as a DIFFERENT Tally
 * stock item from the product being run (DEC-20260821-001).
 *
 * Where Tally carries separate stock items for a product's pouch and tray
 * packings, the ERP holds two separate finished-product masters — not two
 * packaging identities under one product. So this is not "fix the packing":
 * the packing is describing a product of its own, and the batch belongs
 * under that product. The message says which packing, which product it is
 * offered under, which Tally item it would have posted as, and what to do.
 *
 * FORWARD ONLY. It is raised before the batch row is written and nowhere
 * else — never at completion or amendment, where an entry that predates the
 * rule re-resolves its frozen identity on every re-completion and a refusal
 * would leave a real, already-worked shift uncompletable.
 *
 * Renders as a 422 through the DomainException handler, carrying `code`
 * plus the three named parties so a client can offer the right product
 * without parsing the sentence.
 */
class PackagingBelongsToSeparateProductException extends RuntimeException implements DomainException
{
    /** @param array<string, mixed> $parties */
    public function __construct(string $message, private readonly array $parties)
    {
        parent::__construct($message);
    }

    public static function make(
        ProductionStandardPackaging $packaging,
        ?Item $product,
        ?Item $packagingItem,
    ): self {
        $packing = trim($packaging->label()) ?: "packing #{$packaging->id}";
        $productName = trim((string) ($product?->name ?? '')) ?: 'this product';
        $identityName = trim((string) ($packagingItem?->name ?? '')) ?: "item #{$packaging->item_id}";

        return new self(
            "The {$packing} packing posts to Tally as \"{$identityName}\", which is not \"{$productName}\" — "
            .'the product this batch would run. '
            .ProductVariantService::SEPARATE_PRODUCT_REASON.' '
            .ProductVariantService::SEPARATE_PRODUCT_INSTRUCTION,
            [
                'packaging' => ['id' => (int) $packaging->id, 'label' => $packing],
                'product' => $product === null ? null : ['id' => (int) $product->id, 'name' => (string) $product->name],
                'packaging_item' => $packagingItem === null
                    ? ['id' => $packaging->item_id === null ? null : (int) $packaging->item_id, 'name' => null]
                    : ['id' => (int) $packagingItem->id, 'name' => (string) $packagingItem->name],
            ],
        );
    }

    public function errorCode(): string
    {
        return 'packaging_belongs_to_separate_product';
    }

    /**
     * Extra 422 body keys — see bootstrap/app.php's DomainException render.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->parties;
    }
}
