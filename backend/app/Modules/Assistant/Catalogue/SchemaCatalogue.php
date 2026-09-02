<?php

namespace App\Modules\Assistant\Catalogue;

use Symfony\Component\Yaml\Yaml;

/**
 * Every table the assistant knows about — one YAML file each under
 * resources/schema-catalogue. Loaded once per process (a singleton in
 * AssistantServiceProvider); a table with no file does not exist to the
 * assistant, which is the fail-closed default this whole feature rests on.
 */
final class SchemaCatalogue
{
    /** @param array<string, TableSpec> $specs keyed by table name */
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
        ksort($keyed);

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
