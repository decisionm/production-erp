<?php

namespace App\Modules\Production\Services;

/**
 * The five pack counts one run is measured and packed against, each with
 * the rung of PackQuantityResolver's precedence that supplied it.
 *
 * `source` is the one-word answer for the load-bearing figure — nos_per_box,
 * the count boxes are the completion input and the target is measured in;
 * when no rung answered that, the highest rung that answered anything, and
 * 'none' when nothing did. `sources` says it per figure, because a tray run
 * genuinely takes its box count from the packaging row and its pouch count
 * from nowhere, and a reader that has to explain a figure needs the rung
 * behind THAT figure.
 */
final class PackQuantities
{
    public const SOURCE_ENTRY = 'entry';

    public const SOURCE_PACKAGING = 'packaging';

    public const SOURCE_ITEM = 'item';

    public const SOURCE_NONE = 'none';

    public const FIELDS = ['nos_per_box', 'nos_per_tray', 'trays_per_box', 'nos_per_pouch', 'pouches_per_box'];

    /**
     * @param  'entry'|'packaging'|'item'|'none'  $source
     * @param  array<string, 'entry'|'packaging'|'item'|'none'>  $sources  keyed by FIELDS
     */
    public function __construct(
        public readonly ?int $nos_per_box,
        public readonly ?int $nos_per_tray,
        public readonly ?int $trays_per_box,
        public readonly ?int $nos_per_pouch,
        public readonly ?int $pouches_per_box,
        public readonly string $source,
        public readonly array $sources,
    ) {}

    /**
     * @return array{
     *     nos_per_box: ?int, nos_per_tray: ?int, trays_per_box: ?int,
     *     nos_per_pouch: ?int, pouches_per_box: ?int,
     *     source: string, sources: array<string, string>,
     * }
     */
    public function toArray(): array
    {
        return [
            'nos_per_box' => $this->nos_per_box,
            'nos_per_tray' => $this->nos_per_tray,
            'trays_per_box' => $this->trays_per_box,
            'nos_per_pouch' => $this->nos_per_pouch,
            'pouches_per_box' => $this->pouches_per_box,
            'source' => $this->source,
            'sources' => $this->sources,
        ];
    }
}
