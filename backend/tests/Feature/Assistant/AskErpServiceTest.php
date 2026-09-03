<?php

namespace Tests\Feature\Assistant;

use App\Models\User;
use App\Modules\Assistant\Catalogue\ColumnSpec;
use App\Modules\Assistant\Catalogue\SchemaCatalogue;
use App\Modules\Assistant\Catalogue\TableSpec;
use App\Modules\Assistant\Exceptions\AskErpException;
use App\Modules\Assistant\Models\AskErpConversation;
use App\Modules\Assistant\Services\AskErpService;
use App\Modules\Assistant\Services\SqlDraft;
use App\Modules\Assistant\Services\SqlRequest;
use App\Modules\Assistant\Services\SqlWriter;
use App\Modules\HRMS\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AskErpServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @param list<string> $permissions */
    private function user(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function catalogue(): void
    {
        $this->app->instance(SchemaCatalogue::class, SchemaCatalogue::fromArray([
            new TableSpec('employees', 'hrms', 'Employees', 'A person on the payroll.', [
                new ColumnSpec('id', 'integer', meaning: 'pk'),
                new ColumnSpec('employee_code', 'string', meaning: 'code'),
                new ColumnSpec('name', 'string', meaning: 'name'),
                new ColumnSpec('status', 'string', meaning: 'active | inactive | terminated'),
                new ColumnSpec('phone', 'string', nullable: true, meaning: 'mobile', sensitive: 'personal'),
                new ColumnSpec('date_of_joining', 'date', meaning: 'joined'),
                new ColumnSpec('deleted_at', 'datetime', nullable: true, meaning: 'archived at'),
            ], keywords: ['staff', 'employee', 'worker']),
        ]));
    }

    private function writer(string $sql): void
    {
        $this->app->instance(SqlWriter::class, new class($sql) implements SqlWriter
        {
            public ?SqlRequest $seen = null;

            public function __construct(private readonly string $sql) {}

            public function write(SqlRequest $request): SqlDraft
            {
                $this->seen = $request;

                return new SqlDraft($this->sql, '{{count}} employees by status; {{first.status}} first.', 'bar');
            }
        });
    }

    private function employee(string $code, string $status): void
    {
        Employee::create(['employee_code' => $code, 'name' => $code, 'date_of_joining' => '2026-01-01', 'status' => $status]);
    }

    public function test_answers_from_the_database_and_stores_the_turn(): void
    {
        $this->catalogue();
        $this->writer('SELECT e.status, COUNT(*) AS n FROM employees e GROUP BY e.status ORDER BY n DESC');
        $this->employee('A', 'active');
        $this->employee('B', 'active');
        $this->employee('C', 'inactive');

        $user = $this->user(['assistant.view', 'hrms.view']);
        $conversation = AskErpConversation::create(['user_id' => $user->id, 'title' => 'Staff']);

        $message = app(AskErpService::class)->ask($user, $conversation, 'employees by status');

        $this->assertSame('assistant', $message->role);
        $this->assertSame('2 employees by status; active first.', $message->answer);
        $this->assertSame(['employees'], $message->tables_used);
        $this->assertSame(2, $message->row_count);
        $this->assertSame(['status', 'n'], $message->result['columns']);
        $this->assertSame(['type' => 'bar', 'x' => 'status', 'y' => 'n'], $message->result['chart']);
        $this->assertStringEndsWith('LIMIT 200', $message->sql);
        $this->assertDatabaseCount('ask_erp_messages', 2);
        $this->assertDatabaseHas('ask_erp_messages', ['role' => 'user', 'question' => 'employees by status']);
    }

    public function test_hidden_column_is_kept_from_the_model_and_refused_if_used(): void
    {
        $this->catalogue();
        $this->writer('SELECT e.name, e.phone FROM employees e');
        $user = $this->user(['assistant.view', 'hrms.view']);
        $conversation = AskErpConversation::create(['user_id' => $user->id, 'title' => 'Phones']);

        try {
            app(AskErpService::class)->ask($user, $conversation, 'staff phone numbers');
            $this->fail('expected refusal');
        } catch (AskErpException $e) {
            $this->assertStringContainsString('employees.phone', $e->getMessage());
            $this->assertSame(422, $e->status);
        }

        $writer = app(SqlWriter::class);
        $this->assertStringNotContainsString('phone', implode("\n", $writer->seen->tableSpecs));
        $this->assertDatabaseHas('ask_erp_messages', ['role' => 'assistant', 'error' => $e->getMessage()]);
    }

    public function test_hrms_manager_may_see_the_personal_column(): void
    {
        $this->catalogue();
        $this->writer('SELECT e.name, e.phone FROM employees e');
        $this->employee('A', 'active');
        $user = $this->user(['assistant.view', 'hrms.manage']);
        $conversation = AskErpConversation::create(['user_id' => $user->id, 'title' => 'Phones']);

        $message = app(AskErpService::class)->ask($user, $conversation, 'staff phone numbers');

        $this->assertSame(['name', 'phone'], $message->result['columns']);
    }

    public function test_module_the_user_cannot_view_yields_no_tables(): void
    {
        $this->catalogue();
        $this->writer('SELECT COUNT(*) AS n FROM employees');
        $user = $this->user(['assistant.view']);
        $conversation = AskErpConversation::create(['user_id' => $user->id, 'title' => 'x']);

        $this->expectException(AskErpException::class);
        $this->expectExceptionMessage('none of the tables');

        app(AskErpService::class)->ask($user, $conversation, 'how many employees');
    }

    public function test_history_is_replayed_to_the_writer(): void
    {
        $this->catalogue();
        $this->writer('SELECT COUNT(*) AS n FROM employees e');
        $user = $this->user(['assistant.view', 'hrms.view']);
        $conversation = AskErpConversation::create(['user_id' => $user->id, 'title' => 'x']);
        $service = app(AskErpService::class);

        $service->ask($user, $conversation, 'how many employees');
        $service->ask($user, $conversation, 'and active ones');

        $seen = app(SqlWriter::class)->seen;
        $this->assertCount(1, $seen->history);
        $this->assertSame('how many employees', $seen->history[0]['question']);
        $this->assertStringContainsString('COUNT(*)', $seen->history[0]['sql']);
    }

    public function test_a_failing_query_is_a_422_and_is_recorded(): void
    {
        $this->catalogue();
        $this->writer('SELECT e.nope FROM employees e');
        $user = $this->user(['assistant.view', 'hrms.view']);
        $conversation = AskErpConversation::create(['user_id' => $user->id, 'title' => 'x']);

        try {
            app(AskErpService::class)->ask($user, $conversation, 'employees');
            $this->fail('expected failure');
        } catch (AskErpException $e) {
            $this->assertStringStartsWith('The query failed', $e->getMessage());
        }

        $this->assertDatabaseHas('ask_erp_messages', ['role' => 'assistant', 'error' => $e->getMessage()]);
    }
}
