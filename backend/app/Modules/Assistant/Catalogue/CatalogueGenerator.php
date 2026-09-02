<?php

namespace App\Modules\Assistant\Catalogue;

use Illuminate\Support\Facades\Schema;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the live schema and writes one YAML per business table. A file that
 * exists is MERGED: its purpose, module, label, keywords, questions, joins
 * and every column's meaning/sensitive survive; columns the database has
 * gained are appended, columns it has lost are dropped. So the generator
 * can be re-run after every migration without a person losing a word.
 */
final class CatalogueGenerator
{
    /**
     * Laravel's and the packages' own tables — nothing a factory asks about
     * — and the assistant's own conversation log, which is one login's
     * questions and must not be readable through another login's questions.
     */
    public const array FRAMEWORK_TABLES = [
        'migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'sessions', 'password_reset_tokens', 'personal_access_tokens', 'activity_log',
        'permissions', 'roles', 'model_has_permissions', 'model_has_roles', 'role_has_permissions',
        'sqlite_sequence',
        'ask_erp_conversations', 'ask_erp_messages',
    ];

    /**
     * First guess at the owning module from the table name. Checked in
     * order; the hand annotation that follows corrects the misses. A table
     * matching nothing is 'unassigned', which the completeness test refuses.
     */
    public const array MODULE_BY_PREFIX = [
        'attendance' => 'hrms', 'employee' => 'hrms', 'leave_' => 'hrms',
        'payroll' => 'payroll', 'payslip' => 'payroll', 'salary_' => 'payroll',
        'purchase_' => 'procurement', 'vendor' => 'procurement', 'goods_receipt' => 'procurement',
        'grn_' => 'procurement', 'supplier_bill' => 'procurement',
        'sales_order' => 'sales', 'customer' => 'sales', 'deliver' => 'sales', 'invoice' => 'sales', 'quotation' => 'sales',
        'lead' => 'crm', 'opportunit' => 'crm',
        'gl_' => 'finance', 'journal_' => 'finance', 'ledger' => 'finance',
        'gst_' => 'compliance',
        'work_center' => 'machine-master',
        'shift' => 'production', 'batch' => 'production', 'work_order' => 'production',
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
        foreach (Schema::getForeignKeys($table) as $key) {
            if (count($key['columns']) === 1 && count($key['foreign_columns']) === 1) {
                $references[$key['columns'][0]] = $key['foreign_table'].'.'.$key['foreign_columns'][0];
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
                type: $this->simpleType((string) ($column['type_name'] ?? $column['type'] ?? 'string')),
                nullable: (bool) $column['nullable'],
                meaning: isset($prior['meaning']) && trim((string) $prior['meaning']) !== '' ? (string) $prior['meaning'] : null,
                references: $references[$column['name']] ?? ($prior['references'] ?? null),
                sensitive: isset($prior['sensitive']) ? (string) $prior['sensitive'] : null,
            );
        }

        return new TableSpec(
            table: $table,
            module: (string) ($existing['module'] ?? $this->guessModule($table)),
            label: (string) ($existing['label'] ?? ucwords(str_replace('_', ' ', $table))),
            purpose: (string) ($existing['purpose'] ?? ''),
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
            $type === 'tinyint(1)', str_contains($type, 'bool') => 'boolean',
            str_contains($type, 'int') => 'integer',
            str_contains($type, 'decimal'), str_contains($type, 'numeric') => 'decimal',
            str_contains($type, 'char'), str_contains($type, 'text'), $type === 'enum' => 'string',
            str_contains($type, 'datetime'), str_contains($type, 'timestamp') => 'datetime',
            $type === 'date' => 'date',
            $type === 'time' => 'time',
            str_contains($type, 'json') => 'json',
            str_contains($type, 'float'), str_contains($type, 'double') => 'float',
            default => $type,
        };
    }

    /**
     * @param  array<string, string>  $references
     * @return list<string>
     */
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
