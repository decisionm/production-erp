<?php

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Catalogue\ColumnSpec;
use App\Modules\Assistant\Catalogue\SchemaCatalogue;
use App\Modules\Assistant\Catalogue\TableSpec;
use App\Modules\Assistant\Models\AskErpConversation;
use App\Modules\Assistant\Services\SchemaRetriever;
use App\Modules\HRMS\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Re-running a stored answer, and managing conversations.
 *
 * The re-run is the half of a recorded decision that was missing: a turn
 * keeps its SQL and not its rows, because rows are re-runnable — but nothing
 * re-ran them, so history was a sentence over an empty space.
 */
class AskErpHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ask-erp.driver' => 'rules']);
        $this->app->instance(SchemaCatalogue::class, SchemaCatalogue::fromArray([
            new TableSpec('employees', 'hrms', 'Employees', 'A person.', [
                new ColumnSpec('id', 'integer', meaning: 'pk'),
                new ColumnSpec('status', 'string', meaning: 'active | inactive'),
                new ColumnSpec('name', 'string', meaning: 'the person'),
            ], keywords: ['employee']),
        ]));
    }

    /** @param  list<string>  $permissions */
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

    private function answeredTurn(User $user, string $sql = 'SELECT e.status AS status FROM employees e LIMIT 200'): array
    {
        $conversation = AskErpConversation::create(['user_id' => $user->id, 'title' => 'Staff']);
        $message = $conversation->messages()->create([
            'role' => 'assistant',
            'question' => 'employees by status',
            'sql' => $sql,
            'answer' => '1 statuses.',
            'tables_used' => ['employees'],
            'row_count' => 1,
        ]);

        return [$conversation, $message];
    }

    public function test_rerunning_a_stored_answer_returns_its_rows_again(): void
    {
        $user = $this->login(['assistant.view', 'hrms.view']);
        Employee::create(['employee_code' => 'A', 'name' => 'A', 'date_of_joining' => '2026-01-01', 'status' => 'active']);
        [$conversation, $message] = $this->answeredTurn($user);

        $this->postJson("/api/v1/ask-erp/conversations/{$conversation->id}/messages/{$message->id}/rerun")
            ->assertOk()
            ->assertJsonPath('result.columns', ['status'])
            ->assertJsonPath('result.rows.0.status', 'active');
    }

    /**
     * THE ONE THAT MATTERS. A conversation outlives a role change, and the
     * asker need not still hold what they held. Replaying stored SQL would
     * make a saved message a way to keep reading a table after access to it
     * was withdrawn.
     */
    public function test_a_rerun_is_refused_once_the_reader_loses_the_table(): void
    {
        $user = $this->login(['assistant.view', 'hrms.view']);
        [$conversation, $message] = $this->answeredTurn($user);

        // Same person, same stored SQL, HRMS access gone.
        $user->revokePermissionTo('hrms.view');
        Sanctum::actingAs($user->fresh());

        $this->postJson("/api/v1/ask-erp/conversations/{$conversation->id}/messages/{$message->id}/rerun")
            ->assertStatus(422);
    }

    public function test_a_rerun_cannot_be_aimed_at_someone_elses_conversation(): void
    {
        $owner = $this->login(['assistant.view', 'hrms.view']);
        [$conversation, $message] = $this->answeredTurn($owner);

        $this->login(['assistant.view', 'hrms.view']);

        $this->postJson("/api/v1/ask-erp/conversations/{$conversation->id}/messages/{$message->id}/rerun")
            ->assertNotFound();
    }

    public function test_a_turn_with_no_sql_says_so_rather_than_failing(): void
    {
        $user = $this->login(['assistant.view', 'hrms.view']);
        $conversation = AskErpConversation::create(['user_id' => $user->id, 'title' => 'x']);
        $message = $conversation->messages()->create(['role' => 'assistant', 'question' => 'q', 'error' => 'refused']);

        $this->postJson("/api/v1/ask-erp/conversations/{$conversation->id}/messages/{$message->id}/rerun")
            ->assertStatus(422)
            ->assertJsonPath('message', 'That answer has no query to run again.');
    }

    public function test_a_conversation_can_be_renamed(): void
    {
        $user = $this->login(['assistant.view']);
        $conversation = AskErpConversation::create(['user_id' => $user->id, 'title' => 'employees by status']);

        $this->patchJson("/api/v1/ask-erp/conversations/{$conversation->id}", ['title' => 'Monday headcount'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Monday headcount');

        $this->patchJson("/api/v1/ask-erp/conversations/{$conversation->id}", ['title' => ''])->assertUnprocessable();
    }

    public function test_a_conversation_can_be_deleted_with_its_messages(): void
    {
        $user = $this->login(['assistant.view', 'hrms.view']);
        [$conversation, $message] = $this->answeredTurn($user);

        $this->deleteJson("/api/v1/ask-erp/conversations/{$conversation->id}")->assertNoContent();

        $this->assertDatabaseMissing('ask_erp_conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('ask_erp_messages', ['id' => $message->id]);
    }

    public function test_renaming_and_deleting_are_scoped_to_the_owner(): void
    {
        $owner = $this->login(['assistant.view']);
        $conversation = AskErpConversation::create(['user_id' => $owner->id, 'title' => 'Mine']);

        $this->login(['assistant.view']);

        $this->patchJson("/api/v1/ask-erp/conversations/{$conversation->id}", ['title' => 'Yours'])->assertNotFound();
        $this->deleteJson("/api/v1/ask-erp/conversations/{$conversation->id}")->assertNotFound();
        $this->assertDatabaseHas('ask_erp_conversations', ['id' => $conversation->id, 'title' => 'Mine']);
    }

    public function test_a_question_matching_nothing_names_questions_that_would(): void
    {
        $user = $this->login(['assistant.view', 'hrms.view']);
        $conversation = AskErpConversation::create(['user_id' => $user->id, 'title' => 'x']);

        // Nothing on screen to copy from once the suggestions are hidden, so
        // the refusal has to carry them.
        $this->postJson("/api/v1/ask-erp/conversations/{$conversation->id}/ask", ['question' => 'zzzz qqqq'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That question matches none of the tables you can see. Try naming what you want counted.');
    }

    /**
     * A WORD MATCHER MUST NOT DECIDE WHAT A MODEL CANNOT ANSWER.
     *
     * "today productivity?" was refused on the live floor before Claude ever
     * saw it: no table is called productivity, so the lexical rank found
     * nothing and the gate closed. On a model driver the retriever now falls
     * back to the tables the rule book names, so the model gets its chance to
     * answer or to decline. Permission still decides what is in that set.
     */
    public function test_the_fallback_table_set_is_still_permission_filtered(): void
    {
        $retriever = app(SchemaRetriever::class);

        $withHrms = $this->login(['assistant.view', 'hrms.view']);
        $this->assertNotSame([], $retriever->defaultTables($withHrms, 8));

        $withoutAnything = $this->login(['assistant.view']);
        $this->assertSame([], $retriever->defaultTables($withoutAnything, 8));
    }
}
