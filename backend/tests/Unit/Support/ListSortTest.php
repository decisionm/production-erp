<?php

namespace Tests\Unit\Support;

use App\Modules\Inventory\Models\Warehouse;
use App\Support\Lists\ListSort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ListSortTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_rule_accepts_id_and_each_column_bare_or_dashed_and_refuses_the_rest(): void
    {
        $rules = ['sort' => ListSort::rule(['name', 'code'])];

        foreach (['id', '-id', 'name', '-name', 'code', '-code'] as $ok) {
            $this->assertTrue(Validator::make(['sort' => $ok], $rules)->passes(), $ok);
        }
        foreach (['vendor', '--id', 'name asc', 'created_at'] as $bad) {
            $this->assertTrue(Validator::make(['sort' => $bad], $rules)->fails(), $bad);
        }
        $this->assertTrue(Validator::make([], $rules)->passes());
        $this->assertTrue(Validator::make(['sort' => null], $rules)->passes());
    }

    public function test_options_never_repeat_id(): void
    {
        $this->assertSame(['id', '-id', 'name', '-name'], ListSort::options(['id', 'name']));
    }

    public function test_apply_orders_by_the_named_column_with_id_desc_as_the_tiebreak(): void
    {
        Warehouse::create(['code' => 'B', 'name' => 'Same']);
        Warehouse::create(['code' => 'A', 'name' => 'Same']);
        Warehouse::create(['code' => 'C', 'name' => 'Other']);

        $byName = ListSort::apply(Warehouse::query(), 'name', ['name', 'code'])->pluck('code')->all();
        $this->assertSame(['C', 'A', 'B'], $byName, 'ties fall newest first');

        $byCodeDesc = ListSort::apply(Warehouse::query(), '-code', ['name', 'code'])->pluck('code')->all();
        $this->assertSame(['C', 'B', 'A'], $byCodeDesc);
    }

    public function test_apply_falls_back_to_the_default_for_an_absent_or_unknown_sort(): void
    {
        Warehouse::create(['code' => 'A', 'name' => 'First']);
        Warehouse::create(['code' => 'B', 'name' => 'Second']);

        $this->assertSame(['B', 'A'], ListSort::apply(Warehouse::query(), null, ['name'])->pluck('code')->all());
        $this->assertSame(['B', 'A'], ListSort::apply(Warehouse::query(), '  ', ['name'])->pluck('code')->all());
        $this->assertSame(['B', 'A'], ListSort::apply(Warehouse::query(), 'code', ['name'])->pluck('code')->all(), 'a column the service was not told about is not trusted');
        $this->assertSame(['A', 'B'], ListSort::apply(Warehouse::query(), null, ['name'], 'code')->pluck('code')->all(), 'a list may name its own default');
    }
}
