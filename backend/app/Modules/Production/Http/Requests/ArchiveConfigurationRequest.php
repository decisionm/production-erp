<?php

namespace App\Modules\Production\Http\Requests;

use App\Support\Configuration\Http\ConfigurationReasonRequest;

/**
 * The Production module's name for the Configuration Lifecycle Contract's
 * archive/activate body — an ALIAS, not a second rule set.
 *
 * Two workstreams reached for the same FormRequest in the same wave and
 * named it differently: the shared mechanism ships
 * `App\Support\Configuration\Http\ConfigurationReasonRequest`, and the
 * floor's master controllers type-hint this. Rather than leave two
 * definitions of "reason is optional, string, capped" to drift apart, this
 * one extends the shared class and adds nothing at all — every rule, and
 * `reason()`, comes from there.
 *
 * INTEGRATOR NOTE: this file exists only so the two namings agree today.
 * Retyping the five floor controllers onto ConfigurationReasonRequest and
 * deleting this class is a pure rename with no behaviour in it.
 */
class ArchiveConfigurationRequest extends ConfigurationReasonRequest {}
