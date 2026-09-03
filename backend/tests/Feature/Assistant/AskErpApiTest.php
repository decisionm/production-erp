<?php

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Catalogue\ColumnSpec;
use App\Modules\Assistant\Catalogue\SchemaCatalogue;
use App\Modules\Assistant\Catalogue\TableSpec;
use App\Modules\Assistant\Models\AskErpConversation;
use App\Modules\Assistant\Services\SqlDraft;
use App\Modules\Assistant\Services\SqlRequest;
use App\Modules\Assistant\Services\SqlWriter;
use App\Modules\HRMS\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AskErpApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(SchemaCatalogue::class, SchemaCatalogue::fromArray([
            new TableSpec('employees', 'hrms', 'Employees', 'A person.', [
                new ColumnSpec('id', 'integer', meaning: 'pk'),
                new ColumnSpec('status', 'string', meaning: 'active | inactive'),
            ], keywords: ['employee']),
        ]));
        $this->app->instance(SqlWriter::class, new class implements SqlWriter
        {
            public function write(SqlRequest $request): SqlDraft
            {
                return new SqlDraft('SELECT e.status, COUNT(*) AS n FROM employees e GROUP BY e.status', '{{count}} statuses.', 'bar');
            }
        });
        config(['ask-erp.api_key' => 'test-key']);
    }

    /** @param list<string> $permissions */
    private function login(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_requires_the_assistant_permission(): void
    {
        $this->login(['hrms.view']);

        $this->getJson('/api/v1/ask-erp/catalogue')->assertForbidden();
        $this->getJson('/api/v1/ask-erp/conversations')->assertForbidden();
        $this->postJson('/api/v1/ask-erp/conversations', [])->assertForbidden();
    }

    public function test_catalogue_lists_only_viewable_tables(): void
    {
        $this->login(['assistant.view']);
        $this->getJson('/api/v1/ask-erp/catalogue')->assertOk()->assertExactJson(['data' => [], 'configured' => true]);

        $this->login(['assistant.view', 'hrms.view']);
        $this->getJson('/api/v1/ask-erp/catalogue')->assertOk()->assertJsonPath('data.0.table', 'employees');
    }

    public function test_conversation_round_trip(): void
    {
        $this->login(['assistant.view', 'hrms.view']);
        Employee::create(['employee_code' => 'A', 'name' => 'A', 'date_of_joining' => '2026-01-01', 'status' => 'active']);

        $id = $this->postJson('/api/v1/ask-erp/conversations', ['title' => 'Staff'])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/ask-erp/conversations/{$id}/ask", ['question' => 'employees by status'])
            ->assertOk()
            ->assertJsonPath('message.answer', '1 statuses.')
            ->assertJsonPath('result.columns', ['status', 'n'])
            ->assertJsonPath('result.rows.0.status', 'active');

        $this->getJson("/api/v1/ask-erp/conversations/{$id}")->assertOk()->assertJsonCount(2, 'data.messages');
        $this->getJson('/api/v1/ask-erp/conversations?q=staff')->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.message_count', 2)
            ->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/ask-erp/conversations?q=nothing')->assertOk()->assertJsonCount(0, 'data');

        $this->login(['assistant.view']);
        $this->getJson("/api/v1/ask-erp/conversations/{$id}")->assertNotFound();
        $this->postJson("/api/v1/ask-erp/conversations/{$id}/ask", ['question' => 'x'])->assertNotFound();
    }

    public function test_untitled_conversation_takes_the_first_question_as_its_title(): void
    {
        $this->login(['assistant.view', 'hrms.view']);

        $id = $this->postJson('/api/v1/ask-erp/conversations', [])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/ask-erp/conversations/{$id}/ask", ['question' => 'employees by status'])->assertOk();

        $this->assertDatabaseHas('ask_erp_conversations', ['id' => $id, 'title' => 'employees by status']);
    }

    public function test_question_is_required_and_capped(): void
    {
        $user = $this->login(['assistant.view', 'hrms.view']);
        $conversation = AskErpConversation::create(['user_id' => $user->id, 'title' => 'x']);

        $this->postJson("/api/v1/ask-erp/conversations/{$conversation->id}/ask", ['question' => ''])->assertUnprocessable();
        $this->postJson("/api/v1/ask-erp/conversations/{$conversation->id}/ask", ['question' => str_repeat('a', 501)])->assertUnprocessable();
    }

    public function test_refusal_is_a_422_with_the_reason(): void
    {
        $user = $this->login(['assistant.view']);
        $conversation = AskErpConversation::create(['user_id' => $user->id, 'title' => 'x']);

        $this->postJson("/api/v1/ask-erp/conversations/{$conversation->id}/ask", ['question' => 'employees'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That question matches none of the tables you can see. Try naming what you want counted.');
    }

    public function test_unconfigured_server_answers_503(): void
    {
        config(['ask-erp.api_key' => null]);
        $this->app->forgetInstance(SqlWriter::class);
        $user = $this->login(['assistant.view', 'hrms.view']);
        $conversation = AskErpConversation::create(['user_id' => $user->id, 'title' => 'x']);

        $this->getJson('/api/v1/ask-erp/catalogue')->assertOk()->assertJsonPath('configured', false);
        $this->postJson("/api/v1/ask-erp/conversations/{$conversation->id}/ask", ['question' => 'employees'])->assertStatus(503);
    }
}
