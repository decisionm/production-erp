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

        /*
         * IDENTIFIER QUOTING IS DIALECT-SPECIFIC and so is the FOR UPDATE
         * clause — MySQL writes `items`, SQLite writes "items", and Laravel's
         * SQLite grammar drops the lock clause entirely. Matching either
         * spelling literally passes on the driver it was written against and
         * fails on the other; this test did exactly that, green on local
         * SQLite and red on CI's MySQL 8. Quotes are stripped before matching
         * so what is asserted is the part that must hold on every driver:
         * ONE read of BOTH ids, ordered by id.
         */
        $reads = [];
        DB::listen(function ($query) use (&$reads) {
            $sql = strtolower(str_replace(['`', '"'], '', $query->sql));
            if (str_starts_with($sql, 'select') && str_contains($sql, 'from items') && str_contains($sql, ' in (')) {
                $reads[] = $sql;
            }
        });

        $service->update($variant, ['variant_of_item_id' => $base->id]);

        $this->assertCount(1, $reads, 'both rows must be read for locking by a single ordered statement.');
        $this->assertMatchesRegularExpression('/order by id asc/', $reads[0]);
    }

    /**
     * CREATING a variant loses the race the same way UPDATING one does.
     *
     * One request creates C -> A while another points A -> B. Both
     * validations see A as an unlinked base; only the update was taking the
     * locked path, so the create lands afterwards and leaves C -> A -> B
     * (Codex, 5543e21). The create re-checks under the target's lock too.
     */
    public function test_creating_a_variant_of_an_item_that_became_a_variant_is_refused(): void
    {
        $service = app(ItemService::class);

        $base = $this->item('SYN-BASE');
        $a = $this->item('SYN-A');

        // The other editor won: A is a variant now.
        $a->update(['variant_of_item_id' => $base->id]);

        $this->expectException(ValidationException::class);

        $service->create([
            'sku' => 'SYN-C',
            'name' => 'Synthetic C',
            'uom' => 'Nos.',
            'variant_of_item_id' => $a->id,
        ]);
    }

    public function test_creating_a_variant_of_a_real_base_still_goes_through(): void
    {
        $service = app(ItemService::class);

        $base = $this->item('SYN-BASE');

        $created = $service->create([
            'sku' => 'SYN-TRAY',
            'name' => 'Synthetic Tray',
            'uom' => 'Nos.',
            'variant_of_item_id' => $base->id,
            'variant_label' => '490/box tray',
        ]);

        $this->assertSame($base->id, $created->variant_of_item_id);
        $this->assertSame('490/box tray', $created->variant_label);
    }

    public function test_creating_a_plain_item_takes_no_lock_path(): void
    {
        $service = app(ItemService::class);

        $created = $service->create([
            'sku' => 'SYN-PLAIN',
            'name' => 'Synthetic Plain',
            'uom' => 'Nos.',
        ]);

        $this->assertNull($created->variant_of_item_id);
    }

    /**
     * A LABEL-ONLY EDIT loses the race too.
     *
     * The FormRequest reads the link off the route-bound model, so a payload
     * carrying only `variant_label` validates against the link as it was a
     * moment ago. If another request unlinks the item in between, the
     * label-only write commits last and leaves a base product wearing a
     * variant label — the exact state VariantLabelLifecycleTest forbids
     * through the front door (Codex, 2e3af77).
     */
    public function test_a_label_only_edit_is_refused_when_the_link_was_cleared_first(): void
    {
        $service = app(ItemService::class);

        $base = $this->item('SYN-BASE');
        $variant = $this->item('SYN-POUCH', [
            'variant_of_item_id' => $base->id,
            'variant_label' => '840/box pouch',
        ]);

        // The other editor won: the item is a base again, label cleared.
        $variant->update(['variant_of_item_id' => null, 'variant_label' => null]);

        $this->expectException(ValidationException::class);

        $service->update($variant, ['variant_label' => '840/box pouch']);
    }

    public function test_a_label_only_edit_on_a_still_linked_variant_goes_through(): void
    {
        $service = app(ItemService::class);

        $base = $this->item('SYN-BASE');
        $variant = $this->item('SYN-POUCH', [
            'variant_of_item_id' => $base->id,
            'variant_label' => '840/box pouch',
        ]);

        $service->update($variant, ['variant_label' => '812/box pouch']);

        $this->assertSame('812/box pouch', $variant->fresh()->variant_label);
    }

    /**
     * The SERVICE keeps the link/label pair whole, not just the FormRequest.
     *
     * `prepareVariantLabelForUnlink` runs in the request layer, so a caller
     * that reaches the service directly — a command, a sync, a future
     * controller — could unlink and set a label in one payload and leave a
     * base product wearing a variant label. The invariant belongs to the
     * write, so the write enforces it: clearing the link clears the label
     * whatever the payload says.
     */
    public function test_unlinking_through_the_service_drops_a_label_sent_with_it(): void
    {
        $service = app(ItemService::class);

        $base = $this->item('SYN-BASE');
        $variant = $this->item('SYN-POUCH', [
            'variant_of_item_id' => $base->id,
            'variant_label' => '840/box pouch',
        ]);

        $service->update($variant, [
            'variant_of_item_id' => null,
            'variant_label' => '840/box pouch',
        ]);

        $fresh = $variant->fresh();
        $this->assertNull($fresh->variant_of_item_id);
        $this->assertNull($fresh->variant_label);
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
