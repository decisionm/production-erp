<?php

namespace App\Modules\Assistant\Services;

/**
 * Whether the provider named by ASK_ERP_DRIVER actually has what it needs to
 * answer. The page asks this to decide whether to offer the input at all.
 *
 * It exists as a free function rather than a method on SqlWriter on purpose:
 * SqlWriter is faked by an anonymous class in three test suites, and widening
 * the interface to answer a question about configuration would force every
 * one of those fakes to implement something they do not care about.
 *
 * Before the second driver arrived the controller asked this by reading the
 * Anthropic key directly. That answer becomes wrong the moment the driver is
 * openai — the server would be perfectly able to answer while telling the
 * user it was not configured.
 */
final class ProviderStatus
{
    public static function configured(): bool
    {
        return match ((string) config('ask-erp.driver')) {
            'anthropic' => (string) config('ask-erp.api_key') !== '',
            'openai' => (string) config('ask-erp.openai.api_key') !== '',
            // Always ready: the rule set needs no credential to configure and
            // no balance to stay working.
            'rules' => true,
            default => false,
        };
    }
}
