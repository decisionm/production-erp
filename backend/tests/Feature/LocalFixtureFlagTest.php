<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * THE POSTING GATE IS A COLUMN, NOT A TEXT FIELD.
 *
 * Whether a batch's voucher reaches Tally at all runs through
 * Item::isLocalFixture(). It used to be `str_starts_with($sku, 'LOCAL-')`,
 * which put that gate on a free-text field — and the SKU is about to become
 * owner-managed data across 644 items. From that point a single office typo
 * could silently stop a real product posting, or start a fixture posting a
 * name Tally cannot accept, and neither would fail loudly.
 *
 * These tests pin the behaviour that makes a typo survivable.
 */
class LocalFixtureFlagTest extends TestCase
{
    use RefreshDatabase;

    private function item(array $attributes = []): Item
    {
        return Item::create([
            'sku' => 'BTL-450-RIB-AMB-34',
            'name' => 'A real product',
            'uom' => 'NOS',
            'is_active' => true,
            ...$attributes,
        ]);
    }

    public function test_the_column_alone_marks_a_fixture(): void
    {
        // The point of the change: no LOCAL- prefix anywhere, and it is still
        // a fixture because somebody said so.
        $item = $this->item(['sku' => 'TRY-200-STD', 'is_local_fixture' => true]);

        $this->assertTrue($item->isLocalFixture());
    }

    public function test_a_real_item_is_not_a_fixture(): void
    {
        $this->assertFalse($this->item()->isLocalFixture());
    }

    public function test_the_prefix_still_works_as_a_fallback(): void
    {
        // A row written before the column existed, or by an importer that does
        // not know about it yet, must still be refused a voucher.
        $item = $this->item(['sku' => 'LOCAL-500ML-THING', 'is_local_fixture' => false]);

        $this->assertTrue($item->isLocalFixture());
    }

    public function test_renaming_a_fixture_off_the_prefix_does_not_start_it_posting(): void
    {
        // THE TYPO THIS EXISTS FOR. Bulk SKU assignment renames a fixture to
        // something sensible. Before the column, that alone would have made it
        // start posting a name Tally cannot accept.
        $item = $this->item(['sku' => 'LOCAL-OLD', 'is_local_fixture' => true]);
        $item->update(['sku' => 'BOX-200-RND']);

        $this->assertTrue($item->fresh()->isLocalFixture(), 'a renamed fixture must not start posting');
    }

    public function test_a_disagreement_is_logged_loudly_rather_than_resolved_silently(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'disagrees with its SKU'));

        $this->item(['sku' => 'LOCAL-SOMETHING', 'is_local_fixture' => false])->isLocalFixture();
    }

    public function test_agreement_is_silent(): void
    {
        Log::shouldReceive('warning')->never();

        $this->item(['sku' => 'LOCAL-X', 'is_local_fixture' => true])->isLocalFixture();
        $this->item(['sku' => 'BTL-090-RIB-CLR-085', 'is_local_fixture' => false])->isLocalFixture();
    }

    public function test_no_generated_sku_may_ever_begin_with_the_fixture_prefix(): void
    {
        // The owner's hard rule for the coming SKU scheme, asserted rather than
        // left in a comment. Every code the format can produce must be safe to
        // assign to a real item without changing whether it posts.
        $generated = [
            'BTL-450-RIB-AMB-34',
            'BTL-090-RIB-CLR-085',
            'TCN-200-STD-CLR',
            'BOX-200-RND',
            'TRY-100-STD',
            'POU-750-STD',
            'COV-030-LDP',
            'TAP-065-BRN',
            'RSN-RELPET',
            'MB-AMBER',
        ];

        foreach ($generated as $sku) {
            $this->assertStringStartsNotWith(
                Item::LOCAL_FIXTURE_SKU_PREFIX,
                $sku,
                "a generated SKU must never look like a local fixture: {$sku}",
            );
            $this->assertFalse(
                $this->item(['sku' => $sku])->isLocalFixture(),
                "assigning {$sku} to a real item must not stop it posting",
            );
        }
    }
}
