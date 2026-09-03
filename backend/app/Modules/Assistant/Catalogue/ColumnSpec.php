<?php

namespace App\Modules\Assistant\Catalogue;

/**
 * One column of a catalogue table: what the database says it is (name,
 * type, nullability, foreign key) plus what a person wrote about it
 * (meaning, sensitivity).
 */
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
            meaning: isset($row['meaning']) && trim((string) $row['meaning']) !== '' ? (string) $row['meaning'] : null,
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
        ], static fn ($value) => $value !== null);
    }

    public function renderLine(): string
    {
        $line = $this->name.' '.$this->type.($this->nullable ? ' nullable' : '');
        if ($this->references !== null) {
            $line .= ' → '.$this->references;
        }
        if ($this->meaning !== null) {
            $line .= ' — '.$this->meaning;
        }

        return $line;
    }
}
