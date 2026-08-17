<?php

namespace App\Support\Configuration;

use App\Exceptions\DomainException;
use RuntimeException;

/**
 * The ONE refusal a configuration delete may raise — 422 through the
 * existing DomainException renderer in bootstrap/app.php, exactly as
 * ProductNotReadyException does.
 *
 * It carries the reasons as DATA, not only as prose: `blocking` with
 * integer counts so the SPA can list them, `unprovable` for the reasons
 * that could not be counted at all (DEC-20260817-002 §5), `cascade_gaps`
 * for a cascading child the SCHEMA reported and the module's declaration
 * does not cover (that one is a defect in the declaration, not something
 * the user did — it names the table so it can be fixed), and `alternative`
 * naming what the user may do instead. The message says the
 * same thing in one sentence for anyone reading a log.
 *
 * Generalised from RoleInUseException, which has been doing exactly this
 * for one entity since Phase 0.
 */
class ConfigurationInUseException extends RuntimeException implements DomainException
{
    /**
     * @param  list<array{code: string, label: string, count: int}>  $blocking
     * @param  list<array{code: string, label: string}>  $unprovable
     * @param  list<array{table: string, column: string, reason: string, message: string}>  $cascadeGaps
     */
    private function __construct(
        string $message,
        private readonly array $blocking,
        private readonly array $unprovable,
        private readonly array $cascadeGaps,
    ) {
        parent::__construct($message);
    }

    public static function for(string $label, string $name, DependencyReport $report): self
    {
        return new self(
            sprintf('Cannot delete %s "%s" — %s. Deactivate instead.', $label, $name, $report->sentence()),
            $report->blocking(),
            $report->unprovable(),
            $report->cascadeGaps(),
        );
    }

    public function errorCode(): string
    {
        return 'configuration_in_use';
    }

    /**
     * Extra 422 body keys — see bootstrap/app.php's DomainException render.
     *
     * @return array{blocking: list<array{code: string, label: string, count: int}>, unprovable: list<array{code: string, label: string}>, cascade_gaps: list<array{table: string, column: string, reason: string, message: string}>, alternative: string}
     */
    public function payload(): array
    {
        return [
            'blocking' => $this->blocking,
            'unprovable' => $this->unprovable,
            'cascade_gaps' => $this->cascadeGaps,
            'alternative' => 'archive',
        ];
    }
}
