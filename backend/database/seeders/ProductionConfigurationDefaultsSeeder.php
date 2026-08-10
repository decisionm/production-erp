<?php

namespace Database\Seeders;

use App\Modules\Production\Models\DowntimeReason;
use App\Modules\Production\Models\FactorySetting;
use Illuminate\Database\Seeder;

/**
 * The factory's own System Config and Downtime Reason sheets, loaded as
 * editable rows.
 *
 * Every row carries the workbook's verbatim confirmation wording. "To
 * Confirm" values are still loaded — they are the factory's own starting
 * point and the UI shows the status beside them — but nothing here is a
 * production STANDARD: no cycle time, no cavity count, no product mapping.
 * Those live in production_configurations and only ever arrive as drafts.
 *
 * Idempotent: safe to re-run, and re-running never clobbers a value someone
 * has since edited (updateOrCreate on the descriptive columns only).
 */
class ProductionConfigurationDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->settings() as $setting) {
            $existing = FactorySetting::query()->where('key', $setting['key'])->first();

            if ($existing !== null) {
                // Refresh the labelling, never the value — a production
                // manager's edit outranks a redeploy.
                $existing->update([
                    'label' => $setting['label'],
                    'description' => $setting['description'],
                    'confirmation_status' => $setting['confirmation_status'],
                ]);

                continue;
            }

            FactorySetting::create($setting);
        }

        foreach ($this->downtimeReasons() as $reason) {
            DowntimeReason::query()->firstOrCreate(['code' => $reason['code']], $reason);
        }
    }

    /** @return list<array<string, mixed>> */
    private function settings(): array
    {
        return [
            [
                'key' => 'GLOBAL_CYCLE_TIME_MIN', 'value' => '8', 'data_type' => 'decimal', 'scope' => 'production',
                'label' => 'Global minimum cycle time (s)',
                'description' => 'Lowest cycle time normally selectable unless a machine-product configuration narrows it further.',
                'confirmation_status' => 'Discussion Confirmed',
            ],
            [
                'key' => 'GLOBAL_CYCLE_TIME_MAX', 'value' => '14', 'data_type' => 'decimal', 'scope' => 'production',
                'label' => 'Global maximum cycle time (s)',
                'description' => 'Highest normal cycle time; historical rows include valid exceptions above this, so an approved configuration may widen it.',
                'confirmation_status' => 'Discussion Confirmed',
            ],
            [
                'key' => 'DEFAULT_SHIFT_HOURS', 'value' => '8', 'data_type' => 'decimal', 'scope' => 'production',
                'label' => 'Default scheduled shift hours',
                'description' => 'Used when a shift carries no timing of its own.',
                'confirmation_status' => 'Discussion Confirmed',
            ],
            [
                'key' => 'MATERIAL_VARIANCE_PERCENT', 'value' => '0.01', 'data_type' => 'decimal', 'scope' => 'production',
                'label' => 'Material variance threshold (%)',
                'description' => 'Percentage threshold used together with the absolute kg threshold.',
                'confirmation_status' => 'To Confirm',
            ],
            [
                'key' => 'MATERIAL_VARIANCE_KG', 'value' => '1', 'data_type' => 'decimal', 'scope' => 'production',
                'label' => 'Material variance threshold (kg)',
                'description' => 'Absolute threshold. Review when either configured threshold is exceeded.',
                'confirmation_status' => 'To Confirm',
            ],
            [
                'key' => 'ALLOW_CYCLE_TIME_OVERRIDE', 'value' => 'true', 'data_type' => 'boolean', 'scope' => 'production',
                'label' => 'Allow cycle-time override at Start Batch',
                'description' => 'Lets an authorized shift user change the prefilled cycle time, within configured limits.',
                'confirmation_status' => 'Discussion Confirmed',
            ],
            [
                'key' => 'ALLOW_CAVITY_OVERRIDE', 'value' => 'true', 'data_type' => 'boolean', 'scope' => 'production',
                'label' => 'Allow cavity override at Start Batch',
                'description' => 'Lets an authorized shift user select an approved cavity option.',
                'confirmation_status' => 'Discussion Confirmed',
            ],
            [
                'key' => 'REQUIRE_OVERRIDE_REASON', 'value' => 'true', 'data_type' => 'boolean', 'scope' => 'production',
                'label' => 'Require a reason for every override',
                'description' => 'Every cycle, cavity, pieces or rejection override must carry a reason.',
                'confirmation_status' => 'Recommended',
            ],
            [
                'key' => 'PLANNED_DOWNTIME_AT_START', 'value' => 'true', 'data_type' => 'boolean', 'scope' => 'production',
                'label' => 'Allow planned downtime before Start Batch',
                'description' => 'Known mould change, planned outage and planned maintenance can be entered before the batch starts.',
                'confirmation_status' => 'Discussion Confirmed',
            ],
            [
                'key' => 'UNPLANNED_DOWNTIME_DURING_OR_END', 'value' => 'true', 'data_type' => 'boolean', 'scope' => 'production',
                'label' => 'Allow unplanned downtime during or at end of run',
                'description' => 'Emergency downtime can be added during the run or at shift completion.',
                'confirmation_status' => 'Discussion Confirmed',
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function downtimeReasons(): array
    {
        // Straight from the workbook's Downtime Reasons sheet, including its
        // "To Confirm" status — the planned/unplanned split is the factory's
        // to ratify, and every one of these is editable in the UI.
        return [
            ['code' => 'DT-MOULD', 'category' => 'Changeover', 'description' => 'Mould change', 'planning_type' => 'planned', 'reduces_runtime' => true, 'requires_note' => true, 'selectable_at_start' => true, 'confirmation_status' => 'To Confirm'],
            ['code' => 'DT-COLOUR', 'category' => 'Changeover', 'description' => 'Colour change', 'planning_type' => 'planned', 'reduces_runtime' => true, 'requires_note' => true, 'selectable_at_start' => true, 'confirmation_status' => 'To Confirm'],
            ['code' => 'DT-PLAN-MAINT', 'category' => 'Maintenance', 'description' => 'Planned maintenance', 'planning_type' => 'planned', 'reduces_runtime' => true, 'requires_note' => true, 'selectable_at_start' => true, 'confirmation_status' => 'To Confirm'],
            ['code' => 'DT-CLEAN', 'category' => 'Setup', 'description' => 'Cleaning/setup', 'planning_type' => 'planned', 'reduces_runtime' => true, 'requires_note' => false, 'selectable_at_start' => true, 'confirmation_status' => 'To Confirm'],
            ['code' => 'DT-POWER', 'category' => 'Utilities', 'description' => 'Power outage', 'planning_type' => 'unplanned', 'reduces_runtime' => true, 'requires_note' => true, 'selectable_at_start' => true, 'confirmation_status' => 'To Confirm'],
            ['code' => 'DT-BREAKDOWN', 'category' => 'Machine', 'description' => 'Machine breakdown', 'planning_type' => 'unplanned', 'reduces_runtime' => true, 'requires_note' => true, 'selectable_at_start' => false, 'confirmation_status' => 'To Confirm'],
            ['code' => 'DT-MATERIAL', 'category' => 'Material', 'description' => 'Material shortage', 'planning_type' => 'unplanned', 'reduces_runtime' => true, 'requires_note' => true, 'selectable_at_start' => false, 'confirmation_status' => 'To Confirm'],
            // The paper's most common idle cause — "given drying" appears on
            // the 07-Aug report against two machines at once (ASB-6/7,
            // 11:30–13:00). No note required: unlike "Other", the reason IS
            // the explanation, and demanding extra words for the most common
            // cause invites junk text on every row.
            ['code' => 'DT-DRYING', 'category' => 'Material', 'description' => 'Material drying', 'planning_type' => 'unplanned', 'reduces_runtime' => true, 'requires_note' => false, 'selectable_at_start' => false, 'confirmation_status' => 'To Confirm'],
            ['code' => 'DT-QUALITY', 'category' => 'Quality', 'description' => 'Quality hold', 'planning_type' => 'unplanned', 'reduces_runtime' => true, 'requires_note' => true, 'selectable_at_start' => false, 'confirmation_status' => 'To Confirm'],
            ['code' => 'DT-OPERATOR', 'category' => 'People', 'description' => 'Operator unavailable', 'planning_type' => 'unplanned', 'reduces_runtime' => true, 'requires_note' => true, 'selectable_at_start' => false, 'confirmation_status' => 'To Confirm'],
            ['code' => 'DT-OTHER', 'category' => 'Other', 'description' => 'Other downtime', 'planning_type' => 'unplanned', 'reduces_runtime' => true, 'requires_note' => true, 'selectable_at_start' => false, 'confirmation_status' => 'To Confirm'],
        ];
    }
}
