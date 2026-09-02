# Ask-the-ERP (schema catalogue + chat page) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A permission-aware chat page where a user asks a question in English and gets an answer computed from the ERP database, using only the tables of modules that user may view.

**Architecture:** A one-file-per-table YAML catalogue (`backend/resources/schema-catalogue/`) is the model's only knowledge of the schema. `SchemaRetriever` picks the tables relevant to a question from the subset the user's permissions allow; `AnthropicSqlWriter` asks Claude for one SELECT as structured JSON; `SqlGuard` refuses anything but a single read on allowed tables and columns; `QueryRunner` executes on a read-only connection with a row cap; the page renders the sentence, table and a small chart. All of it lives in a new `Assistant` module (`app/Modules/Assistant`) behind a new `assistant` permission module.

**Tech Stack:** Laravel 13 / PHP 8.3+, `anthropic-ai/sdk` (official PHP SDK, structured outputs), `symfony/yaml`, MySQL live / SQLite in tests, React + TypeScript + Ant Design + TanStack Query, vitest, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-09-03-hrms-attendance-and-ask-erp-design.md` (Track 3)

## Global Constraints

- Module pattern from CLAUDE.md: thin controllers, FormRequests, Services, Resources; routes under `/api/v1` grouped by module and guarded by `module:assistant`.
- Permission names are `assistant.view` / `assistant.manage`; the module MUST be added to `PermissionService::MODULES` or `RoleService` strips it on the next role save.
- The chat page never writes to the database; `SqlGuard` is the only path to `QueryRunner`.
- Model id `claude-opus-5` by default, adaptive thinking (omit the `thinking` parameter), `output_config.effort` configurable (default `medium`), structured JSON output via `outputConfig.format`. No `budget_tokens`, no prefill, no `temperature`.
- `ANTHROPIC_API_KEY` is read from env only; it is never logged and never sent to the browser.
- Every list is server-paged with server search (conversations list has search by title).
- No explanatory prose on the page — labels, counts and the server's error text only.
- Tests run on SQLite in memory (`phpunit.xml`); anything MySQL-only (`MAX_EXECUTION_TIME`) is guarded by driver.
- Commit trailer on every commit:
  ```
  Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01JtMNB2VyAfrPtnydmTqiDd
  ```

## File structure

Backend (`backend/`):

- `config/ask-erp.php` — model, effort, limits, connection name, catalogue path.
- `resources/schema-catalogue/<table>.yaml` — one file per business table.
- `app/Modules/Assistant/Catalogue/TableSpec.php` — value object for one file.
- `app/Modules/Assistant/Catalogue/ColumnSpec.php` — value object for one column.
- `app/Modules/Assistant/Catalogue/SchemaCatalogue.php` — loads and memoises every YAML file.
- `app/Modules/Assistant/Catalogue/SensitiveColumns.php` — sensitivity kind → permissions.
- `app/Modules/Assistant/Catalogue/CatalogueGenerator.php` — schema → YAML skeleton, merge-preserving.
- `app/Console/Commands/GenerateSchemaCatalogue.php` — `schema:catalogue:generate`.
- `app/Modules/Assistant/Services/SchemaRetriever.php` — permission filter + ranking.
- `app/Modules/Assistant/Services/SqlGuard.php` + `Exceptions/SqlRefusedException.php`.
- `app/Modules/Assistant/Services/SqlWriter.php` (interface), `SqlDraft.php`, `AnthropicSqlWriter.php`.
- `app/Modules/Assistant/Services/QueryRunner.php`.
- `app/Modules/Assistant/Services/ChartSuggestion.php`.
- `app/Modules/Assistant/Services/AskErpService.php` + `Exceptions/AskErpException.php`.
- `app/Modules/Assistant/Models/{AskErpConversation,AskErpMessage}.php` + migration.
- `app/Modules/Assistant/Http/{Controllers/AskErpController.php, Requests/AskQuestionRequest.php, Requests/ListConversationsRequest.php, Resources/ConversationResource.php, Resources/MessageResource.php}`.
- `app/Providers/AssistantServiceProvider.php` — binds `SqlWriter` to `AnthropicSqlWriter`.
- `routes/api.php` — the `ask-erp` group.
- `app/Modules/Core/Services/PermissionService.php` — `assistant` entry.
- Tests: `tests/Unit/Assistant/{SqlGuardTest,SchemaRetrieverTest,ChartSuggestionTest,CatalogueGeneratorTest}.php`, `tests/Feature/Assistant/{CatalogueCompletenessTest,AskErpApiTest}.php`.

Frontend (`frontend/`):

- `src/features/ask-erp/types.ts`, `api.ts`, `chart.ts` + `chart.test.ts`, `csv.ts` + `csv.test.ts`.
- `src/features/ask-erp/components/{AnswerCard.tsx, ResultChart.tsx, TableChips.tsx}`.
- `src/features/ask-erp/pages/AskErpPage.tsx` + `AskErpPage.render.test.tsx`.
- `src/lib/adoptedModules.ts`, `src/app/AppLayout.tsx`, `src/app/App.tsx` and their two tests.

---

### Task 1: Permission module, config, dependencies

**Files:**
- Modify: `backend/app/Modules/Core/Services/PermissionService.php` (the `MODULES` array)
- Create: `backend/config/ask-erp.php`
- Modify: `backend/.env.example`
- Modify: `backend/composer.json` (via composer)
- Test: `backend/tests/Feature/Assistant/PermissionCatalogueTest.php`

**Interfaces:**
- Produces: `config('ask-erp.*')` keys `api_key, model, effort, max_tokens, timeout, row_limit, connection, catalogue_path, tables_per_question, history_turns`; permission names `assistant.view`, `assistant.manage`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// backend/tests/Feature/Assistant/PermissionCatalogueTest.php
namespace Tests\Feature\Assistant;

use App\Modules\Core\Services\PermissionService;
use Tests\TestCase;

class PermissionCatalogueTest extends TestCase
{
    public function test_ask_erp_is_a_catalogue_module(): void
    {
        $names = app(PermissionService::class)->allPermissionNames();

        $this->assertContains('assistant.view', $names);
        $this->assertContains('assistant.manage', $names);
    }

    public function test_ask_erp_config_has_safe_defaults(): void
    {
        $this->assertSame('claude-opus-5', config('ask-erp.model'));
        $this->assertSame(200, config('ask-erp.row_limit'));
        $this->assertNull(config('ask-erp.connection'));
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `cd backend && php artisan test --filter PermissionCatalogueTest`
Expected: FAIL (`assistant.view` not in the list; config null).

- [ ] **Step 3: Add the module and the config**

In `PermissionService::MODULES`, after `'tally-sync' => 'Tally Sync',` add:

```php
        // ASK ERP — the natural-language query page. Its own catalogue entry
        // because its audience is a choice the owner makes per role: the page
        // can read every table of every module the login may view, so
        // granting it is granting a second, wider window onto those modules.
        // `.view` is the real half (asking is a read); `.manage` is the
        // vestigial twin, as with carton-trace. Administrator receives it
        // through PermissionSeeder; no other role does unless a human grants it.
        'assistant' => 'Ask ERP',
```

Create `backend/config/ask-erp.php`:

```php
<?php

return [
    // Anthropic API key. Read from env only; never logged, never sent to the SPA.
    'api_key' => env('ANTHROPIC_API_KEY'),

    // The model that writes SQL. Adaptive thinking is the model's default.
    'model' => env('ASK_ERP_MODEL', 'claude-opus-5'),
    'effort' => env('ASK_ERP_EFFORT', 'medium'),
    'max_tokens' => 4000,
    'timeout' => 45,

    // Rows returned to the page. SqlGuard appends LIMIT row_limit when the
    // model wrote none, and QueryRunner truncates to it regardless.
    'row_limit' => 200,

    // Database connection the guarded SELECT runs on. Null means the default
    // connection. Live sets ASK_ERP_DB_CONNECTION=ask_erp with a read-only
    // MySQL user (config/database.php).
    'connection' => env('ASK_ERP_DB_CONNECTION'),

    'catalogue_path' => resource_path('schema-catalogue'),

    // How many ranked tables a question is answered from, before joins pull
    // in their neighbours.
    'tables_per_question' => 8,

    // Prior turns of the conversation replayed to the model.
    'history_turns' => 4,
];
```

Append to `backend/.env.example`:

```
# Ask ERP (natural-language queries). Leave the key blank to keep the page
# answering "not configured".
ANTHROPIC_API_KEY=
ASK_ERP_MODEL=claude-opus-5
ASK_ERP_EFFORT=medium
# ASK_ERP_DB_CONNECTION=ask_erp
# ASK_ERP_DB_USERNAME=
# ASK_ERP_DB_PASSWORD=
```

In `backend/config/database.php`, inside `'connections'`, after the `'mysql'` entry add a copy of it named `'ask_erp'` whose `username` is `env('ASK_ERP_DB_USERNAME', env('DB_USERNAME', 'root'))` and `password` is `env('ASK_ERP_DB_PASSWORD', env('DB_PASSWORD', ''))`; every other key identical to `mysql`.

Install the two packages:

```bash
cd backend && composer require anthropic-ai/sdk symfony/yaml
```

- [ ] **Step 4: Run the test and the whole suite's permission tests**

Run: `cd backend && php artisan test --filter 'PermissionCatalogueTest|Permission|Role'`
Expected: PASS. If an existing test pins the exact module list (grep `tests/` for `'tally-sync' => 'Tally Sync'` or a count of 34/36 permissions), update that pin to include `assistant`.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Core/Services/PermissionService.php backend/config/ask-erp.php backend/config/database.php backend/.env.example backend/composer.json backend/composer.lock backend/tests/Feature/Assistant/PermissionCatalogueTest.php
git commit -m "Ask ERP: permission module, config and SDK dependencies"
```

---

### Task 2: Catalogue value objects and loader

**Files:**
- Create: `backend/app/Modules/Assistant/Catalogue/ColumnSpec.php`, `TableSpec.php`, `SchemaCatalogue.php`, `SensitiveColumns.php`
- Test: `backend/tests/Unit/Assistant/SchemaCatalogueTest.php`

**Interfaces:**
- Produces:
  - `ColumnSpec { string $name; string $type; bool $nullable; ?string $meaning; ?string $references; ?string $sensitive }`
  - `TableSpec { string $table; string $module; string $label; string $purpose; ColumnSpec[] $columns; string[] $joins; string[] $keywords; string[] $questions; }` with `columnNames(): string[]`, `sensitiveColumns(): array<string,string>` (name → kind), `render(array $hiddenColumns = []): string` (compact text for the prompt, hidden columns omitted).
  - `SchemaCatalogue::all(): array<string, TableSpec>` keyed by table, `find(string $table): ?TableSpec`, `fromDirectory(string $dir)` static constructor, `fromArray(array $specs)` for tests.
  - `SensitiveColumns::permissionsFor(string $kind): string[]` and `SensitiveColumns::KINDS`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// backend/tests/Unit/Assistant/SchemaCatalogueTest.php
namespace Tests\Unit\Assistant;

use App\Modules\Assistant\Catalogue\SchemaCatalogue;
use App\Modules\Assistant\Catalogue\SensitiveColumns;
use PHPUnit\Framework\TestCase;

class SchemaCatalogueTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/catalogue-'.uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir.'/vendors.yaml', <<<'YAML'
table: vendors
module: procurement
label: Vendors
purpose: One supplier the factory buys from.
columns:
  - name: id
    type: bigint
    meaning: primary key
  - name: name
    type: string
    meaning: supplier name
    sensitive: supplier-identity
  - name: state_code
    type: string
    nullable: true
    meaning: GST state code
joins:
  - purchase_orders.vendor_id = vendors.id
keywords: [supplier, party]
questions:
  - How many vendors are active?
YAML);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*'));
        rmdir($this->dir);
    }

    public function test_loads_a_table_spec_from_yaml(): void
    {
        $catalogue = SchemaCatalogue::fromDirectory($this->dir);
        $vendors = $catalogue->find('vendors');

        $this->assertSame('procurement', $vendors->module);
        $this->assertSame(['id', 'name', 'state_code'], $vendors->columnNames());
        $this->assertSame(['name' => 'supplier-identity'], $vendors->sensitiveColumns());
        $this->assertTrue($vendors->columns[2]->nullable);
        $this->assertNull($catalogue->find('nope'));
    }

    public function test_render_omits_hidden_columns(): void
    {
        $spec = SchemaCatalogue::fromDirectory($this->dir)->find('vendors');
        $text = $spec->render(['name']);

        $this->assertStringContainsString('vendors (procurement): One supplier', $text);
        $this->assertStringContainsString('state_code string nullable — GST state code', $text);
        $this->assertStringNotContainsString('supplier name', $text);
        $this->assertStringContainsString('joins: purchase_orders.vendor_id = vendors.id', $text);
    }

    public function test_sensitive_kinds_map_to_permissions(): void
    {
        $this->assertSame(['carton-trace.view', 'carton-trace.manage', 'finance.view', 'finance.manage'], SensitiveColumns::permissionsFor('rates'));
        $this->assertSame(['procurement.view', 'procurement.manage'], SensitiveColumns::permissionsFor('supplier-identity'));
        $this->assertSame(['payroll.view', 'payroll.manage'], SensitiveColumns::permissionsFor('pay'));
        $this->assertSame(['hrms.manage'], SensitiveColumns::permissionsFor('personal'));
    }
}
```

- [ ] **Step 2: Run to see it fail**

Run: `cd backend && php artisan test --filter SchemaCatalogueTest`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

```php
<?php
// backend/app/Modules/Assistant/Catalogue/ColumnSpec.php
namespace App\Modules\Assistant\Catalogue;

final class ColumnSpec
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $nullable = false,
        public readonly ?string $meaning = null,
        public readonly ?string $references = null,
        public readonly ?string $sensitive = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            name: (string) $row['name'],
            type: (string) ($row['type'] ?? 'string'),
            nullable: (bool) ($row['nullable'] ?? false),
            meaning: isset($row['meaning']) ? (string) $row['meaning'] : null,
            references: isset($row['references']) ? (string) $row['references'] : null,
            sensitive: isset($row['sensitive']) ? (string) $row['sensitive'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'type' => $this->type,
            'nullable' => $this->nullable ?: null,
            'meaning' => $this->meaning,
            'references' => $this->references,
            'sensitive' => $this->sensitive,
        ], static fn ($v) => $v !== null);
    }

    public function renderLine(): string
    {
        $line = $this->name.' '.$this->type.($this->nullable ? ' nullable' : '');
        if ($this->references) {
            $line .= ' → '.$this->references;
        }
        if ($this->meaning) {
            $line .= ' — '.$this->meaning;
        }

        return $line;
    }
}
```

```php
<?php
// backend/app/Modules/Assistant/Catalogue/TableSpec.php
namespace App\Modules\Assistant\Catalogue;

final class TableSpec
{
    /**
     * @param  list<ColumnSpec>  $columns
     * @param  list<string>  $joins
     * @param  list<string>  $keywords
     * @param  list<string>  $questions
     */
    public function __construct(
        public readonly string $table,
        public readonly string $module,
        public readonly string $label,
        public readonly string $purpose,
        public readonly array $columns,
        public readonly array $joins = [],
        public readonly array $keywords = [],
        public readonly array $questions = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            table: (string) $data['table'],
            module: (string) ($data['module'] ?? 'unassigned'),
            label: (string) ($data['label'] ?? $data['table']),
            purpose: (string) ($data['purpose'] ?? ''),
            columns: array_map(ColumnSpec::fromArray(...), array_values($data['columns'] ?? [])),
            joins: array_values($data['joins'] ?? []),
            keywords: array_values($data['keywords'] ?? []),
            questions: array_values($data['questions'] ?? []),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'module' => $this->module,
            'label' => $this->label,
            'purpose' => $this->purpose,
            'columns' => array_map(static fn (ColumnSpec $c) => $c->toArray(), $this->columns),
            'joins' => $this->joins,
            'keywords' => $this->keywords,
            'questions' => $this->questions,
        ];
    }

    /** @return list<string> */
    public function columnNames(): array
    {
        return array_map(static fn (ColumnSpec $c) => $c->name, $this->columns);
    }

    /** @return array<string, string> column name → sensitivity kind */
    public function sensitiveColumns(): array
    {
        $out = [];
        foreach ($this->columns as $column) {
            if ($column->sensitive !== null) {
                $out[$column->name] = $column->sensitive;
            }
        }

        return $out;
    }

    /** Tables named on the other side of this table's joins. @return list<string> */
    public function joinedTables(): array
    {
        $tables = [];
        foreach ($this->joins as $join) {
            preg_match_all('/([a-z_]+)\.[a-z_]+/i', $join, $m);
            foreach ($m[1] as $t) {
                if ($t !== $this->table) {
                    $tables[] = $t;
                }
            }
        }

        return array_values(array_unique($tables));
    }

    /** Compact text for the model. @param list<string> $hiddenColumns */
    public function render(array $hiddenColumns = []): string
    {
        $lines = [sprintf('%s (%s): %s', $this->table, $this->module, $this->purpose)];
        foreach ($this->columns as $column) {
            if (in_array($column->name, $hiddenColumns, true)) {
                continue;
            }
            $lines[] = '  - '.$column->renderLine();
        }
        foreach ($this->joins as $join) {
            $lines[] = '  joins: '.$join;
        }

        return implode("\n", $lines);
    }
}
```

```php
<?php
// backend/app/Modules/Assistant/Catalogue/SensitiveColumns.php
namespace App\Modules\Assistant\Catalogue;

/**
 * What a column marked `sensitive:` in the catalogue needs before this
 * reader may see it. FC-06 is the origin of the first two: rates and
 * supplier identity are the office's, never the floor's.
 */
final class SensitiveColumns
{
    public const array KINDS = [
        'rates' => ['carton-trace.view', 'carton-trace.manage', 'finance.view', 'finance.manage'],
        'supplier-identity' => ['procurement.view', 'procurement.manage'],
        'pay' => ['payroll.view', 'payroll.manage'],
        'personal' => ['hrms.manage'],
    ];

    /** @return list<string> */
    public static function permissionsFor(string $kind): array
    {
        return self::KINDS[$kind] ?? [];
    }
}
```

```php
<?php
// backend/app/Modules/Assistant/Catalogue/SchemaCatalogue.php
namespace App\Modules\Assistant\Catalogue;

use Symfony\Component\Yaml\Yaml;

/**
 * Every table the assistant knows about — one YAML file each under
 * resources/schema-catalogue. Loaded once per process.
 */
final class SchemaCatalogue
{
    /** @param array<string, TableSpec> $specs */
    private function __construct(private readonly array $specs) {}

    public static function fromDirectory(string $dir): self
    {
        $specs = [];
        foreach (glob(rtrim($dir, '/').'/*.yaml') ?: [] as $file) {
            $data = Yaml::parseFile($file);
            if (! is_array($data) || empty($data['table'])) {
                continue;
            }
            $spec = TableSpec::fromArray($data);
            $specs[$spec->table] = $spec;
        }
        ksort($specs);

        return new self($specs);
    }

    /** @param list<TableSpec> $specs */
    public static function fromArray(array $specs): self
    {
        $keyed = [];
        foreach ($specs as $spec) {
            $keyed[$spec->table] = $spec;
        }

        return new self($keyed);
    }

    /** @return array<string, TableSpec> */
    public function all(): array
    {
        return $this->specs;
    }

    public function find(string $table): ?TableSpec
    {
        return $this->specs[$table] ?? null;
    }
}
```

Register a singleton in `backend/app/Providers/AssistantServiceProvider.php` (created here, added to `bootstrap/providers.php`):

```php
<?php

namespace App\Providers;

use App\Modules\Assistant\Catalogue\SchemaCatalogue;
use App\Modules\Assistant\Services\AnthropicSqlWriter;
use App\Modules\Assistant\Services\SqlWriter;
use Illuminate\Support\ServiceProvider;

class AssistantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SchemaCatalogue::class, fn () => SchemaCatalogue::fromDirectory(config('ask-erp.catalogue_path')));
        $this->app->bind(SqlWriter::class, AnthropicSqlWriter::class);
    }
}
```

(The `SqlWriter` binding refers to Task 6's classes; keep the `use` lines and add the classes in Task 6 — until then leave the `bind` line commented out with `// Task 6` and uncomment it there.)

- [ ] **Step 4: Run the test**

Run: `cd backend && php artisan test --filter SchemaCatalogueTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Assistant/Catalogue backend/app/Providers/AssistantServiceProvider.php backend/bootstrap/providers.php backend/tests/Unit/Assistant/SchemaCatalogueTest.php
git commit -m "Ask ERP: schema catalogue value objects and YAML loader"
```

---

### Task 3: Catalogue generator command

**Files:**
- Create: `backend/app/Modules/Assistant/Catalogue/CatalogueGenerator.php`, `backend/app/Console/Commands/GenerateSchemaCatalogue.php`
- Test: `backend/tests/Feature/Assistant/CatalogueGeneratorTest.php`

**Interfaces:**
- Produces: `CatalogueGenerator::generate(string $dir): array{created: list<string>, updated: list<string>, unchanged: list<string>}`; `CatalogueGenerator::FRAMEWORK_TABLES`; the `MODULE_BY_PREFIX` first-guess map.
- Command: `php artisan schema:catalogue:generate [--path=]`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// backend/tests/Feature/Assistant/CatalogueGeneratorTest.php
namespace Tests\Feature\Assistant;

use App\Modules\Assistant\Catalogue\CatalogueGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class CatalogueGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/catalogue-gen-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*'));
        rmdir($this->dir);
        parent::tearDown();
    }

    public function test_writes_one_file_per_business_table_and_skips_framework_tables(): void
    {
        $result = app(CatalogueGenerator::class)->generate($this->dir);

        $this->assertFileExists($this->dir.'/employees.yaml');
        $this->assertFileExists($this->dir.'/purchase_orders.yaml');
        $this->assertFileDoesNotExist($this->dir.'/cache.yaml');
        $this->assertFileDoesNotExist($this->dir.'/migrations.yaml');
        $this->assertContains('employees', $result['created']);

        $employees = Yaml::parseFile($this->dir.'/employees.yaml');
        $names = array_column($employees['columns'], 'name');
        $this->assertContains('employee_code', $names);
        $this->assertSame('hrms', $employees['module']);
        $manager = collect($employees['columns'])->firstWhere('name', 'manager_id');
        $this->assertSame('employees.id', $manager['references']);
    }

    public function test_regeneration_keeps_annotations_and_adds_new_columns(): void
    {
        $generator = app(CatalogueGenerator::class);
        $generator->generate($this->dir);

        $data = Yaml::parseFile($this->dir.'/employees.yaml');
        $data['purpose'] = 'One person on the payroll.';
        $data['columns'] = array_values(array_filter($data['columns'], fn ($c) => $c['name'] !== 'phone'));
        $data['columns'][1]['meaning'] = 'the Pooja app ID';
        file_put_contents($this->dir.'/employees.yaml', Yaml::dump($data, 4, 2));

        $result = $generator->generate($this->dir);

        $again = Yaml::parseFile($this->dir.'/employees.yaml');
        $this->assertSame('One person on the payroll.', $again['purpose']);
        $this->assertSame('the Pooja app ID', $again['columns'][1]['meaning']);
        $this->assertContains('phone', array_column($again['columns'], 'name'));
        $this->assertContains('employees', $result['updated']);
    }
}
```

- [ ] **Step 2: Run to see it fail**

Run: `cd backend && php artisan test --filter CatalogueGeneratorTest`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

```php
<?php
// backend/app/Modules/Assistant/Catalogue/CatalogueGenerator.php
namespace App\Modules\Assistant\Catalogue;

use Illuminate\Support\Facades\Schema;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the live schema and writes one YAML per business table. A file that
 * exists is MERGED: its purpose, module, label, keywords, questions, joins
 * and every column's meaning/sensitive survive; columns the database has
 * gained are appended, columns it has lost are dropped.
 */
final class CatalogueGenerator
{
    public const array FRAMEWORK_TABLES = [
        'migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'sessions', 'password_reset_tokens', 'personal_access_tokens', 'activity_log',
        'permissions', 'roles', 'model_has_permissions', 'model_has_roles', 'role_has_permissions',
        'sqlite_sequence',
    ];

    /** First guess at the owning module from the table name; annotated by hand afterwards. */
    public const array MODULE_BY_PREFIX = [
        'attendance' => 'hrms', 'employee' => 'hrms', 'leave_' => 'hrms',
        'payroll' => 'payroll', 'payslip' => 'payroll', 'salary_' => 'payroll',
        'purchase_' => 'procurement', 'vendor' => 'procurement', 'goods_receipt' => 'procurement', 'grn_' => 'procurement', 'supplier_bill' => 'procurement',
        'sales_order' => 'sales', 'customer' => 'sales', 'deliver' => 'sales', 'invoice' => 'sales', 'quotation' => 'sales',
        'lead' => 'crm', 'opportunit' => 'crm',
        'gl_' => 'finance', 'journal_' => 'finance', 'ledger' => 'finance',
        'gst_' => 'compliance',
        'shift' => 'production', 'batch' => 'production', 'work_order' => 'production', 'work_center' => 'machine-master',
        'production_' => 'production', 'mold' => 'production', 'routing' => 'production', 'bom' => 'production',
        'downtime' => 'production', 'machine_' => 'production', 'power_' => 'production', 'masterbatch' => 'production',
        'rework' => 'production', 'subcontract' => 'production', 'scrap' => 'production', 'day_bin' => 'production',
        'resin_' => 'production', 'finished_carton' => 'production', 'packing_' => 'production', 'serial_' => 'production',
        'item' => 'inventory', 'stock_' => 'inventory', 'warehouse' => 'inventory', 'material_' => 'inventory', 'store_issue' => 'inventory',
        'incoming_inspection' => 'quality', 'non_conformance' => 'quality', 'capa' => 'quality', 'spc_' => 'quality',
        'calibration' => 'quality', 'measuring_' => 'quality',
        'asset' => 'maintenance', 'maintenance_' => 'maintenance',
        'tally_' => 'tally-sync',
        'user' => 'users', 'app_setting' => 'users', 'factory_setting' => 'users', 'export_run' => 'users',
    ];

    /** @return array{created: list<string>, updated: list<string>, unchanged: list<string>} */
    public function generate(string $dir): array
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $result = ['created' => [], 'updated' => [], 'unchanged' => []];

        foreach (Schema::getTableListing() as $table) {
            $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
            if (in_array($table, self::FRAMEWORK_TABLES, true)) {
                continue;
            }

            $path = rtrim($dir, '/')."/{$table}.yaml";
            $existing = is_file($path) ? (Yaml::parseFile($path) ?: []) : null;
            $spec = $this->merge($table, $existing);
            $yaml = Yaml::dump($spec->toArray(), 4, 2);

            if ($existing === null) {
                $result['created'][] = $table;
            } elseif (trim($yaml) === trim((string) file_get_contents($path))) {
                $result['unchanged'][] = $table;
                continue;
            } else {
                $result['updated'][] = $table;
            }

            file_put_contents($path, $yaml);
        }

        return $result;
    }

    /** @param array<string, mixed>|null $existing */
    private function merge(string $table, ?array $existing): TableSpec
    {
        $references = [];
        foreach (Schema::getForeignKeys($table) as $fk) {
            if (count($fk['columns']) === 1 && count($fk['foreign_columns']) === 1) {
                $references[$fk['columns'][0]] = $fk['foreign_table'].'.'.$fk['foreign_columns'][0];
            }
        }

        $known = [];
        foreach ($existing['columns'] ?? [] as $column) {
            $known[$column['name']] = $column;
        }

        $columns = [];
        foreach (Schema::getColumns($table) as $column) {
            $prior = $known[$column['name']] ?? [];
            $columns[] = new ColumnSpec(
                name: $column['name'],
                type: $this->simpleType($column['type_name']),
                nullable: (bool) $column['nullable'],
                meaning: $prior['meaning'] ?? null,
                references: $references[$column['name']] ?? ($prior['references'] ?? null),
                sensitive: $prior['sensitive'] ?? null,
            );
        }

        return new TableSpec(
            table: $table,
            module: $existing['module'] ?? $this->guessModule($table),
            label: $existing['label'] ?? ucwords(str_replace('_', ' ', $table)),
            purpose: $existing['purpose'] ?? '',
            columns: $columns,
            joins: array_values($existing['joins'] ?? $this->joinsFrom($table, $references)),
            keywords: array_values($existing['keywords'] ?? []),
            questions: array_values($existing['questions'] ?? []),
        );
    }

    private function simpleType(string $type): string
    {
        $type = strtolower($type);

        return match (true) {
            str_contains($type, 'int') => 'integer',
            str_contains($type, 'decimal'), str_contains($type, 'numeric') => 'decimal',
            str_contains($type, 'char'), str_contains($type, 'text') => 'string',
            str_contains($type, 'datetime'), str_contains($type, 'timestamp') => 'datetime',
            $type === 'date' => 'date',
            $type === 'time' => 'time',
            str_contains($type, 'bool'), $type === 'tinyint(1)' => 'boolean',
            str_contains($type, 'json') => 'json',
            default => $type,
        };
    }

    /** @param array<string, string> $references @return list<string> */
    private function joinsFrom(string $table, array $references): array
    {
        $joins = [];
        foreach ($references as $column => $target) {
            $joins[] = "{$table}.{$column} = {$target}";
        }

        return $joins;
    }

    private function guessModule(string $table): string
    {
        foreach (self::MODULE_BY_PREFIX as $prefix => $module) {
            if (str_starts_with($table, $prefix) || str_contains($table, '_'.$prefix)) {
                return $module;
            }
        }

        return 'unassigned';
    }
}
```

```php
<?php
// backend/app/Console/Commands/GenerateSchemaCatalogue.php
namespace App\Console\Commands;

use App\Modules\Assistant\Catalogue\CatalogueGenerator;
use Illuminate\Console\Command;

class GenerateSchemaCatalogue extends Command
{
    protected $signature = 'schema:catalogue:generate {--path= : Directory to write into (default config ask-erp.catalogue_path)}';

    protected $description = 'Write or refresh one YAML per business table for Ask ERP, keeping hand-written annotations';

    public function handle(CatalogueGenerator $generator): int
    {
        $dir = $this->option('path') ?: config('ask-erp.catalogue_path');
        $result = $generator->generate($dir);

        $this->info(sprintf('%d created, %d updated, %d unchanged in %s', count($result['created']), count($result['updated']), count($result['unchanged']), $dir));

        return self::SUCCESS;
    }
}
```

Note: SQLite in tests reports `type_name` as e.g. `varchar`, `integer`; MySQL live reports `bigint`, `decimal`. `simpleType` covers both. `Schema::getForeignKeys` on SQLite returns the declared constraints from `foreignId()->constrained()`; the employees migration declares `manager_id` that way, which is what the first test pins.

- [ ] **Step 4: Run the test**

Run: `cd backend && php artisan test --filter CatalogueGeneratorTest`
Expected: PASS (2 tests). If `type_name` is not present in your Laravel version's `getColumns` rows, use `$column['type']` instead — check with `php artisan tinker --execute='print_r(Schema::getColumns("employees")[0]);'`.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Assistant/Catalogue/CatalogueGenerator.php backend/app/Console/Commands/GenerateSchemaCatalogue.php backend/tests/Feature/Assistant/CatalogueGeneratorTest.php
git commit -m "Ask ERP: schema catalogue generator that keeps annotations"
```

---

### Task 4: Generate and annotate the catalogue

**Files:**
- Create: `backend/resources/schema-catalogue/*.yaml` (about 120 files)
- Test: `backend/tests/Feature/Assistant/CatalogueCompletenessTest.php`

**Interfaces:**
- Produces: the committed catalogue; every file has a non-empty `purpose`, a `module` from `PermissionService::MODULES`, and every column a `meaning`.

- [ ] **Step 1: Write the completeness test**

```php
<?php
// backend/tests/Feature/Assistant/CatalogueCompletenessTest.php
namespace Tests\Feature\Assistant;

use App\Modules\Assistant\Catalogue\CatalogueGenerator;
use App\Modules\Assistant\Catalogue\SchemaCatalogue;
use App\Modules\Assistant\Catalogue\SensitiveColumns;
use App\Modules\Core\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CatalogueCompletenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_business_table_has_an_annotated_file(): void
    {
        $catalogue = SchemaCatalogue::fromDirectory(resource_path('schema-catalogue'));
        $modules = array_keys(PermissionService::MODULES);

        foreach (Schema::getTableListing() as $table) {
            if (in_array($table, CatalogueGenerator::FRAMEWORK_TABLES, true)) {
                continue;
            }
            $spec = $catalogue->find($table);
            $this->assertNotNull($spec, "No catalogue file for {$table}");
            $this->assertNotSame('', trim($spec->purpose), "{$table} has no purpose");
            $this->assertContains($spec->module, $modules, "{$table} names module {$spec->module}, not in the permission catalogue");

            $dbColumns = array_column(Schema::getColumns($table), 'name');
            $this->assertEqualsCanonicalizing($dbColumns, $spec->columnNames(), "{$table} columns drifted from the database; run schema:catalogue:generate");

            foreach ($spec->columns as $column) {
                $this->assertNotNull($column->meaning, "{$table}.{$column->name} has no meaning");
                if ($column->sensitive !== null) {
                    $this->assertArrayHasKey($column->sensitive, SensitiveColumns::KINDS, "{$table}.{$column->name} has unknown sensitivity {$column->sensitive}");
                }
            }
        }
    }
}
```

- [ ] **Step 2: Generate the skeleton**

Run: `cd backend && php artisan schema:catalogue:generate`
Expected: about 120 created. Check `git status` shows `resources/schema-catalogue/`. Run the completeness test: it FAILS on empty purposes.

- [ ] **Step 3: Annotate every file, by module, in parallel**

Dispatch one subagent per group; each reads the migrations, models and services for its tables and fills `purpose`, `module` (correct the guess — e.g. `work_centers` is `machine-master`, `finished_cartons` is `production`, `resin_pool_balances` is `production`, `material_*` are `inventory`, `tally_*` are `tally-sync`, `users`, `app_settings`, `factory_settings`, `export_runs` are `users`), every column's `meaning` (a phrase, and the enum values for status-like columns copied from the Enum class or the migration comment), `sensitive` where it applies (`rates` on every rate/cost/amount/price/value column in procurement, tally purchase rates, batch costing, material cost versions, supplier bills, invoices; `supplier-identity` on vendor name/contact columns and vendor ledger names; `pay` on every salary/payslip/structure amount; `personal` on phone/email/date_of_birth/address), `keywords` (3–8 words a factory person would use, including Tally names where they differ), and 1–3 `questions`. Groups:

1. hrms + payroll + users/settings/exports
2. procurement + tally-sync
3. inventory + quality + compliance + maintenance
4. production (shifts, batches, entries, consumptions, cartons, standards, configurations, molds, downtime, day bin, resin) — the largest, split in two subagents if over 30 files
5. sales + crm + finance

Each subagent's prompt: the group's table list, the annotation rules above, the YAML shape from Task 2's test, the instruction to run `php artisan test --filter CatalogueCompletenessTest` for their tables' assertions, and to touch nothing outside `resources/schema-catalogue/`.

- [ ] **Step 4: Run the completeness test**

Run: `cd backend && php artisan test --filter CatalogueCompletenessTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/resources/schema-catalogue backend/tests/Feature/Assistant/CatalogueCompletenessTest.php
git commit -m "Ask ERP: annotated schema catalogue, one file per table"
```

---

### Task 5: SchemaRetriever

**Files:**
- Create: `backend/app/Modules/Assistant/Services/SchemaRetriever.php`
- Test: `backend/tests/Unit/Assistant/SchemaRetrieverTest.php`

**Interfaces:**
- Consumes: `SchemaCatalogue`, `TableSpec`, `SensitiveColumns`.
- Produces:
  - `SchemaRetriever::allowedTables(Authenticatable $user): array<string, TableSpec>`
  - `SchemaRetriever::hiddenColumns(Authenticatable $user, TableSpec $spec): list<string>`
  - `SchemaRetriever::forQuestion(Authenticatable $user, string $question, array $previousTables = []): list<TableSpec>` (ranked, joins pulled in, capped by `tables_per_question` + neighbours)
  - `SchemaRetriever::tokens(string $text): list<string>` (public for tests)

- [ ] **Step 1: Write the failing test**

```php
<?php
// backend/tests/Unit/Assistant/SchemaRetrieverTest.php
namespace Tests\Unit\Assistant;

use App\Modules\Assistant\Catalogue\ColumnSpec;
use App\Modules\Assistant\Catalogue\SchemaCatalogue;
use App\Modules\Assistant\Catalogue\TableSpec;
use App\Modules\Assistant\Services\SchemaRetriever;
use Illuminate\Contracts\Auth\Authenticatable;
use PHPUnit\Framework\TestCase;

class SchemaRetrieverTest extends TestCase
{
    private SchemaCatalogue $catalogue;

    protected function setUp(): void
    {
        $this->catalogue = SchemaCatalogue::fromArray([
            new TableSpec('vendors', 'procurement', 'Vendors', 'A supplier.', [
                new ColumnSpec('id', 'integer', meaning: 'pk'),
                new ColumnSpec('name', 'string', meaning: 'supplier name', sensitive: 'supplier-identity'),
            ], keywords: ['supplier'], questions: ['How many vendors?']),
            new TableSpec('purchase_orders', 'procurement', 'Purchase Orders', 'A PO on a vendor.', [
                new ColumnSpec('id', 'integer', meaning: 'pk'),
                new ColumnSpec('vendor_id', 'integer', meaning: 'the supplier', references: 'vendors.id'),
                new ColumnSpec('total_amount', 'decimal', meaning: 'PO value', sensitive: 'rates'),
            ], joins: ['purchase_orders.vendor_id = vendors.id'], keywords: ['po', 'purchase order']),
            new TableSpec('employees', 'hrms', 'Employees', 'A person.', [
                new ColumnSpec('id', 'integer', meaning: 'pk'),
                new ColumnSpec('phone', 'string', meaning: 'mobile', sensitive: 'personal'),
            ], keywords: ['staff', 'worker']),
        ]);
    }

    private function user(array $permissions): Authenticatable
    {
        return new class($permissions) implements Authenticatable
        {
            public function __construct(private array $permissions) {}

            public function hasAnyPermission(array $names): bool
            {
                return count(array_intersect($names, $this->permissions)) > 0;
            }

            public function getAuthIdentifierName(): string { return 'id'; }
            public function getAuthIdentifier(): mixed { return 1; }
            public function getAuthPasswordName(): string { return 'password'; }
            public function getAuthPassword(): string { return ''; }
            public function getRememberToken(): string { return ''; }
            public function setRememberToken($value): void {}
            public function getRememberTokenName(): string { return ''; }
        };
    }

    private function retriever(): SchemaRetriever
    {
        return new SchemaRetriever($this->catalogue, tablesPerQuestion: 8);
    }

    public function test_only_tables_of_viewable_modules_are_allowed(): void
    {
        $allowed = $this->retriever()->allowedTables($this->user(['hrms.view']));

        $this->assertSame(['employees'], array_keys($allowed));
    }

    public function test_manage_counts_as_view(): void
    {
        $allowed = $this->retriever()->allowedTables($this->user(['procurement.manage']));

        $this->assertSame(['purchase_orders', 'vendors'], array_keys($allowed));
    }

    public function test_hidden_columns_follow_sensitivity_permissions(): void
    {
        $retriever = $this->retriever();
        $po = $this->catalogue->find('purchase_orders');

        $this->assertSame(['total_amount'], $retriever->hiddenColumns($this->user(['procurement.view']), $po));
        $this->assertSame([], $retriever->hiddenColumns($this->user(['procurement.view', 'finance.view']), $po));
        $this->assertSame(['phone'], $retriever->hiddenColumns($this->user(['hrms.view']), $this->catalogue->find('employees')));
        $this->assertSame([], $retriever->hiddenColumns($this->user(['hrms.manage']), $this->catalogue->find('employees')));
    }

    public function test_ranks_by_keyword_and_pulls_in_joined_tables(): void
    {
        $picked = $this->retriever()->forQuestion($this->user(['procurement.view', 'hrms.view']), 'how many purchase orders per supplier this month');

        $this->assertSame('purchase_orders', $picked[0]->table);
        $this->assertContains('vendors', array_map(fn ($t) => $t->table, $picked));
        $this->assertNotContains('employees', array_map(fn ($t) => $t->table, $picked));
    }

    public function test_previous_turn_tables_stay_in_scope(): void
    {
        $picked = $this->retriever()->forQuestion($this->user(['procurement.view', 'hrms.view']), 'and only the active ones', ['employees']);

        $this->assertContains('employees', array_map(fn ($t) => $t->table, $picked));
    }

    public function test_never_returns_a_table_the_user_may_not_see(): void
    {
        $picked = $this->retriever()->forQuestion($this->user(['hrms.view']), 'purchase orders per supplier', ['purchase_orders']);

        $this->assertSame([], array_filter($picked, fn ($t) => $t->module !== 'hrms'));
    }

    public function test_tokens_are_lowercased_stemmed_words(): void
    {
        $this->assertSame(['purchas', 'order', 'supplier'], SchemaRetriever::tokens('Purchase orders, per SUPPLIER!'));
    }
}
```

- [ ] **Step 2: Run to see it fail**

Run: `cd backend && php artisan test --filter SchemaRetrieverTest`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

```php
<?php
// backend/app/Modules/Assistant/Services/SchemaRetriever.php
namespace App\Modules\Assistant\Services;

use App\Modules\Assistant\Catalogue\SchemaCatalogue;
use App\Modules\Assistant\Catalogue\SensitiveColumns;
use App\Modules\Assistant\Catalogue\TableSpec;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Which tables a question is answered from. Permission first — a table
 * outside the user's modules does not exist as far as this class is
 * concerned — then a plain lexical ranking. No embeddings: nothing to host.
 */
class SchemaRetriever
{
    public function __construct(
        private readonly SchemaCatalogue $catalogue,
        private readonly int $tablesPerQuestion = 8,
    ) {}

    /** @return array<string, TableSpec> */
    public function allowedTables(Authenticatable $user): array
    {
        $allowed = [];
        foreach ($this->catalogue->all() as $table => $spec) {
            if ($this->may($user, ["{$spec->module}.view", "{$spec->module}.manage"])) {
                $allowed[$table] = $spec;
            }
        }

        return $allowed;
    }

    /** @return list<string> */
    public function hiddenColumns(Authenticatable $user, TableSpec $spec): array
    {
        $hidden = [];
        foreach ($spec->sensitiveColumns() as $column => $kind) {
            if (! $this->may($user, SensitiveColumns::permissionsFor($kind))) {
                $hidden[] = $column;
            }
        }

        return $hidden;
    }

    /**
     * @param  list<string>  $previousTables
     * @return list<TableSpec>
     */
    public function forQuestion(Authenticatable $user, string $question, array $previousTables = []): array
    {
        $allowed = $this->allowedTables($user);
        $tokens = self::tokens($question);

        $scores = [];
        foreach ($allowed as $table => $spec) {
            $score = 0;
            $haystack = self::tokens(implode(' ', [
                $spec->label, $spec->table, implode(' ', $spec->keywords), implode(' ', $spec->questions),
            ]));
            $columnTokens = self::tokens(implode(' ', $spec->columnNames()));
            foreach ($tokens as $token) {
                $score += 3 * count(array_keys($haystack, $token, true));
                $score += in_array($token, $columnTokens, true) ? 1 : 0;
            }
            if (in_array($table, $previousTables, true)) {
                $score += 5;
            }
            if ($score > 0) {
                $scores[$table] = $score;
            }
        }
        arsort($scores);

        $picked = array_slice(array_keys($scores), 0, $this->tablesPerQuestion);

        foreach ($picked as $table) {
            foreach ($allowed[$table]->joinedTables() as $neighbour) {
                if (isset($allowed[$neighbour]) && ! in_array($neighbour, $picked, true)) {
                    $picked[] = $neighbour;
                }
            }
        }

        return array_map(fn (string $table) => $allowed[$table], $picked);
    }

    /** Lowercase words, stop-words dropped, crude English stemming. @return list<string> */
    public static function tokens(string $text): array
    {
        $stop = ['the', 'a', 'an', 'of', 'in', 'on', 'for', 'per', 'and', 'or', 'to', 'by', 'with', 'how', 'many', 'much', 'what', 'which', 'this', 'that', 'is', 'are', 'was', 'were', 'me', 'show', 'list', 'give', 'all', 'each', 'from', 'at', 'as', 'it', 'its', 'do', 'does', 'did', 'only', 'ones', 'one', 'month', 'week', 'today', 'yesterday', 'year', 'last', 'now'];
        preg_match_all('/[a-z0-9]+/', strtolower($text), $m);

        $out = [];
        foreach ($m[0] as $word) {
            if (strlen($word) < 2 || in_array($word, $stop, true)) {
                continue;
            }
            $out[] = self::stem($word);
        }

        return $out;
    }

    private static function stem(string $word): string
    {
        foreach (['ations' => 'at', 'ation' => 'at', 'ings' => '', 'ing' => '', 'ies' => 'i', 'ers' => 'er', 'es' => '', 's' => '', 'ed' => ''] as $suffix => $replacement) {
            if (strlen($word) > strlen($suffix) + 2 && str_ends_with($word, $suffix)) {
                return substr($word, 0, -strlen($suffix)).$replacement;
            }
        }

        return $word;
    }

    /** @param list<string> $permissions */
    private function may(Authenticatable $user, array $permissions): bool
    {
        return method_exists($user, 'hasAnyPermission') && $user->hasAnyPermission($permissions);
    }
}
```

Bind the constructor's int in `AssistantServiceProvider::register()`:

```php
        $this->app->bind(SchemaRetriever::class, fn ($app) => new SchemaRetriever(
            $app->make(SchemaCatalogue::class),
            (int) config('ask-erp.tables_per_question'),
        ));
```

- [ ] **Step 4: Run the test; adjust `stem` until the tokens test passes exactly** (`purchase` → `purchas`, `orders` → `order`, `supplier` stays).

Run: `cd backend && php artisan test --filter SchemaRetrieverTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Assistant/Services/SchemaRetriever.php backend/app/Providers/AssistantServiceProvider.php backend/tests/Unit/Assistant/SchemaRetrieverTest.php
git commit -m "Ask ERP: permission-first schema retrieval"
```

---

### Task 6: SqlGuard

**Files:**
- Create: `backend/app/Modules/Assistant/Services/SqlGuard.php`, `backend/app/Modules/Assistant/Exceptions/SqlRefusedException.php`
- Test: `backend/tests/Unit/Assistant/SqlGuardTest.php`

**Interfaces:**
- Produces: `SqlGuard::check(string $sql, array $allowedTables, array $hiddenColumnsByTable, int $rowLimit): string` — returns the SQL to run (LIMIT appended when absent) or throws `SqlRefusedException` whose `getMessage()` is the reason shown to the user. `SqlGuard::tablesIn(string $sql): list<string>` public for the service to record `tables_used`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// backend/tests/Unit/Assistant/SqlGuardTest.php
namespace Tests\Unit\Assistant;

use App\Modules\Assistant\Exceptions\SqlRefusedException;
use App\Modules\Assistant\Services\SqlGuard;
use PHPUnit\Framework\TestCase;

class SqlGuardTest extends TestCase
{
    private SqlGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new SqlGuard;
    }

    public function test_plain_select_on_allowed_table_passes_and_gets_a_limit(): void
    {
        $sql = $this->guard->check('SELECT status, COUNT(*) AS n FROM purchase_orders GROUP BY status', ['purchase_orders'], [], 200);

        $this->assertSame('SELECT status, COUNT(*) AS n FROM purchase_orders GROUP BY status LIMIT 200', $sql);
    }

    public function test_existing_limit_is_kept_but_capped(): void
    {
        $this->assertStringEndsWith('LIMIT 50', $this->guard->check('SELECT id FROM vendors LIMIT 50', ['vendors'], [], 200));
        $this->assertStringEndsWith('LIMIT 200', $this->guard->check('SELECT id FROM vendors LIMIT 5000', ['vendors'], [], 200));
    }

    public function test_trailing_semicolon_is_tolerated(): void
    {
        $this->assertSame('SELECT id FROM vendors LIMIT 200', $this->guard->check("SELECT id FROM vendors;\n", ['vendors'], [], 200));
    }

    /** @dataProvider refused */
    public function test_refuses(string $sql, string $reason): void
    {
        $this->expectException(SqlRefusedException::class);
        $this->expectExceptionMessage($reason);

        $this->guard->check($sql, ['vendors', 'purchase_orders'], ['purchase_orders' => ['total_amount']], 200);
    }

    public static function refused(): array
    {
        return [
            'update' => ['UPDATE vendors SET name = 1', 'Only a SELECT'],
            'delete' => ['DELETE FROM vendors', 'Only a SELECT'],
            'two statements' => ['SELECT 1; DROP TABLE vendors', 'one statement'],
            'comment' => ['SELECT id FROM vendors -- x', 'comments'],
            'into outfile' => ["SELECT id INTO OUTFILE '/x' FROM vendors", 'INTO'],
            'for update' => ['SELECT id FROM vendors FOR UPDATE', 'FOR UPDATE'],
            'sleep' => ['SELECT SLEEP(5) FROM vendors', 'SLEEP'],
            'information schema' => ['SELECT * FROM information_schema.tables', 'not available'],
            'other table' => ['SELECT id FROM employees', 'employees'],
            'join to other table' => ['SELECT v.id FROM vendors v JOIN employees e ON e.id = v.id', 'employees'],
            'subquery other table' => ['SELECT id FROM vendors WHERE id IN (SELECT id FROM employees)', 'employees'],
            'cte over other table' => ['WITH e AS (SELECT id FROM employees) SELECT id FROM e', 'employees'],
            'star on table with hidden column' => ['SELECT * FROM purchase_orders', 'total_amount'],
            'qualified hidden column' => ['SELECT po.total_amount FROM purchase_orders po', 'total_amount'],
            'bare hidden column' => ['SELECT total_amount FROM purchase_orders', 'total_amount'],
            'hidden column in aggregate' => ['SELECT SUM(total_amount) FROM purchase_orders', 'total_amount'],
        ];
    }

    public function test_star_on_a_table_without_hidden_columns_is_fine(): void
    {
        $sql = $this->guard->check('SELECT * FROM vendors', ['vendors', 'purchase_orders'], ['purchase_orders' => ['total_amount']], 200);

        $this->assertSame('SELECT * FROM vendors LIMIT 200', $sql);
    }

    public function test_cte_names_are_not_treated_as_tables(): void
    {
        $sql = 'WITH recent AS (SELECT vendor_id FROM purchase_orders) SELECT v.id FROM vendors v JOIN recent r ON r.vendor_id = v.id';

        $this->assertStringEndsWith('LIMIT 200', $this->guard->check($sql, ['vendors', 'purchase_orders'], [], 200));
        $this->assertSame(['purchase_orders', 'vendors'], $this->guard->tablesIn($sql));
    }

    public function test_backticked_and_qualified_names_are_read(): void
    {
        $this->assertSame(['vendors'], $this->guard->tablesIn('SELECT id FROM `vendors`'));
        $this->assertStringContainsString('LIMIT 200', $this->guard->check('SELECT id FROM `vendors`', ['vendors'], [], 200));
    }
}
```

- [ ] **Step 2: Run to see it fail**

Run: `cd backend && php artisan test --filter SqlGuardTest`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

```php
<?php
// backend/app/Modules/Assistant/Exceptions/SqlRefusedException.php
namespace App\Modules\Assistant\Exceptions;

use RuntimeException;

class SqlRefusedException extends RuntimeException {}
```

```php
<?php
// backend/app/Modules/Assistant/Services/SqlGuard.php
namespace App\Modules\Assistant\Services;

use App\Modules\Assistant\Exceptions\SqlRefusedException;

/**
 * The only door to QueryRunner. One SELECT, on tables this reader may see,
 * naming no column they may not, with a row cap. Everything it cannot prove
 * safe it refuses — a refusal costs the user a rephrase, a miss costs a leak.
 */
class SqlGuard
{
    private const array FORBIDDEN = [
        '/\bINTO\b/i' => 'INTO is not allowed',
        '/\bFOR\s+UPDATE\b/i' => 'FOR UPDATE is not allowed',
        '/\bLOCK\b/i' => 'LOCK is not allowed',
        '/\bLOAD_FILE\b/i' => 'LOAD_FILE is not allowed',
        '/\bSLEEP\s*\(/i' => 'SLEEP is not allowed',
        '/\bBENCHMARK\s*\(/i' => 'BENCHMARK is not allowed',
        '/\binformation_schema\b/i' => 'information_schema is not available',
        '/\bperformance_schema\b/i' => 'performance_schema is not available',
        '/\bmysql\s*\./i' => 'the mysql schema is not available',
        '/\bsqlite_master\b/i' => 'sqlite_master is not available',
    ];

    /**
     * @param  list<string>  $allowedTables
     * @param  array<string, list<string>>  $hiddenColumnsByTable
     */
    public function check(string $sql, array $allowedTables, array $hiddenColumnsByTable, int $rowLimit): string
    {
        $sql = trim($sql);
        $sql = rtrim($sql, "; \n\r\t");

        if (str_contains($sql, ';')) {
            throw new SqlRefusedException('Only one statement may run.');
        }
        if (preg_match('/--|#|\/\*/', $sql)) {
            throw new SqlRefusedException('SQL comments are not allowed.');
        }
        if (! preg_match('/^\s*(SELECT|WITH)\b/i', $sql)) {
            throw new SqlRefusedException('Only a SELECT may run.');
        }
        foreach (self::FORBIDDEN as $pattern => $reason) {
            if (preg_match($pattern, $sql)) {
                throw new SqlRefusedException($reason.'.');
            }
        }

        $tables = $this->tablesIn($sql);
        foreach ($tables as $table) {
            if (! in_array($table, $allowedTables, true)) {
                throw new SqlRefusedException("The table {$table} is not available to you.");
            }
        }

        $this->refuseHiddenColumns($sql, $tables, $hiddenColumnsByTable);

        return $this->applyLimit($sql, $rowLimit);
    }

    /**
     * Every real table named after FROM or JOIN, CTE names excluded,
     * sorted. Subqueries and CTE bodies are plain text to this scan, which
     * is the point: a table hidden inside one is still found.
     *
     * @return list<string>
     */
    public function tablesIn(string $sql): array
    {
        $ctes = [];
        if (preg_match_all('/(?:\bWITH\b|,)\s*`?([a-z_][a-z0-9_]*)`?\s+AS\s*\(/i', $sql, $m)) {
            $ctes = array_map('strtolower', $m[1]);
        }

        preg_match_all('/\b(?:FROM|JOIN)\s+`?(?:[a-z_][a-z0-9_]*`?\.`?)?([a-z_][a-z0-9_]*)`?/i', $sql, $m);
        $tables = [];
        foreach ($m[1] as $name) {
            $name = strtolower($name);
            if ($name === 'select' || in_array($name, $ctes, true)) {
                continue;
            }
            $tables[] = $name;
        }
        $tables = array_values(array_unique($tables));
        sort($tables);

        return $tables;
    }

    /**
     * @param  list<string>  $tables
     * @param  array<string, list<string>>  $hiddenColumnsByTable
     */
    private function refuseHiddenColumns(string $sql, array $tables, array $hiddenColumnsByTable): void
    {
        $hidden = [];
        foreach ($tables as $table) {
            foreach ($hiddenColumnsByTable[$table] ?? [] as $column) {
                $hidden[$column] = $table;
            }
        }
        if ($hidden === []) {
            return;
        }

        if (preg_match('/(^|[\s,(])(`?[a-z_][a-z0-9_]*`?\.)?\*/i', $sql)) {
            $names = implode(', ', array_keys($hidden));
            throw new SqlRefusedException("SELECT * is not allowed here: {$names} is not available to you. Name the columns.");
        }

        foreach ($hidden as $column => $table) {
            if (preg_match('/\b'.preg_quote($column, '/').'\b/i', $sql)) {
                throw new SqlRefusedException("The column {$table}.{$column} is not available to you.");
            }
        }
    }

    private function applyLimit(string $sql, int $rowLimit): string
    {
        if (preg_match('/\bLIMIT\s+(\d+)(\s*,\s*\d+|\s+OFFSET\s+\d+)?\s*$/i', $sql, $m)) {
            if ((int) $m[1] <= $rowLimit) {
                return $sql;
            }

            return preg_replace('/\bLIMIT\s+\d+/i', 'LIMIT '.$rowLimit, $sql);
        }

        return $sql.' LIMIT '.$rowLimit;
    }
}
```

- [ ] **Step 4: Run the test**

Run: `cd backend && php artisan test --filter SqlGuardTest`
Expected: PASS (all cases). If the `FROM (SELECT` subquery case captures `select`, the `$name === 'select'` skip handles it.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Assistant/Services/SqlGuard.php backend/app/Modules/Assistant/Exceptions/SqlRefusedException.php backend/tests/Unit/Assistant/SqlGuardTest.php
git commit -m "Ask ERP: SQL guard — one SELECT on allowed tables and columns"
```

---

### Task 7: SqlWriter interface and Anthropic implementation

**Files:**
- Create: `backend/app/Modules/Assistant/Services/SqlWriter.php`, `SqlDraft.php`, `SqlRequest.php`, `AnthropicSqlWriter.php`, `backend/app/Modules/Assistant/Exceptions/AskErpException.php`
- Test: `backend/tests/Unit/Assistant/AnthropicSqlWriterTest.php` (prompt assembly only; no network)

**Interfaces:**
- Produces:
  - `SqlRequest { string $question; list<string> $tableSpecs (rendered); list<array{question:string, sql:?string, answer:?string}> $history; string $today }`
  - `SqlDraft { string $sql; string $answerTemplate; string $chartHint ('bar'|'line'|'none') }`
  - `interface SqlWriter { public function write(SqlRequest $request): SqlDraft; }`
  - `AnthropicSqlWriter::systemPrompt(): string` and `AnthropicSqlWriter::userPrompt(SqlRequest): string` are public so the test can pin them.
  - `AskErpException extends RuntimeException` with `public function __construct(string $message, public readonly int $status = 422)`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// backend/tests/Unit/Assistant/AnthropicSqlWriterTest.php
namespace Tests\Unit\Assistant;

use App\Modules\Assistant\Services\AnthropicSqlWriter;
use App\Modules\Assistant\Services\SqlRequest;
use PHPUnit\Framework\TestCase;

class AnthropicSqlWriterTest extends TestCase
{
    public function test_prompt_carries_the_rules_the_specs_and_the_history(): void
    {
        $request = new SqlRequest(
            question: 'how many open POs',
            tableSpecs: ["purchase_orders (procurement): A PO.\n  - id integer — pk"],
            history: [['question' => 'vendors count', 'sql' => 'SELECT COUNT(*) FROM vendors', 'answer' => 'There are 12 vendors.']],
            today: '2026-09-03',
        );

        $system = AnthropicSqlWriter::systemPrompt();
        $user = AnthropicSqlWriter::userPrompt($request);

        $this->assertStringContainsString('single SELECT', $system);
        $this->assertStringContainsString('MySQL', $system);
        $this->assertStringContainsString('purchase_orders (procurement)', $user);
        $this->assertStringContainsString('Today is 2026-09-03', $user);
        $this->assertStringContainsString('vendors count', $user);
        $this->assertStringContainsString('SELECT COUNT(*) FROM vendors', $user);
        $this->assertStringContainsString('how many open POs', $user);
    }

    public function test_output_schema_requires_sql_answer_and_chart_hint(): void
    {
        $schema = AnthropicSqlWriter::outputSchema();

        $this->assertSame(['sql', 'answer_template', 'chart_hint'], $schema['required']);
        $this->assertSame(['bar', 'line', 'none'], $schema['properties']['chart_hint']['enum']);
        $this->assertFalse($schema['additionalProperties']);
    }
}
```

- [ ] **Step 2: Run to see it fail**

Run: `cd backend && php artisan test --filter AnthropicSqlWriterTest`
Expected: FAIL.

- [ ] **Step 3: Implement**

```php
<?php
// backend/app/Modules/Assistant/Exceptions/AskErpException.php
namespace App\Modules\Assistant\Exceptions;

use RuntimeException;

class AskErpException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
```

```php
<?php
// backend/app/Modules/Assistant/Services/SqlRequest.php
namespace App\Modules\Assistant\Services;

final class SqlRequest
{
    /**
     * @param  list<string>  $tableSpecs  rendered TableSpec texts
     * @param  list<array{question: string, sql: ?string, answer: ?string}>  $history
     */
    public function __construct(
        public readonly string $question,
        public readonly array $tableSpecs,
        public readonly array $history,
        public readonly string $today,
    ) {}
}
```

```php
<?php
// backend/app/Modules/Assistant/Services/SqlDraft.php
namespace App\Modules\Assistant\Services;

final class SqlDraft
{
    public function __construct(
        public readonly string $sql,
        public readonly string $answerTemplate,
        public readonly string $chartHint = 'none',
    ) {}
}
```

```php
<?php
// backend/app/Modules/Assistant/Services/SqlWriter.php
namespace App\Modules\Assistant\Services;

interface SqlWriter
{
    /** @throws \App\Modules\Assistant\Exceptions\AskErpException */
    public function write(SqlRequest $request): SqlDraft;
}
```

```php
<?php
// backend/app/Modules/Assistant/Services/AnthropicSqlWriter.php
namespace App\Modules\Assistant\Services;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use App\Modules\Assistant\Exceptions\AskErpException;

/**
 * Asks Claude for one SELECT as structured JSON. The model sees only the
 * rendered specs SchemaRetriever chose; the SQL it returns is still checked
 * by SqlGuard before anything runs.
 */
class AnthropicSqlWriter implements SqlWriter
{
    public function write(SqlRequest $request): SqlDraft
    {
        $apiKey = (string) config('ask-erp.api_key');
        if ($apiKey === '') {
            throw new AskErpException('Ask ERP is not configured on this server.', 503);
        }

        $client = new Client(apiKey: $apiKey);

        try {
            $message = $client->withOptions(timeout: (float) config('ask-erp.timeout'))->messages->create(
                model: (string) config('ask-erp.model'),
                maxTokens: (int) config('ask-erp.max_tokens'),
                system: [['type' => 'text', 'text' => self::systemPrompt(), 'cacheControl' => ['type' => 'ephemeral']]],
                messages: [['role' => 'user', 'content' => self::userPrompt($request)]],
                outputConfig: [
                    'effort' => (string) config('ask-erp.effort'),
                    'format' => ['type' => 'json_schema', 'schema' => self::outputSchema()],
                ],
            );
        } catch (APIConnectionException $e) {
            throw new AskErpException('The model did not answer in time. Try again.', 504);
        } catch (APIStatusException $e) {
            throw new AskErpException('The model refused the request ('.($e->type?->value ?? 'error').').', 502);
        }

        if ($message->stopReason === 'refusal') {
            throw new AskErpException('The model declined to write that query.', 422);
        }

        $json = null;
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $json = json_decode($block->text, true);
                break;
            }
        }
        if (! is_array($json) || trim((string) ($json['sql'] ?? '')) === '') {
            throw new AskErpException('Could not turn that into a query. Try naming the table or the field.', 422);
        }

        return new SqlDraft(
            sql: trim($json['sql']),
            answerTemplate: (string) ($json['answer_template'] ?? ''),
            chartHint: in_array($json['chart_hint'] ?? 'none', ['bar', 'line', 'none'], true) ? $json['chart_hint'] : 'none',
        );
    }

    public static function systemPrompt(): string
    {
        return <<<'TXT'
You write one SQL query that answers a question about a manufacturing ERP's MySQL database.

Rules:
- Output a single SELECT (a WITH ... SELECT is fine). Never write, alter, or lock anything.
- Use only the tables and columns listed in the request. Do not invent columns.
- Alias every table. Prefer aggregates and GROUP BY over raw row dumps. Add ORDER BY.
- Money and quantities are DECIMAL; use ROUND(..., 2) on sums.
- Dates: use CURDATE(), DATE_SUB, YEAR(), MONTH(); the factory is in India.
- Soft-deleted rows have deleted_at IS NOT NULL — exclude them unless asked.
- Keep results under 200 rows; add LIMIT.
- answer_template is one plain sentence using {{count}} for the number of rows, {{first.<column>}} for a value from the first row, and {{sum.<column>}} for a column total.
- chart_hint is "bar" for one label plus one number per row, "line" when the label is a date or month, else "none".
TXT;
    }

    public static function userPrompt(SqlRequest $request): string
    {
        $parts = ['Today is '.$request->today.'.', '', 'Tables available:', implode("\n\n", $request->tableSpecs)];

        if ($request->history !== []) {
            $parts[] = '';
            $parts[] = 'Earlier in this conversation:';
            foreach ($request->history as $turn) {
                $parts[] = 'Q: '.$turn['question'];
                if ($turn['sql']) {
                    $parts[] = 'SQL: '.$turn['sql'];
                }
                if ($turn['answer']) {
                    $parts[] = 'A: '.$turn['answer'];
                }
            }
        }

        $parts[] = '';
        $parts[] = 'Question: '.$request->question;

        return implode("\n", $parts);
    }

    /** @return array<string, mixed> */
    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sql' => ['type' => 'string'],
                'answer_template' => ['type' => 'string'],
                'chart_hint' => ['type' => 'string', 'enum' => ['bar', 'line', 'none']],
            ],
            'required' => ['sql', 'answer_template', 'chart_hint'],
            'additionalProperties' => false,
        ];
    }
}
```

Uncomment the `SqlWriter` binding in `AssistantServiceProvider`. If `$client->withOptions(timeout: ...)` does not exist in the installed SDK version, check `vendor/anthropic-ai/sdk/src/Client.php` for the constructor's timeout/`requestOptions` parameter and pass it there instead — do not guess; read the file.

- [ ] **Step 4: Run the tests**

Run: `cd backend && php artisan test --filter AnthropicSqlWriterTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Assistant/Services/{SqlWriter,SqlDraft,SqlRequest,AnthropicSqlWriter}.php backend/app/Modules/Assistant/Exceptions/AskErpException.php backend/app/Providers/AssistantServiceProvider.php backend/tests/Unit/Assistant/AnthropicSqlWriterTest.php
git commit -m "Ask ERP: Claude writes one SELECT as structured JSON"
```

---

### Task 8: QueryRunner, ChartSuggestion, AskErpService, models and migration

**Files:**
- Create: `backend/app/Modules/Assistant/Services/QueryRunner.php`, `ChartSuggestion.php`, `AskErpService.php`, `AnswerTemplate.php`
- Create: `backend/app/Modules/Assistant/Models/AskErpConversation.php`, `AskErpMessage.php`
- Create: `backend/database/migrations/2026_09_03_100000_create_ask_erp_tables.php`
- Test: `backend/tests/Unit/Assistant/ChartSuggestionTest.php`, `backend/tests/Unit/Assistant/AnswerTemplateTest.php`, `backend/tests/Feature/Assistant/AskErpServiceTest.php`

**Interfaces:**
- Produces:
  - `QueryRunner::run(string $sql, int $rowLimit): array{columns: list<string>, rows: list<array<string, mixed>>, truncated: bool}`
  - `ChartSuggestion::for(array $columns, array $rows, string $hint): ?array{type: string, x: string, y: string}`
  - `AnswerTemplate::render(string $template, array $columns, array $rows): string`
  - `AskErpService::ask(User $user, AskErpConversation $conversation, string $question): AskErpMessage` (the stored assistant message; result rows attached as a transient `$message->result` array `{columns, rows, truncated, chart}`)
  - `AskErpService::catalogueFor(User $user): list<array{table: string, label: string, module: string}>`
  - Models: `AskErpConversation (id, user_id, title)` hasMany `messages`; `AskErpMessage (conversation_id, role 'user'|'assistant', question, sql, answer, tables_used json, row_count, error, duration_ms)`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
// backend/tests/Unit/Assistant/ChartSuggestionTest.php
namespace Tests\Unit\Assistant;

use App\Modules\Assistant\Services\ChartSuggestion;
use PHPUnit\Framework\TestCase;

class ChartSuggestionTest extends TestCase
{
    public function test_label_plus_number_is_a_bar(): void
    {
        $rows = [['status' => 'open', 'n' => 3], ['status' => 'closed', 'n' => 9]];

        $this->assertSame(['type' => 'bar', 'x' => 'status', 'y' => 'n'], ChartSuggestion::for(['status', 'n'], $rows, 'none'));
    }

    public function test_date_label_is_a_line(): void
    {
        $rows = [['day' => '2026-09-01', 'kg' => '10.5'], ['day' => '2026-09-02', 'kg' => '12']];

        $this->assertSame(['type' => 'line', 'x' => 'day', 'y' => 'kg'], ChartSuggestion::for(['day', 'kg'], $rows, 'none'));
    }

    public function test_single_row_or_three_columns_is_no_chart(): void
    {
        $this->assertNull(ChartSuggestion::for(['n'], [['n' => 4]], 'bar'));
        $this->assertNull(ChartSuggestion::for(['a', 'b', 'c'], [['a' => 'x', 'b' => 1, 'c' => 2], ['a' => 'y', 'b' => 1, 'c' => 2]], 'bar'));
    }

    public function test_more_than_sixty_rows_is_no_chart(): void
    {
        $rows = array_map(fn ($i) => ['k' => "k{$i}", 'v' => $i], range(1, 61));

        $this->assertNull(ChartSuggestion::for(['k', 'v'], $rows, 'bar'));
    }
}
```

```php
<?php
// backend/tests/Unit/Assistant/AnswerTemplateTest.php
namespace Tests\Unit\Assistant;

use App\Modules\Assistant\Services\AnswerTemplate;
use PHPUnit\Framework\TestCase;

class AnswerTemplateTest extends TestCase
{
    public function test_fills_count_first_and_sum(): void
    {
        $rows = [['vendor' => 'Acme', 'n' => 3], ['vendor' => 'Bolt', 'n' => 5]];

        $this->assertSame(
            '2 vendors; Acme leads with 3; 8 in all.',
            AnswerTemplate::render('{{count}} vendors; {{first.vendor}} leads with {{first.n}}; {{sum.n}} in all.', ['vendor', 'n'], $rows)
        );
    }

    public function test_empty_result_says_so(): void
    {
        $this->assertSame('No rows matched.', AnswerTemplate::render('{{first.vendor}} leads.', ['vendor'], []));
    }
}
```

```php
<?php
// backend/tests/Feature/Assistant/AskErpServiceTest.php
namespace Tests\Feature\Assistant;

use App\Modules\Assistant\Catalogue\ColumnSpec;
use App\Modules\Assistant\Catalogue\SchemaCatalogue;
use App\Modules\Assistant\Catalogue\TableSpec;
use App\Modules\Assistant\Exceptions\AskErpException;
use App\Modules\Assistant\Models\AskErpConversation;
use App\Modules\Assistant\Services\AskErpService;
use App\Modules\Assistant\Services\SqlDraft;
use App\Modules\Assistant\Services\SqlRequest;
use App\Modules\Assistant\Services\SqlWriter;
use App\Modules\Core\Models\User;
use App\Modules\HRMS\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AskErpServiceTest extends TestCase
{
    use RefreshDatabase;

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

            public function __construct(private string $sql) {}

            public function write(SqlRequest $request): SqlDraft
            {
                $this->seen = $request;

                return new SqlDraft($this->sql, '{{count}} employees by status; {{first.status}} first.', 'bar');
            }
        });
    }

    public function test_answers_from_the_database_and_stores_the_turn(): void
    {
        $this->catalogue();
        $this->writer('SELECT e.status, COUNT(*) AS n FROM employees e GROUP BY e.status ORDER BY n DESC');
        Employee::create(['employee_code' => 'A', 'name' => 'A', 'date_of_joining' => '2026-01-01', 'status' => 'active']);
        Employee::create(['employee_code' => 'B', 'name' => 'B', 'date_of_joining' => '2026-01-01', 'status' => 'active']);
        Employee::create(['employee_code' => 'C', 'name' => 'C', 'date_of_joining' => '2026-01-01', 'status' => 'inactive']);

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
        $this->assertDatabaseCount('ask_erp_messages', 2); // the user's turn and the answer
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
        }

        $writer = app(SqlWriter::class);
        $this->assertStringNotContainsString('phone', implode("\n", $writer->seen->tableSpecs));
        $this->assertDatabaseHas('ask_erp_messages', ['role' => 'assistant', 'error' => $e->getMessage()]);
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
        $this->assertSame('how many employees', $seen->history[0]['question']);
        $this->assertStringContainsString('COUNT(*)', $seen->history[0]['sql']);
    }
}
```

- [ ] **Step 2: Run to see them fail**

Run: `cd backend && php artisan test --filter 'ChartSuggestionTest|AnswerTemplateTest|AskErpServiceTest'`
Expected: FAIL.

- [ ] **Step 3: Implement**

Migration:

```php
<?php
// backend/database/migrations/2026_09_03_100000_create_ask_erp_tables.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ask_erp_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 120);
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('ask_erp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ask_erp_conversations')->cascadeOnDelete();
            $table->string('role', 16); // user | assistant
            $table->text('question')->nullable();
            $table->text('sql')->nullable();
            $table->text('answer')->nullable();
            $table->json('tables_used')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ask_erp_messages');
        Schema::dropIfExists('ask_erp_conversations');
    }
};
```

Models:

```php
<?php
// backend/app/Modules/Assistant/Models/AskErpConversation.php
namespace App\Modules\Assistant\Models;

use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AskErpConversation extends Model
{
    protected $fillable = ['user_id', 'title'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AskErpMessage::class, 'conversation_id')->orderBy('id');
    }
}
```

```php
<?php
// backend/app/Modules/Assistant/Models/AskErpMessage.php
namespace App\Modules\Assistant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AskErpMessage extends Model
{
    protected $fillable = ['conversation_id', 'role', 'question', 'sql', 'answer', 'tables_used', 'row_count', 'error', 'duration_ms'];

    protected $casts = ['tables_used' => 'array'];

    /** Result rows for THIS response only; never persisted. @var array{columns: list<string>, rows: list<array<string, mixed>>, truncated: bool, chart: ?array}|null */
    public ?array $result = null;

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AskErpConversation::class, 'conversation_id');
    }
}
```

Check `User` lives at `App\Modules\Core\Models\User` (grep `class User extends`); adjust the import if not.

Services:

```php
<?php
// backend/app/Modules/Assistant/Services/QueryRunner.php
namespace App\Modules\Assistant\Services;

use App\Modules\Assistant\Exceptions\AskErpException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class QueryRunner
{
    /** @return array{columns: list<string>, rows: list<array<string, mixed>>, truncated: bool} */
    public function run(string $sql, int $rowLimit): array
    {
        $connection = DB::connection(config('ask-erp.connection') ?: null);

        try {
            if ($connection->getDriverName() === 'mysql') {
                $connection->statement('SET SESSION MAX_EXECUTION_TIME = 10000');
            }
            $result = $connection->select($sql);
        } catch (QueryException $e) {
            throw new AskErpException('The query failed: '.$e->getMessage(), 422);
        }

        $rows = array_map(fn ($row) => (array) $row, $result);
        $truncated = count($rows) > $rowLimit;
        $rows = array_slice($rows, 0, $rowLimit);
        $columns = $rows === [] ? [] : array_keys($rows[0]);

        return ['columns' => $columns, 'rows' => $rows, 'truncated' => $truncated];
    }
}
```

```php
<?php
// backend/app/Modules/Assistant/Services/ChartSuggestion.php
namespace App\Modules\Assistant\Services;

final class ChartSuggestion
{
    /**
     * @param  list<string>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @return array{type: string, x: string, y: string}|null
     */
    public static function for(array $columns, array $rows, string $hint): ?array
    {
        if (count($columns) !== 2 || count($rows) < 2 || count($rows) > 60) {
            return null;
        }
        [$a, $b] = $columns;
        $numeric = fn (string $col) => collect($rows)->every(fn ($r) => is_numeric($r[$col] ?? null));

        if ($numeric($b) && ! $numeric($a)) {
            [$x, $y] = [$a, $b];
        } elseif ($numeric($a) && ! $numeric($b)) {
            [$x, $y] = [$b, $a];
        } else {
            return null;
        }

        $dateLike = collect($rows)->every(fn ($r) => preg_match('/^\d{4}-\d{2}(-\d{2})?/', (string) $r[$x]) === 1);
        $type = $hint === 'line' || $dateLike ? 'line' : 'bar';

        return ['type' => $type, 'x' => $x, 'y' => $y];
    }
}
```

```php
<?php
// backend/app/Modules/Assistant/Services/AnswerTemplate.php
namespace App\Modules\Assistant\Services;

final class AnswerTemplate
{
    /**
     * @param  list<string>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    public static function render(string $template, array $columns, array $rows): string
    {
        if ($rows === []) {
            return 'No rows matched.';
        }
        if (trim($template) === '') {
            return count($rows).' rows.';
        }

        return (string) preg_replace_callback('/\{\{\s*(count|first\.([a-z0-9_]+)|sum\.([a-z0-9_]+))\s*\}\}/i', function (array $m) use ($rows) {
            if ($m[1] === 'count') {
                return (string) count($rows);
            }
            if (! empty($m[2])) {
                return self::format($rows[0][$m[2]] ?? '');
            }
            $sum = array_sum(array_map(fn ($r) => (float) ($r[$m[3]] ?? 0), $rows));

            return self::format($sum);
        }, $template);
    }

    private static function format(mixed $value): string
    {
        if (is_numeric($value)) {
            $float = (float) $value;

            return floor($float) == $float ? number_format($float, 0, '.', '') : number_format($float, 2, '.', '');
        }

        return (string) $value;
    }
}
```

```php
<?php
// backend/app/Modules/Assistant/Services/AskErpService.php
namespace App\Modules\Assistant\Services;

use App\Modules\Assistant\Exceptions\AskErpException;
use App\Modules\Assistant\Exceptions\SqlRefusedException;
use App\Modules\Assistant\Models\AskErpConversation;
use App\Modules\Assistant\Models\AskErpMessage;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Log;

class AskErpService
{
    public function __construct(
        private readonly SchemaRetriever $retriever,
        private readonly SqlWriter $writer,
        private readonly SqlGuard $guard,
        private readonly QueryRunner $runner,
    ) {}

    /** @return list<array{table: string, label: string, module: string}> */
    public function catalogueFor(User $user): array
    {
        return array_values(array_map(
            fn ($spec) => ['table' => $spec->table, 'label' => $spec->label, 'module' => $spec->module],
            $this->retriever->allowedTables($user),
        ));
    }

    public function ask(User $user, AskErpConversation $conversation, string $question): AskErpMessage
    {
        $started = hrtime(true);
        $conversation->messages()->create(['role' => 'user', 'question' => $question]);

        $history = $conversation->messages()
            ->where('role', 'assistant')
            ->whereNull('error')
            ->latest('id')
            ->limit((int) config('ask-erp.history_turns'))
            ->get()
            ->reverse()
            ->map(fn (AskErpMessage $m) => ['question' => (string) $m->question, 'sql' => $m->sql, 'answer' => $m->answer])
            ->values()
            ->all();
        $previousTables = collect($history)->pluck('sql')->filter()->flatMap(fn ($sql) => $this->guard->tablesIn($sql))->unique()->values()->all();

        try {
            $specs = $this->retriever->forQuestion($user, $question, $previousTables);
            if ($specs === []) {
                throw new AskErpException('That question matches none of the tables you can see. Try naming what you want counted.', 422);
            }

            $hidden = [];
            $rendered = [];
            foreach ($specs as $spec) {
                $hidden[$spec->table] = $this->retriever->hiddenColumns($user, $spec);
                $rendered[] = $spec->render($hidden[$spec->table]);
            }

            $draft = $this->writer->write(new SqlRequest(
                question: $question,
                tableSpecs: $rendered,
                history: $history,
                today: now(config('tally-sync.factory_timezone', 'Asia/Kolkata'))->toDateString(),
            ));

            try {
                $sql = $this->guard->check($draft->sql, array_keys($this->retriever->allowedTables($user)), $hidden, (int) config('ask-erp.row_limit'));
            } catch (SqlRefusedException $e) {
                throw new AskErpException($e->getMessage(), 422);
            }

            $result = $this->runner->run($sql, (int) config('ask-erp.row_limit'));
        } catch (AskErpException $e) {
            $conversation->messages()->create([
                'role' => 'assistant',
                'question' => $question,
                'error' => $e->getMessage(),
                'duration_ms' => (int) ((hrtime(true) - $started) / 1e6),
            ]);
            Log::info('ask-erp refused', ['user' => $user->id, 'question' => $question, 'reason' => $e->getMessage()]);
            throw $e;
        }

        $message = $conversation->messages()->create([
            'role' => 'assistant',
            'question' => $question,
            'sql' => $sql,
            'answer' => AnswerTemplate::render($draft->answerTemplate, $result['columns'], $result['rows']),
            'tables_used' => $this->guard->tablesIn($sql),
            'row_count' => count($result['rows']),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1e6),
        ]);
        $message->result = [...$result, 'chart' => ChartSuggestion::for($result['columns'], $result['rows'], $draft->chartHint)];
        $conversation->touch();

        return $message;
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `cd backend && php artisan test --filter 'ChartSuggestionTest|AnswerTemplateTest|AskErpServiceTest'`
Expected: PASS. Note the history test: the second call's history must contain the FIRST answer only (the service records the user turn before reading history and filters `role = assistant`).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Assistant backend/database/migrations/2026_09_03_100000_create_ask_erp_tables.php backend/tests/Unit/Assistant/{ChartSuggestionTest,AnswerTemplateTest}.php backend/tests/Feature/Assistant/AskErpServiceTest.php
git commit -m "Ask ERP: guarded read, stored conversation, chart and answer shaping"
```

---

### Task 9: HTTP layer — controller, requests, resources, routes

**Files:**
- Create: `backend/app/Modules/Assistant/Http/Controllers/AskErpController.php`, `Http/Requests/AskQuestionRequest.php`, `Http/Requests/ListConversationsRequest.php`, `Http/Resources/ConversationResource.php`, `Http/Resources/MessageResource.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Assistant/AskErpApiTest.php`

**Interfaces:**
- Produces, under `/api/v1/ask-erp` (`module:assistant`):
  - `GET catalogue` → `{ data: [{table,label,module}], configured: bool }`
  - `GET conversations?q=&page=&per_page=` → paginated `{id, title, updated_at, message_count}` (own only, newest first)
  - `POST conversations {title?}` → 201 conversation
  - `GET conversations/{id}` → conversation with `messages: [{id, role, question, sql, answer, tables_used, row_count, error, created_at}]` (404 if not the caller's)
  - `POST conversations/{id}/ask {question}` → `{ message: MessageResource, result: {columns, rows, truncated, chart} }`; errors as `{message}` with the exception's status.

- [ ] **Step 1: Write the failing test**

```php
<?php
// backend/tests/Feature/Assistant/AskErpApiTest.php
namespace Tests\Feature\Assistant;

use App\Modules\Assistant\Catalogue\ColumnSpec;
use App\Modules\Assistant\Catalogue\SchemaCatalogue;
use App\Modules\Assistant\Catalogue\TableSpec;
use App\Modules\Assistant\Models\AskErpConversation;
use App\Modules\Assistant\Services\SqlDraft;
use App\Modules\Assistant\Services\SqlRequest;
use App\Modules\Assistant\Services\SqlWriter;
use App\Modules\Core\Models\User;
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
    }

    public function test_catalogue_lists_only_viewable_tables(): void
    {
        $this->login(['assistant.view']);
        $this->getJson('/api/v1/ask-erp/catalogue')->assertOk()->assertJson(['data' => [], 'configured' => true]);

        $this->login(['assistant.view', 'hrms.view']);
        $this->getJson('/api/v1/ask-erp/catalogue')->assertOk()->assertJsonPath('data.0.table', 'employees');
    }

    public function test_conversation_round_trip(): void
    {
        $user = $this->login(['assistant.view', 'hrms.view']);
        Employee::create(['employee_code' => 'A', 'name' => 'A', 'date_of_joining' => '2026-01-01', 'status' => 'active']);

        $id = $this->postJson('/api/v1/ask-erp/conversations', ['title' => 'Staff'])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/ask-erp/conversations/{$id}/ask", ['question' => 'employees by status'])
            ->assertOk()
            ->assertJsonPath('message.answer', '1 statuses.')
            ->assertJsonPath('result.columns', ['status', 'n'])
            ->assertJsonPath('result.rows.0.status', 'active');

        $this->getJson("/api/v1/ask-erp/conversations/{$id}")->assertOk()->assertJsonCount(2, 'data.messages');
        $this->getJson('/api/v1/ask-erp/conversations?q=staff')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.message_count', 2);
        $this->getJson('/api/v1/ask-erp/conversations?q=nothing')->assertOk()->assertJsonCount(0, 'data');

        $this->login(['assistant.view']);
        $this->getJson("/api/v1/ask-erp/conversations/{$id}")->assertNotFound();
    }

    public function test_question_is_required_and_capped(): void
    {
        $user = $this->login(['assistant.view', 'hrms.view']);
        $conversation = AskErpConversation::create(['user_id' => $user->id, 'title' => 'x']);

        $this->postJson("/api/v1/ask-erp/conversations/{$conversation->id}/ask", ['question' => ''])->assertUnprocessable();
        $this->postJson("/api/v1/ask-erp/conversations/{$conversation->id}/ask", ['question' => str_repeat('a', 501)])->assertUnprocessable();
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
```

- [ ] **Step 2: Run to see it fail**

Run: `cd backend && php artisan test --filter AskErpApiTest`
Expected: FAIL (404 routes).

- [ ] **Step 3: Implement**

```php
<?php
// backend/app/Modules/Assistant/Http/Requests/AskQuestionRequest.php
namespace App\Modules\Assistant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AskQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['question' => ['required', 'string', 'max:500']];
    }
}
```

```php
<?php
// backend/app/Modules/Assistant/Http/Requests/ListConversationsRequest.php
namespace App\Modules\Assistant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListConversationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
```

```php
<?php
// backend/app/Modules/Assistant/Http/Resources/MessageResource.php
namespace App\Modules\Assistant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'question' => $this->question,
            'sql' => $this->sql,
            'answer' => $this->answer,
            'tables_used' => $this->tables_used ?? [],
            'row_count' => $this->row_count,
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

```php
<?php
// backend/app/Modules/Assistant/Http/Resources/ConversationResource.php
namespace App\Modules\Assistant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'message_count' => $this->messages_count ?? $this->messages->count(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'messages' => MessageResource::collection($this->whenLoaded('messages')),
        ];
    }
}
```

```php
<?php
// backend/app/Modules/Assistant/Http/Controllers/AskErpController.php
namespace App\Modules\Assistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assistant\Exceptions\AskErpException;
use App\Modules\Assistant\Http\Requests\AskQuestionRequest;
use App\Modules\Assistant\Http\Requests\ListConversationsRequest;
use App\Modules\Assistant\Http\Resources\ConversationResource;
use App\Modules\Assistant\Http\Resources\MessageResource;
use App\Modules\Assistant\Models\AskErpConversation;
use App\Modules\Assistant\Services\AskErpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class AskErpController extends Controller
{
    public function __construct(private readonly AskErpService $service) {}

    public function catalogue(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->catalogueFor($request->user()),
            'configured' => (string) config('ask-erp.api_key') !== '',
        ]);
    }

    public function index(ListConversationsRequest $request): AnonymousResourceCollection
    {
        $query = AskErpConversation::query()
            ->where('user_id', $request->user()->id)
            ->withCount('messages')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if (($term = trim((string) $request->validated('q', ''))) !== '') {
            $query->where('title', 'like', '%'.$term.'%');
        }

        return ConversationResource::collection($query->paginate((int) $request->validated('per_page', 20))->withQueryString());
    }

    public function store(Request $request): JsonResponse
    {
        $title = trim((string) $request->input('title', '')) ?: 'New question';
        $conversation = AskErpConversation::create(['user_id' => $request->user()->id, 'title' => Str::limit($title, 120, '')]);

        return (new ConversationResource($conversation->load('messages')))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $id): ConversationResource
    {
        return new ConversationResource($this->own($request, $id)->load('messages'));
    }

    public function ask(AskQuestionRequest $request, int $id): JsonResponse
    {
        $conversation = $this->own($request, $id);
        $question = trim($request->validated('question'));

        if ($conversation->title === 'New question') {
            $conversation->update(['title' => Str::limit($question, 120, '')]);
        }

        try {
            $message = $this->service->ask($request->user(), $conversation, $question);
        } catch (AskErpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['message' => new MessageResource($message), 'result' => $message->result]);
    }

    private function own(Request $request, int $id): AskErpConversation
    {
        return AskErpConversation::query()->where('user_id', $request->user()->id)->findOrFail($id);
    }
}
```

Routes — in `backend/routes/api.php`, inside the authenticated `v1` group next to the `hrms` group, add:

```php
        // ASK ERP — natural-language questions over the tables this login may
        // view. `module:assistant` gates the page; what the page may READ is
        // decided per table inside SchemaRetriever from the login's other
        // module permissions, and per column by SensitiveColumns.
        Route::prefix('ask-erp')->middleware('module:assistant')->group(function () {
            Route::get('catalogue', [AskErpController::class, 'catalogue']);
            Route::get('conversations', [AskErpController::class, 'index']);
            Route::post('conversations', [AskErpController::class, 'store']);
            Route::get('conversations/{id}', [AskErpController::class, 'show']);
            Route::post('conversations/{id}/ask', [AskErpController::class, 'ask']);
        });
```

with `use App\Modules\Assistant\Http\Controllers\AskErpController;` at the top.

- [ ] **Step 4: Run the tests, then pint and the whole backend suite**

Run: `cd backend && php artisan test --filter AskErpApiTest && ./vendor/bin/pint && php artisan test`
Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Modules/Assistant/Http backend/routes/api.php backend/tests/Feature/Assistant/AskErpApiTest.php
git commit -m "Ask ERP: conversations and ask endpoints under module:assistant"
```

---

### Task 10: Frontend types, api, chart and csv helpers

**Files:**
- Create: `frontend/src/features/ask-erp/types.ts`, `api.ts`, `chart.ts`, `chart.test.ts`, `csv.ts`, `csv.test.ts`

**Interfaces:**
- Produces:
  - `types.ts`: `AskErpMessage`, `AskErpConversation`, `AskErpConversationSummary`, `AskResult { columns: string[]; rows: Record<string, unknown>[]; truncated: boolean; chart: ChartSpec | null }`, `ChartSpec { type: 'bar'|'line'; x: string; y: string }`, `CatalogueEntry { table; label; module }`, `ConversationListParams = ListParams`.
  - `api.ts`: `getCatalogue()`, `listConversations(params)`, `createConversation(title?)`, `getConversation(id)`, `askQuestion(id, question)` → `{ message, result }`.
  - `chart.ts`: `chartPoints(result: AskResult): { label: string; value: number }[]` and `chartScale(points): { max: number; ticks: number[] }`.
  - `csv.ts`: `resultToCsv(columns, rows): string` (RFC 4180 quoting) — and the download uses `downloadBlob` from `@/lib/csv` if it exists (read `frontend/src/lib/csv.ts` first; if it exports a blob-download helper use it, else add `downloadText(name, text)` in `csv.ts`).

- [ ] **Step 1: Write the failing tests**

```ts
// frontend/src/features/ask-erp/chart.test.ts
import { describe, expect, it } from 'vitest';
import { chartPoints, chartScale } from './chart';

describe('chartPoints', () => {
    it('reads x and y from the chart spec, numbers coerced', () => {
        const points = chartPoints({
            columns: ['status', 'n'],
            rows: [{ status: 'open', n: '3' }, { status: 'closed', n: 9 }],
            truncated: false,
            chart: { type: 'bar', x: 'status', y: 'n' },
        });
        expect(points).toEqual([{ label: 'open', value: 3 }, { label: 'closed', value: 9 }]);
    });

    it('is empty without a chart spec', () => {
        expect(chartPoints({ columns: ['n'], rows: [{ n: 1 }], truncated: false, chart: null })).toEqual([]);
    });
});

describe('chartScale', () => {
    it('rounds the max up to a friendly number and gives four ticks', () => {
        expect(chartScale([{ label: 'a', value: 37 }, { label: 'b', value: 12 }])).toEqual({ max: 40, ticks: [0, 10, 20, 30, 40] });
        expect(chartScale([{ label: 'a', value: 0.6 }])).toEqual({ max: 1, ticks: [0, 0.25, 0.5, 0.75, 1] });
        expect(chartScale([])).toEqual({ max: 1, ticks: [0, 0.25, 0.5, 0.75, 1] });
    });
});
```

```ts
// frontend/src/features/ask-erp/csv.test.ts
import { describe, expect, it } from 'vitest';
import { resultToCsv } from './csv';

describe('resultToCsv', () => {
    it('quotes commas, quotes and newlines and keeps column order', () => {
        const csv = resultToCsv(['name', 'n'], [{ name: 'Acme, Ltd', n: 3 }, { name: 'Say "hi"', n: null }, { name: 'two\nlines', n: 1.5 }]);
        expect(csv).toBe('name,n\r\n"Acme, Ltd",3\r\n"Say ""hi""",\r\n"two\nlines",1.5\r\n');
    });
});
```

- [ ] **Step 2: Run to see them fail**

Run: `cd frontend && npx vitest run src/features/ask-erp`
Expected: FAIL (modules missing).

- [ ] **Step 3: Implement**

```ts
// frontend/src/features/ask-erp/types.ts
import type { ListParams } from '@/lib/listParams';

export interface ChartSpec {
    type: 'bar' | 'line';
    x: string;
    y: string;
}

export interface AskResult {
    columns: string[];
    rows: Record<string, unknown>[];
    truncated: boolean;
    chart: ChartSpec | null;
}

export interface AskErpMessage {
    id: number;
    role: 'user' | 'assistant';
    question: string | null;
    sql: string | null;
    answer: string | null;
    tables_used: string[];
    row_count: number | null;
    error: string | null;
    created_at: string;
}

export interface AskErpConversationSummary {
    id: number;
    title: string;
    message_count: number;
    updated_at: string;
}

export interface AskErpConversation extends AskErpConversationSummary {
    messages: AskErpMessage[];
}

export interface CatalogueEntry {
    table: string;
    label: string;
    module: string;
}

export type ConversationListParams = ListParams;
```

```ts
// frontend/src/features/ask-erp/api.ts
import { api } from '@/lib/api';
import { compactParams } from '@/lib/listParams';
import type { Paginated } from '@/lib/types';
import type { AskErpConversation, AskErpConversationSummary, AskErpMessage, AskResult, CatalogueEntry, ConversationListParams } from './types';

export async function getCatalogue(): Promise<{ data: CatalogueEntry[]; configured: boolean }> {
    const { data } = await api.get<{ data: CatalogueEntry[]; configured: boolean }>('/ask-erp/catalogue');
    return data;
}

export async function listConversations(params: ConversationListParams = {}): Promise<Paginated<AskErpConversationSummary>> {
    const { data } = await api.get<Paginated<AskErpConversationSummary>>('/ask-erp/conversations', { params: compactParams(params) });
    return data;
}

export async function createConversation(title?: string): Promise<AskErpConversation> {
    const { data } = await api.post<{ data: AskErpConversation }>('/ask-erp/conversations', { title });
    return data.data;
}

export async function getConversation(id: number): Promise<AskErpConversation> {
    const { data } = await api.get<{ data: AskErpConversation }>(`/ask-erp/conversations/${id}`);
    return data.data;
}

export async function askQuestion(id: number, question: string): Promise<{ message: AskErpMessage; result: AskResult }> {
    const { data } = await api.post<{ message: AskErpMessage; result: AskResult }>(`/ask-erp/conversations/${id}/ask`, { question });
    return data;
}
```

```ts
// frontend/src/features/ask-erp/chart.ts
import type { AskResult } from './types';

export interface ChartPoint {
    label: string;
    value: number;
}

export function chartPoints(result: AskResult): ChartPoint[] {
    if (!result.chart) return [];
    const { x, y } = result.chart;
    return result.rows.map((row) => ({ label: String(row[x] ?? ''), value: Number(row[y] ?? 0) || 0 }));
}

export function chartScale(points: ChartPoint[]): { max: number; ticks: number[] } {
    const raw = Math.max(0, ...points.map((p) => p.value));
    const max = raw <= 0 ? 1 : niceCeiling(raw);
    const step = max / 4;
    return { max, ticks: [0, step, step * 2, step * 3, max] };
}

function niceCeiling(value: number): number {
    if (value <= 1) return 1;
    const magnitude = 10 ** Math.floor(Math.log10(value));
    const candidates = [1, 2, 2.5, 4, 5, 10].map((m) => m * magnitude);
    return candidates.find((c) => c >= value) ?? 10 * magnitude;
}
```

```ts
// frontend/src/features/ask-erp/csv.ts
export function resultToCsv(columns: string[], rows: Record<string, unknown>[]): string {
    const cell = (value: unknown): string => {
        if (value === null || value === undefined) return '';
        const text = String(value);
        return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
    };
    const lines = [columns.map(cell).join(',')];
    for (const row of rows) lines.push(columns.map((c) => cell(row[c])).join(','));
    return lines.join('\r\n') + '\r\n';
}

export function downloadText(fileName: string, text: string, mime = 'text/csv;charset=utf-8'): void {
    const url = URL.createObjectURL(new Blob([text], { type: mime }));
    const a = document.createElement('a');
    a.href = url;
    a.download = fileName;
    a.click();
    URL.revokeObjectURL(url);
}
```

(If `@/lib/csv` already exports an equivalent of `downloadText`, import and re-export that instead of defining a second one.)

- [ ] **Step 4: Run the tests**

Run: `cd frontend && npx vitest run src/features/ask-erp && npm run typecheck`
Expected: PASS, typecheck clean.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/features/ask-erp
git commit -m "Ask ERP: frontend types, API client, chart and CSV helpers"
```

---

### Task 11: Page, components, sidebar, route, adoption

**Files:**
- Create: `frontend/src/features/ask-erp/components/ResultChart.tsx`, `AnswerCard.tsx`, `TableChips.tsx`, `frontend/src/features/ask-erp/pages/AskErpPage.tsx`, `AskErpPage.render.test.tsx`
- Modify: `frontend/src/lib/adoptedModules.ts`, `frontend/src/app/AppLayout.tsx`, `frontend/src/app/AppLayout.nav.test.ts`, `frontend/src/app/App.tsx`, `frontend/src/app/App.routes.test.tsx`

**Interfaces:**
- Consumes: Task 10's api/types/chart/csv.
- Produces: route `/ask-erp`; sidebar leaf "Ask ERP" (module `assistant`) directly after Dashboard.

- [ ] **Step 1: Write the failing render test** (mirror `frontend/src/features/hrms/AttendancePage.render.test.tsx` for the QueryClient/router wrapper and the `vi.mock('@/features/ask-erp/api')` shape used there for `@/features/hrms/api`):

```tsx
// frontend/src/features/ask-erp/pages/AskErpPage.render.test.tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import AskErpPage from './AskErpPage';

vi.mock('@/features/ask-erp/api', () => ({
    getCatalogue: vi.fn().mockResolvedValue({ data: [{ table: 'employees', label: 'Employees', module: 'hrms' }], configured: true }),
    listConversations: vi.fn().mockResolvedValue({ data: [{ id: 1, title: 'Staff', message_count: 2, updated_at: '2026-09-03T10:00:00Z' }], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1, from: 1, to: 1 } }),
    getConversation: vi.fn().mockResolvedValue({
        id: 1, title: 'Staff', message_count: 2, updated_at: '2026-09-03T10:00:00Z',
        messages: [
            { id: 1, role: 'user', question: 'employees by status', sql: null, answer: null, tables_used: [], row_count: null, error: null, created_at: '' },
            { id: 2, role: 'assistant', question: 'employees by status', sql: 'SELECT 1', answer: '2 statuses.', tables_used: ['employees'], row_count: 2, error: null, created_at: '' },
        ],
    }),
    createConversation: vi.fn(),
    askQuestion: vi.fn(),
}));

describe('AskErpPage', () => {
    it('renders the catalogue chips, the conversation list and the thread', async () => {
        render(
            <QueryClientProvider client={new QueryClient()}>
                <MemoryRouter initialEntries={['/ask-erp?conversation=1']}>
                    <AskErpPage />
                </MemoryRouter>
            </QueryClientProvider>
        );

        await waitFor(() => expect(screen.getByText('Employees')).toBeInTheDocument());
        expect(screen.getByText('Staff')).toBeInTheDocument();
        await waitFor(() => expect(screen.getByText('2 statuses.')).toBeInTheDocument());
        expect(screen.getByRole('button', { name: /show sql/i })).toBeInTheDocument();
    });
});
```

Check how existing render tests import `@testing-library/jest-dom` (a `setupTests` file or a per-test import) and match it.

- [ ] **Step 2: Run to see it fail**

Run: `cd frontend && npx vitest run src/features/ask-erp/pages`
Expected: FAIL (page missing).

- [ ] **Step 3: Implement the components and the page**

```tsx
// frontend/src/features/ask-erp/components/ResultChart.tsx
import { chartPoints, chartScale } from '@/features/ask-erp/chart';
import type { AskResult } from '@/features/ask-erp/types';

const W = 640;
const H = 220;
const PAD = { top: 12, right: 12, bottom: 48, left: 48 };

export default function ResultChart({ result }: { result: AskResult }) {
    const points = chartPoints(result);
    if (points.length === 0 || !result.chart) return null;
    const { max, ticks } = chartScale(points);
    const innerW = W - PAD.left - PAD.right;
    const innerH = H - PAD.top - PAD.bottom;
    const yFor = (v: number) => PAD.top + innerH - (v / max) * innerH;
    const slot = innerW / points.length;

    return (
        <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label={`${result.chart.y} by ${result.chart.x}`} style={{ width: '100%', maxWidth: W, display: 'block' }}>
            {ticks.map((t) => (
                <g key={t}>
                    <line x1={PAD.left} x2={W - PAD.right} y1={yFor(t)} y2={yFor(t)} stroke="#e5e7eb" />
                    <text x={PAD.left - 6} y={yFor(t) + 4} textAnchor="end" fontSize={11} fill="#6b7280">{t}</text>
                </g>
            ))}
            {result.chart.type === 'bar'
                ? points.map((p, i) => (
                      <rect key={p.label + i} x={PAD.left + i * slot + slot * 0.15} y={yFor(p.value)} width={slot * 0.7} height={PAD.top + innerH - yFor(p.value)} fill="#1677ff">
                          <title>{`${p.label}: ${p.value}`}</title>
                      </rect>
                  ))
                : (
                      <polyline fill="none" stroke="#1677ff" strokeWidth={2} points={points.map((p, i) => `${PAD.left + i * slot + slot / 2},${yFor(p.value)}`).join(' ')} />
                  )}
            {points.map((p, i) => (
                <text key={'l' + i} x={PAD.left + i * slot + slot / 2} y={H - PAD.bottom + 14} textAnchor="middle" fontSize={11} fill="#374151">
                    {p.label.length > 12 ? p.label.slice(0, 11) + '…' : p.label}
                </text>
            ))}
        </svg>
    );
}
```

```tsx
// frontend/src/features/ask-erp/components/AnswerCard.tsx
import { Button, Space, Table, Tag, Typography } from 'antd';
import { useState } from 'react';
import { downloadText, resultToCsv } from '@/features/ask-erp/csv';
import type { AskErpMessage, AskResult } from '@/features/ask-erp/types';
import ResultChart from './ResultChart';

export default function AnswerCard({ message, result }: { message: AskErpMessage; result: AskResult | null }) {
    const [showSql, setShowSql] = useState(false);

    if (message.error) {
        return <Typography.Text type="danger">{message.error}</Typography.Text>;
    }

    return (
        <Space direction="vertical" style={{ width: '100%' }} size="small">
            <Typography.Text strong>{message.answer}</Typography.Text>
            <Space size={4} wrap>
                {message.tables_used.map((t) => <Tag key={t}>{t}</Tag>)}
                {message.row_count !== null ? <Tag color="blue">{message.row_count} rows{result?.truncated ? ' (capped)' : ''}</Tag> : null}
            </Space>
            {result ? <ResultChart result={result} /> : null}
            {result && result.rows.length > 0 ? (
                <Table
                    size="small"
                    rowKey={(_, i) => String(i)}
                    dataSource={result.rows}
                    pagination={result.rows.length > 20 ? { pageSize: 20, size: 'small' } : false}
                    scroll={{ x: 'max-content' }}
                    columns={result.columns.map((c) => ({ title: c, dataIndex: c, render: (v: unknown) => (v === null || v === undefined ? '' : String(v)) }))}
                />
            ) : null}
            <Space>
                <Button size="small" onClick={() => setShowSql((s) => !s)}>{showSql ? 'Hide SQL' : 'Show SQL'}</Button>
                {result && result.rows.length > 0 ? (
                    <Button size="small" onClick={() => downloadText(`ask-erp-${message.id}.csv`, resultToCsv(result.columns, result.rows))}>Download CSV</Button>
                ) : null}
            </Space>
            {showSql ? <pre style={{ margin: 0, whiteSpace: 'pre-wrap', fontSize: 12 }}>{message.sql}</pre> : null}
        </Space>
    );
}
```

```tsx
// frontend/src/features/ask-erp/components/TableChips.tsx
import { Space, Tag } from 'antd';
import type { CatalogueEntry } from '@/features/ask-erp/types';

export default function TableChips({ entries, onPick }: { entries: CatalogueEntry[]; onPick: (entry: CatalogueEntry) => void }) {
    return (
        <Space size={4} wrap>
            {entries.map((e) => (
                <Tag key={e.table} style={{ cursor: 'pointer' }} onClick={() => onPick(e)}>{e.label}</Tag>
            ))}
        </Space>
    );
}
```

```tsx
// frontend/src/features/ask-erp/pages/AskErpPage.tsx
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert, Button, Col, Input, List, Row, Space, Typography } from 'antd';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { askQuestion, createConversation, getCatalogue, getConversation, listConversations } from '@/features/ask-erp/api';
import AnswerCard from '@/features/ask-erp/components/AnswerCard';
import TableChips from '@/features/ask-erp/components/TableChips';
import type { AskErpMessage, AskResult } from '@/features/ask-erp/types';
import { apiErrorMessage } from '@/lib/apiError';

/**
 * ASK ERP. Left: this login's conversations (server search + paging).
 * Right: the thread. A question POSTs to the server, which decides from the
 * login's permissions which tables may even be looked at; the page shows
 * what came back and nothing it computed itself.
 */
export default function AskErpPage() {
    const [searchParams, setSearchParams] = useSearchParams();
    const conversationId = Number(searchParams.get('conversation')) || null;
    const [q, setQ] = useState('');
    const [page, setPage] = useState(1);
    const [draft, setDraft] = useState('');
    const [results, setResults] = useState<Record<number, AskResult>>({});
    const queryClient = useQueryClient();
    const bottomRef = useRef<HTMLDivElement>(null);

    const catalogue = useQuery({ queryKey: ['ask-erp', 'catalogue'], queryFn: getCatalogue });
    const conversations = useQuery({
        queryKey: ['ask-erp', 'conversations', { q, page }],
        queryFn: () => listConversations({ q: q || undefined, page }),
        placeholderData: (previous) => previous,
    });
    const thread = useQuery({
        queryKey: ['ask-erp', 'conversation', conversationId],
        queryFn: () => getConversation(conversationId as number),
        enabled: conversationId !== null,
    });

    const open = (id: number) => setSearchParams({ conversation: String(id) });

    const create = useMutation({
        mutationFn: () => createConversation(),
        onSuccess: (conversation) => {
            queryClient.invalidateQueries({ queryKey: ['ask-erp', 'conversations'] });
            open(conversation.id);
        },
    });

    const ask = useMutation({
        mutationFn: async (question: string) => {
            const id = conversationId ?? (await createConversation(question)).id;
            if (id !== conversationId) open(id);
            return { id, ...(await askQuestion(id, question)) };
        },
        onSuccess: ({ id, message, result }) => {
            setResults((r) => ({ ...r, [message.id]: result }));
            setDraft('');
            queryClient.invalidateQueries({ queryKey: ['ask-erp', 'conversation', id] });
            queryClient.invalidateQueries({ queryKey: ['ask-erp', 'conversations'] });
        },
        onError: () => {
            if (conversationId) queryClient.invalidateQueries({ queryKey: ['ask-erp', 'conversation', conversationId] });
        },
    });

    const messages: AskErpMessage[] = useMemo(() => thread.data?.messages ?? [], [thread.data]);
    useEffect(() => bottomRef.current?.scrollIntoView({ block: 'end' }), [messages.length, ask.isPending]);

    const submit = () => {
        const question = draft.trim();
        if (question && !ask.isPending) ask.mutate(question);
    };

    return (
        <Row gutter={16} style={{ minHeight: 'calc(100vh - 140px)' }}>
            <Col xs={24} md={7} lg={6}>
                <Space direction="vertical" style={{ width: '100%' }}>
                    <Button type="primary" block onClick={() => create.mutate()} loading={create.isPending}>New question</Button>
                    <Input.Search allowClear placeholder="Search conversations" onSearch={(value) => { setQ(value.trim()); setPage(1); }} />
                    <List
                        size="small"
                        loading={conversations.isFetching}
                        dataSource={conversations.data?.data ?? []}
                        pagination={{ current: page, pageSize: conversations.data?.meta.per_page ?? 20, total: conversations.data?.meta.total ?? 0, size: 'small', onChange: setPage, hideOnSinglePage: true }}
                        renderItem={(c) => (
                            <List.Item onClick={() => open(c.id)} style={{ cursor: 'pointer', background: c.id === conversationId ? '#e6f4ff' : undefined }}>
                                <List.Item.Meta title={c.title} description={`${c.message_count} messages`} />
                            </List.Item>
                        )}
                    />
                </Space>
            </Col>
            <Col xs={24} md={17} lg={18}>
                <Space direction="vertical" style={{ width: '100%' }} size="middle">
                    <Typography.Title level={3} style={{ margin: 0 }}>Ask ERP</Typography.Title>
                    {catalogue.data && !catalogue.data.configured ? <Alert type="warning" showIcon message="Ask ERP is not configured on this server." /> : null}
                    <TableChips entries={catalogue.data?.data ?? []} onPick={(entry) => setDraft((d) => (d ? `${d} ${entry.label}` : `How many ${entry.label.toLowerCase()} `))} />
                    <div style={{ maxHeight: 'calc(100vh - 360px)', overflowY: 'auto', paddingRight: 8 }}>
                        <List
                            dataSource={messages}
                            locale={{ emptyText: conversationId ? 'No messages yet.' : 'Ask a question below.' }}
                            renderItem={(m) => (
                                <List.Item style={{ display: 'block', border: 0 }}>
                                    {m.role === 'user' ? (
                                        <div style={{ textAlign: 'right' }}>
                                            <Typography.Text style={{ background: '#1677ff', color: '#fff', padding: '6px 12px', borderRadius: 12, display: 'inline-block' }}>{m.question}</Typography.Text>
                                        </div>
                                    ) : (
                                        <div style={{ background: '#fafafa', padding: 12, borderRadius: 8 }}>
                                            <AnswerCard message={m} result={results[m.id] ?? null} />
                                        </div>
                                    )}
                                </List.Item>
                            )}
                        />
                        {ask.isError ? <Alert type="error" showIcon message={apiErrorMessage(ask.error)} /> : null}
                        <div ref={bottomRef} />
                    </div>
                    <Space.Compact style={{ width: '100%' }}>
                        <Input
                            value={draft}
                            maxLength={500}
                            disabled={ask.isPending}
                            placeholder="e.g. how many purchase orders are open per vendor"
                            onChange={(e) => setDraft(e.target.value)}
                            onPressEnter={submit}
                        />
                        <Button type="primary" onClick={submit} loading={ask.isPending} disabled={!draft.trim()}>Ask</Button>
                    </Space.Compact>
                </Space>
            </Col>
        </Row>
    );
}
```

Read `frontend/src/lib/apiError.ts` for the exact exported name of the message extractor and use that (the code above assumes `apiErrorMessage(error)`).

Wiring:

- `frontend/src/lib/adoptedModules.ts`: add `'assistant',` with a two-line comment: "Ask ERP — adopted with the page (03-Sep-2026). Reads only what the login's other permissions already allow."
- `frontend/src/app/AppLayout.tsx`: import `MessageOutlined` from `@ant-design/icons`; in `allNavItems` insert `{ key: '/ask-erp', icon: <MessageOutlined />, label: 'Ask ERP', module: 'assistant' },` immediately after the Dashboard entry.
- `frontend/src/app/AppLayout.nav.test.ts`: insert `'Ask ERP',` after `'Dashboard',` in `CONFIGURED_ORDER`.
- `frontend/src/app/App.tsx`: add `const AskErpPage = lazyPage(() => import('@/features/ask-erp/pages/AskErpPage'));` in alphabetical position and `<Route path="/ask-erp" element={<AskErpPage />} />` after the `/account/change-password` route.
- `frontend/src/app/App.routes.test.tsx`: add `'/ask-erp',` after `'/account/change-password',` in `ROUTE_TABLE`.

- [ ] **Step 4: Run everything**

Run: `cd frontend && npm run typecheck && npm run test && npm run build`
Expected: all clean; `App.routes.test` and `AppLayout.nav.test` pass with the new entries.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/features/ask-erp frontend/src/lib/adoptedModules.ts frontend/src/app/AppLayout.tsx frontend/src/app/AppLayout.nav.test.ts frontend/src/app/App.tsx frontend/src/app/App.routes.test.tsx
git commit -m "Ask ERP: the page, sidebar entry and route"
```

---

### Task 12: Live proof against the local database and hand-off

**Files:**
- Modify (docs only): `docs/superpowers/specs/2026-09-03-hrms-attendance-and-ask-erp-design.md` if any deviation happened; `DEPLOY.md` gets the three new env lines.

- [ ] **Step 1: Run the migration and a real question locally**

Copy the main checkout's SQLite file if the worktree has none (`cp /Users/newuser/Documents/production-erp/backend/database/database.sqlite backend/database/`), then `php artisan migrate`. With `ANTHROPIC_API_KEY` set in the shell (never written into a committed file), start `composer run dev` and `npm run dev`, log in as the seeded administrator, open Ask ERP, ask "how many employees by department" and "vendors with the most purchase orders". Record in the PR description: the question, the SQL the guard let through, and the row count. If no key is available, record that the page showed "Ask ERP is not configured on this server." and that the fake-writer feature tests are the proof.

- [ ] **Step 2: Deployment notes**

Append to `DEPLOY.md` under the env section: `ANTHROPIC_API_KEY`, `ASK_ERP_MODEL`, optionally `ASK_ERP_DB_CONNECTION=ask_erp` with `ASK_ERP_DB_USERNAME`/`ASK_ERP_DB_PASSWORD` for a MySQL user granted `SELECT` only; and that `php artisan schema:catalogue:generate` is re-run whenever a migration adds columns (the completeness test fails CI otherwise).

- [ ] **Step 3: Full verification**

Run: `cd backend && ./vendor/bin/pint --test && php artisan test` then `cd ../frontend && npm run typecheck && npm run test && npm run build`.
Expected: all clean.

- [ ] **Step 4: Commit and push; open a draft PR against `decisionm` main**

```bash
git add DEPLOY.md docs/superpowers
git commit -m "Ask ERP: deployment notes"
git push -u decisionm HEAD:claude/ask-erp-assistant
gh pr create --repo decisionm/production-erp --draft --base main --head claude/ask-erp-assistant --title "Ask ERP: schema catalogue and permission-aware natural-language queries" --body-file <(printf '...')
```
