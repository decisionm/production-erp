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

    /**
     * EVERY ENTRY OF THE CONSTANT, driven off the constant itself.
     *
     * The providers below are hand-written samples, and a sample cannot see a
     * NEW entry somebody adds to KG_UOM_VARIANTS — which is exactly the drift
     * this file claims to pin. Iterating the constant closes that: add a
     * spelling to the SQL list and forget the PHP predicate, and this goes
     * red on the entry you added rather than staying green because no sample
     * happened to name it.
     */
    public function test_every_entry_of_the_sql_list_is_accepted_by_the_php_predicate(): void
    {
        $this->assertNotEmpty(Item::KG_UOM_VARIANTS);

        foreach (Item::KG_UOM_VARIANTS as $uom) {
            $item = $this->itemWithUom($uom, 'RM-CONST-'.md5($uom));

            $this->assertTrue(
                Item::isKgUom($uom),
                "KG_UOM_VARIANTS carries '{$uom}' but isKgUom() rejects it — the SQL list and the PHP predicate have drifted.",
            );
            $this->assertTrue(
                $item->hasKgUom(),
                "hasKgUom() rejects '{$uom}', which is in KG_UOM_VARIANTS.",
            );
            $this->assertTrue(
                Item::query()->kgUom()->whereKey($item->id)->exists(),
                "scopeKgUom() does not select '{$uom}', which is in its own list.",
            );
        }
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
     * WHERE PHP AND SQL DELIBERATELY DISAGREE, pinned by name so the parity
     * assertions above cannot be read as a claim of total equivalence.
     *
     * isKgUom() normalises; scopeKgUom() enumerates in SQL. PHP `rtrim(…,'.')`
     * strips a RUN of dots and PHP `trim()` strips tabs, while SQL `TRIM()`
     * strips spaces only and the list carries at most one dot. Tally cannot
     * produce these strings, so this is drift with no live consequence — but
     * it is real, and PHP being the SUPERSET is the safe direction: the scope
     * only ever narrows a candidate list, so a row it misses is a row a screen
     * does not offer, never a wrong number and never a new refusal.
     *
     * Narrowing PHP to match SQL would flip these accept -> reject. That is a
     * new refusal, which is precisely the mistake this branch already made
     * once in MeasurementType::forUom() and reverted.
     */
    #[DataProvider('phpOnlySpellings')]
    public function test_php_accepts_what_the_sql_list_cannot_and_that_is_the_safe_direction(string $uom): void
    {
        $item = $this->itemWithUom($uom, 'RM-'.md5('div'.$uom));

        $this->assertTrue(Item::isKgUom($uom), 'PHP normalises, so it accepts this.');
        $this->assertFalse(
            Item::query()->kgUom()->whereKey($item->id)->exists(),
            'The SQL scope enumerates, so it does not — pinned deliberately, not a bug to "fix" by narrowing PHP.',
        );
    }

    /** @return array<string, array{string}> */
    public static function phpOnlySpellings(): array
    {
        return [
            'a run of trailing dots' => ['Kgs..'],
            'three dots' => ['Kg...'],
            'trailing tab after the dot' => ["Kgs.\t"],
        ];
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

    /**
     * THE REFUSAL THIS BRANCH NEARLY SHIPPED, pinned so it cannot come back.
     *
     * An earlier revision of this change "fixed" the `Kilograms.` gap by
     * stripping a trailing dot inside MeasurementType::forUom(). rtrim() runs
     * before BOTH lookups, so it widened COUNT_UNITS too and moved these four
     * spellings Unknown -> Count. Unknown permits fractions; Count refuses
     * them, and permitsFractions() gates three live write paths
     * (StoreMaterialRequestRequest, StoreStoreIssueRequest,
     * StoreStoreIssueReturnRequest). A quantity the factory could enter
     * yesterday would have 422'd today.
     *
     * The frontend had already tried the same strip and backed it out for the
     * same reason — words.ts mirrors COUNT_UNITS verbatim, dotted spellings
     * and all, and says so. This list is one half of a two-sided contract.
     *
     * These must stay Unknown until the frontend mirror changes with them.
     */
    public function test_dotted_count_spellings_stay_unknown_and_keep_permitting_fractions(): void
    {
        foreach (['piece.', 'pieces.', 'each.', 'ea.'] as $uom) {
            $type = MeasurementType::forUom($uom);

            $this->assertSame(
                MeasurementType::Unknown,
                $type,
                "{$uom} must stay Unknown — classifying it Count adds a fraction refusal on three live write paths.",
            );
            $this->assertTrue(
                $type->permitsFractions(),
                "{$uom} must keep permitting fractions; refusing one is a gate that did not exist before.",
            );
        }
    }
}
