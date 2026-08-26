<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\ItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * THE ONE-LEVEL RULE HOLDS AT THE WRITE, not only at the form.
 *
 * ValidatesVariantLink refuses the three shapes that build a chain, but it
 * reads BEFORE the write and holds no lock. Two editors submitting A → B and
 * B → A at the same moment each see a target with no link and an item with no
 * variants, both validations pass, and the pair lands as a cycle (Codex,
 * 12766d3) — which `Item::variantRootId()`'s "one level, no walk" and the
 * identity grouping both assume cannot exist.
 *
 * So the service re-checks under a row lock, and these tests call it DIRECTLY,
 * bypassing the FormRequest exactly as a lost race would: the state each test
 * sets up is the state the loser's validation saw a moment ago, and the write
 * still refuses.
 */
class VariantLinkConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function item(string $sku, array $attributes = []): Item
    {
        return Item::create([
            'sku' => $sku,
            'name' => $attributes['name'] ?? "Synthetic {$sku}",
            'uom' => 'Nos.',
            ...$attributes,
        ]);
    }

    public function test_the_write_refuses_to_point_an_item_at_a_target_that_became_a_variant(): void
    {
        $service = app(ItemService::class);

        $base = $this->item('SYN-BASE');
        $a = $this->item('SYN-A');
        $b = $this->item('SYN-B');

        // The other editor won: B is a variant now, which A's validation did
        // not see.
        $b->update(['variant_of_item_id' => $base->id]);

        $this->expectException(ValidationException::class);

        $service->update($a, ['variant_of_item_id' => $b->id]);
    }

    public function test_the_write_refuses_to_make_a_base_with_variants_into_a_variant(): void
    {
        $service = app(ItemService::class);

        $a = $this->item('SYN-A');
        $b = $this->item('SYN-B');
        $c = $this->item('SYN-C');

        // The other editor won: B now points at A, so A is somebody's base —
        // the side rule 3 exists for, arriving after A's validation read.
        $b->update(['variant_of_item_id' => $a->id]);

        $this->expectException(ValidationException::class);

        $service->update($a, ['variant_of_item_id' => $c->id]);
    }

    /**
     * The two rows are locked in ONE statement, ascending by id.
     *
     * Locking the target first and the item second would have the reciprocal
     * writes this guard exists for take A-then-B and B-then-A: a deadlock,
     * which MySQL resolves by rolling one side back with an error instead of
     * the refusal the loser is owed (Cursor, def53c9). Ascending id is an
     * order both sides agree on whichever way the edit points — so the query
     * is asserted, because the ORDER is the guarantee and a same-process test
     * cannot stage the deadlock it prevents.
     */
    public function test_both_rows_are_locked_in_one_statement_ordered_by_id(): void
    {
        $service = app(ItemService::class);

        $base = $this->item('SYN-BASE');
        $variant = $this->item('SYN-POUCH');

        // The FOR UPDATE clause itself is dialect-specific — Laravel's SQLite
        // grammar drops it — so what is asserted is the part that has to be
        // true on every driver: ONE read of BOTH ids, ordered by id.
        $reads = [];
        DB::listen(function ($query) use (&$reads) {
            $sql = strtolower($query->sql);
            if (str_starts_with($sql, 'select') && str_contains($sql, 'from "items"') && str_contains($sql, ' in (')) {
                $reads[] = $sql;
            }
        });

        $service->update($variant, ['variant_of_item_id' => $base->id]);

        $this->assertCount(1, $reads, 'both rows must be read for locking by a single ordered statement.');
        $this->assertMatchesRegularExpression('/order by "?id"? asc/i', $reads[0]);
    }

    public function test_a_legitimate_link_still_goes_through(): void
    {
        $service = app(ItemService::class);

        $base = $this->item('SYN-BASE');
        $variant = $this->item('SYN-POUCH');

        $service->update($variant, [
            'variant_of_item_id' => $base->id,
            'variant_label' => '840/box pouch',
        ]);

        $this->assertSame($base->id, $variant->fresh()->variant_of_item_id);
        $this->assertSame('840/box pouch', $variant->fresh()->variant_label);
    }

    public function test_an_edit_that_does_not_touch_the_link_is_not_re_checked(): void
    {
        $service = app(ItemService::class);

        $a = $this->item('SYN-A');
        $b = $this->item('SYN-B');
        $b->update(['variant_of_item_id' => $a->id]);

        // A is a base with a variant — legal, and renaming it is nobody's
        // cycle. The guard must not turn an unrelated edit into a refusal.
        $service->update($a, ['display_name' => 'Synthetic A — clear']);

        $this->assertSame('Synthetic A — clear', $a->fresh()->display_name);
    }
}
