<?php

namespace App\Modules\Assistant\Catalogue;

/**
 * One catalogue file: a business table, the module whose permission decides
 * who may query it, and enough plain English for a model to write SQL
 * against it without ever seeing the database.
 */
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
            purpose: trim((string) ($data['purpose'] ?? '')),
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
            'columns' => array_map(static fn (ColumnSpec $column) => $column->toArray(), $this->columns),
            'joins' => $this->joins,
            'keywords' => $this->keywords,
            'questions' => $this->questions,
        ];
    }

    /** @return list<string> */
    public function columnNames(): array
    {
        return array_map(static fn (ColumnSpec $column) => $column->name, $this->columns);
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

    /**
     * Tables named on the other side of this table's joins.
     *
     * @return list<string>
     */
    public function joinedTables(): array
    {
        $tables = [];
        foreach ($this->joins as $join) {
            preg_match_all('/([a-z_][a-z0-9_]*)\.[a-z_][a-z0-9_]*/i', $join, $matches);
            foreach ($matches[1] as $table) {
                if ($table !== $this->table) {
                    $tables[] = $table;
                }
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * Compact text for the model. Hidden columns are simply absent — a
     * column the reader may not see is not offered as something to ask for.
     *
     * @param  list<string>  $hiddenColumns
     */
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
