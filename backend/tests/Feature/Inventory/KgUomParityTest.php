<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Enums\MeasurementType;
use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * "IS THIS UNIT KILOGRAMS" HAS EXACTLY ONE ANSWER, and this pins it.
 *
 * It had two until 23-Aug-2026. Four services carried a private isMassUom()
 * that normalised the trailing dot and accepted 'kilogram(s)';
 * Item::hasKgUom() enumerated the dotted spellings and accepted neither
 * kilogram form. An item whose Tally unit read 'Kilograms' therefore
 * contributed to a BOM weight norm while simultaneously failing hasKgUom(),
 * and nothing said which was right.
 *
 * The duplication is now gone — Item::isKgUom() is the only definition — but
 * deleting copies does not stop new ones appearing. What actually holds the
 * line is the parity assertion below: the SQL scope enumerates spellings
 * (portably, because MySQL and SQLite strip a trailing dot with different
 * syntax) while the PHP predicate normalises, so the two CAN drift. Every
 * spelling is asserted through both paths, against the real database.
 */
class KgUomParityTest extends TestCase
{
    use RefreshDatabase;

    private function itemWithUom(string $uom, string $sku): Item
    {
        return Item::query()->create([
            'sku' => $sku,
            'name' => "Item {$uom}",
            'uom' => $uom,
            'item_type' => 'raw_material',
            'is_active' => true,
        ]);
    }

    /** @return array<string, array{string}> */
    public static function kilogramSpellings(): array
    {
        return [
            'kg' => ['kg'],
            'Kg' => ['Kg'],
            'KG' => ['KG'],
            'kg with the Tally trailing dot' => ['Kg.'],
            'kgs' => ['Kgs'],
            'KGS' => ['KGS'],
            // The spelling the live masters actually carry on 90+ items.
            'Kgs. as Tally writes it' => ['Kgs.'],
            // The two the old hasKgUom() missed while four services accepted them.
            'kilogram' => ['Kilogram'],
            'kilograms' => ['Kilograms'],
            'kilograms with a trailing dot' => ['Kilograms.'],
            'padded' => ['  Kgs.  '],
        ];
    }

    /** @return array<string, array{string}> */
    public static function nonKilogramSpellings(): array
    {
        return [
            'Nos as Tally writes it' => ['Nos.'],
            'Nos' => ['Nos'],
            'Pcs' => ['Pcs.'],
            'empty' => [''],
            'whitespace only' => ['   '],
            // Grams are WEIGHT but are not kilograms. Every caller of this
            // predicate sums the quantity as kg, so accepting a gram unit
            // would read 0.32 g of masterbatch as 0.32 kg.
            'grams' => ['gms'],
            'gram' => ['gram'],
            'g' => ['g'],
            // Near-misses that must not match.
            'kilogramme' => ['kilogramme'],
            'kgm' => ['kgm'],
        ];
    }

    #[DataProvider('kilogramSpellings')]
    public function test_php_and_sql_agree_that_a_spelling_is_kilograms(string $uom): void
    {
        $item = $this->itemWithUom($uom, 'RM-'.md5($uom));

        $this->assertTrue(Item::isKgUom($uom), "isKgUom() must accept {$uom}");
        $this->assertTrue($item->hasKgUom(), "hasKgUom() must accept {$uom}");
        $this->assertTrue(
            Item::query()->kgUom()->whereKey($item->id)->exists(),
            "scopeKgUom() must select an item whose uom is {$uom} — the SQL list and the PHP predicate have drifted.",
        );
    }

    #[DataProvider('nonKilogramSpellings')]
    public function test_php_and_sql_agree_that_a_spelling_is_not_kilograms(string $uom): void
    {
        $item = $this->itemWithUom($uom, 'RM-'.md5('not'.$uom));

        $this->assertFalse(Item::isKgUom($uom), "isKgUom() must reject {$uom}");
        $this->assertFalse($item->hasKgUom(), "hasKgUom() must reject {$uom}");
        $this->assertFalse(
            Item::query()->kgUom()->whereKey($item->id)->exists(),
            "scopeKgUom() must NOT select an item whose uom is {$uom}.",
        );
    }

    /**
     * THE REGRESSION THAT STARTED THIS, stated as its own test so the reason
     * survives even if the providers above are edited.
     */
    public function test_kilograms_is_accepted_which_the_old_enumerated_list_refused(): void
    {
        $item = $this->itemWithUom('Kilograms', 'RM-KILOGRAMS');

        $this->assertTrue($item->hasKgUom());
        $this->assertTrue(Item::query()->kgUom()->whereKey($item->id)->exists());
    }

    /**
     * KILOGRAMS AND WEIGHT ARE DIFFERENT QUESTIONS, and merging them would be
     * the obvious "cleanup" for someone who reads only one of the two.
     *
     * MeasurementType is the broader classifier and the right one for "is
     * this weighed rather than counted" — it answers Weight for grams. Every
     * caller of isKgUom() sums the quantity AS KILOGRAMS, so a gram unit must
     * classify as Weight and still be refused by isKgUom(). This asserts both
     * halves at once, which is the only way the distinction survives.
     */
    public function test_grams_are_weight_but_are_not_kilograms(): void
    {
        foreach (['gm', 'gms', 'g', 'gram', 'grams'] as $uom) {
            $this->assertSame(
                MeasurementType::Weight,
                MeasurementType::forUom($uom),
                "{$uom} is a weight unit",
            );
            $this->assertFalse(
                Item::isKgUom($uom),
                "{$uom} must NOT be treated as kilograms — callers sum the quantity as kg.",
            );
        }
    }

    /**
     * Every kilogram spelling must also classify as Weight. If one did not,
     * the two classifiers would disagree about the same item in the opposite
     * direction from the bug this change fixed.
     */
    #[DataProvider('kilogramSpellings')]
    public function test_every_kilogram_spelling_also_classifies_as_weight(string $uom): void
    {
        $this->assertSame(MeasurementType::Weight, MeasurementType::forUom(trim($uom)));
    }
}
