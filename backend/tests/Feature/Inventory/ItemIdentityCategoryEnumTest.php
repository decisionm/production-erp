<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Enums\ItemCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * THE THREE NEW CATEGORIES WERE ADDED ADDITIVELY, and this is what
 * "additively" is allowed to mean.
 *
 * Q59 — which categories may each document use — is an OPEN owner question.
 * So the eligibility answers for the four cases that existed before
 * 26-Aug-2026 must be BYTE-FOR-BYTE what they were, and the three new ones
 * must fall out of each rule the way an unnamed case always did. A refactor's
 * contract is the OLD refusal set: "no new gate" is no defence if the old
 * code already permitted something this one refuses.
 *
 * The expectations below are written out longhand rather than derived from
 * the enum, deliberately — a table generated from the code under test proves
 * only that the code equals itself.
 */
class ItemIdentityCategoryEnumTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool, 2: bool, 3: bool}>
     */
    public static function eligibility(): array
    {
        //                        case value        purchasable  sellable  requestable
        return [
            'raw material' => ['raw_material', true, false, true],
            'packing material' => ['packing_material', true, false, true],
            'finished good' => ['finished_good', false, true, false],
            'other' => ['other', true, false, false],
            // The three added cases: purchasable, not sellable, not
            // requestable — exactly where an unnamed case has always landed.
            'work in progress' => ['work_in_progress', true, false, false],
            'consumable' => ['consumable', true, false, false],
            'spare or tooling' => ['spare_tooling', true, false, false],
        ];
    }

    #[DataProvider('eligibility')]
    public function test_every_document_rule_answers_exactly_as_before(
        string $value,
        bool $purchasable,
        bool $sellable,
        bool $requestable,
    ): void {
        $case = ItemCategory::from($value);

        $this->assertSame($purchasable, $case->purchasable(), "{$value}->purchasable()");
        $this->assertSame($sellable, $case->sellable(), "{$value}->sellable()");
        $this->assertSame($requestable, $case->requestableFromStore(), "{$value}->requestableFromStore()");
    }

    public function test_the_three_new_cases_exist_and_nothing_was_removed(): void
    {
        $this->assertSame(
            [
                'raw_material', 'packing_material', 'finished_good', 'other',
                'work_in_progress', 'consumable', 'spare_tooling',
            ],
            array_map(fn (ItemCategory $case): string => $case->value, ItemCategory::cases()),
        );
    }

    /**
     * `label()` is a `match` with no default arm, so a case added and
     * forgotten here throws an UnhandledMatchError inside the very refusal
     * message that was supposed to explain something.
     */
    public function test_every_case_has_a_label_and_none_of_them_throws(): void
    {
        foreach (ItemCategory::cases() as $case) {
            $this->assertNotSame('', trim($case->label()), "{$case->value} has no label.");
        }
    }

    /** Finished goods remain the ONLY sellable category — nothing widened it. */
    public function test_finished_good_is_still_the_only_sellable_category(): void
    {
        $sellable = array_values(array_filter(
            ItemCategory::cases(),
            fn (ItemCategory $case): bool => $case->sellable(),
        ));

        $this->assertSame([ItemCategory::FinishedGood], $sellable);
    }

    /** Raw and packing remain the ONLY two the floor may request. */
    public function test_only_raw_and_packing_may_be_requested_from_the_store(): void
    {
        $requestable = array_values(array_filter(
            ItemCategory::cases(),
            fn (ItemCategory $case): bool => $case->requestableFromStore(),
        ));

        $this->assertSame([ItemCategory::RawMaterial, ItemCategory::PackingMaterial], $requestable);
    }
}
