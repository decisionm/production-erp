<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The user list sorts on the SERVER (03-Sep-2026): `sort` is validated at
 * the door, a named column orders the whole set with `id desc` as the
 * tiebreak, and `per_page` pages with the real total. The acting user is a
 * row of the list like any other (is_system false).
 */
class UsersListSortingTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actor = User::factory()->create(['name' => 'Zeta Admin', 'email' => 'zeta@example.test', 'is_active' => true]);
        Permission::findOrCreate('users.view', 'web');
        $this->actor->givePermissionTo('users.view');
        Sanctum::actingAs($this->actor);
    }

    /** @return list<int> */
    private function ids(string $url): array
    {
        return array_map(fn (array $row) => $row['id'], $this->getJson($url)->assertOk()->json('data'));
    }

    public function test_users_refuse_a_sort_column_they_do_not_have(): void
    {
        $this->getJson('/api/v1/users?sort=nonsense')->assertStatus(422)->assertJsonValidationErrors('sort');
        // Roles are a relation, not a column.
        $this->getJson('/api/v1/users?sort=roles')->assertStatus(422)->assertJsonValidationErrors('sort');
    }

    public function test_users_sort_descending_on_name_with_newest_id_breaking_the_tie(): void
    {
        $alphaOld = User::factory()->create(['name' => 'Alpha', 'email' => 'alpha.one@example.test']);
        $alphaNew = User::factory()->create(['name' => 'Alpha', 'email' => 'alpha.two@example.test']);

        $this->assertSame([$this->actor->id, $alphaNew->id, $alphaOld->id], $this->ids('/api/v1/users?sort=-name'));
        // The default is still name order.
        $this->assertSame([$alphaNew->id, $alphaOld->id, $this->actor->id], $this->ids('/api/v1/users'));
    }

    public function test_users_page_with_the_real_total(): void
    {
        User::factory()->create(['name' => 'One', 'email' => 'one@example.test']);
        User::factory()->create(['name' => 'Two', 'email' => 'two@example.test']);

        $this->getJson('/api/v1/users?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3);
    }
}
