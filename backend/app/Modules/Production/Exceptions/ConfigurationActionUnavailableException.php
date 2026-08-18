<?php

namespace App\Modules\Production\Exceptions;

use App\Exceptions\DomainException;
use RuntimeException;

/**
 * A stale button, answered as a business refusal instead of a crash.
 *
 * ConfigurationLifecycle enforces its own `can` block: archive() on an
 * already-retired record, or activate() on one already in service, throws a
 * LogicException — which is right for a mechanism (it is a programming
 * error to call an action the server said was unavailable), but a plain
 * LogicException does not implement DomainException, so over HTTP it renders
 * as a 500. And this IS reachable by an ordinary user: two supervisors on
 * the same master screen, or one stale tab, is a double Archive.
 *
 * So the controller asks the mechanism's OWN abilities() first and renders
 * this 422 when the action is not offered. Nothing here decides anything —
 * the verdict is still the mechanism's, and the mechanism still enforces it
 * behind this (a genuine race that slips through stays a 500, which is the
 * honest answer for something that should not be reachable).
 *
 * `alternative` names what the user can actually do instead, so the SPA can
 * offer it rather than printing a dead end — the same shape
 * ConfigurationInUseException uses for the delete refusal.
 */
class ConfigurationActionUnavailableException extends RuntimeException implements DomainException
{
    private function __construct(string $message, private readonly string $action, private readonly string $alternative)
    {
        parent::__construct($message);
    }

    public static function archive(string $label): self
    {
        return new self(
            sprintf('This %s is already out of service, so there is nothing to archive. Refresh to see its current state.', $label),
            'archive',
            'activate',
        );
    }

    public static function activate(string $label): self
    {
        return new self(
            sprintf('This %s is already in service, so there is nothing to reactivate. Refresh to see its current state.', $label),
            'activate',
            'archive',
        );
    }

    public function errorCode(): string
    {
        return 'configuration_action_unavailable';
    }

    /** @return array{action: string, alternative: string} */
    public function payload(): array
    {
        return ['action' => $this->action, 'alternative' => $this->alternative];
    }
}
