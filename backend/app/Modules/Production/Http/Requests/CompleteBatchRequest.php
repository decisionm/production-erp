<?php

namespace App\Modules\Production\Http\Requests;

use App\Modules\Production\Http\Requests\Concerns\ValidatesDowntimeEvents;
use App\Modules\Production\Models\ProductionStandardPackaging;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Production\Services\RunConsumableOptionsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * THE COMPLETION PAYLOAD — what the floor records when a batch ends. Every
 * field is named here for what it IS, because the same words are read by
 * the completion drawer, the amend drawer (AmendBatchRequest inherits every
 * rule), the metrics and the Tally voucher, and a field understood two ways
 * on one screen is how a 490/box run came to be measured against 520.
 *
 *   quantity_produced   the PIECES this run produced — the count every
 *                       ledger, kg conversion (at the run's frozen unit
 *                       weight) and voucher line reads. Not cartons, not kg.
 *   quantity_scrap      pieces rejected on the floor (converted to kg the
 *                       same way); qc_rejection_kg is quality's own figure.
 *   nos_per_box         the pieces in the carton ACTUALLY PACKED on this
 *                       run — what the supervisor packed at, which is the
 *                       standard's count on an ordinary run and a
 *                       different one when a run packs short or to a
 *                       customer's carton. Recorded, never inferred, so the
 *                       metrics measure the run at the count it was packed
 *                       at (PackQuantityResolver reads it first).
 *   no_of_box           the cartons packed on this run — the OUTER package
 *                       in every mode; equal to the packing lines' carton
 *                       total when lines are sent.
 *   nos_per_tray /      the inner containers, per the mode the run packed
 *   no_of_trays,        in: pieces per tray and trays packed, pieces per
 *   nos_per_pouch /     pouch and pouches packed. Absent for a mode the
 *   no_of_pouches       run did not use.
 *   loose_pieces        pieces left in no container at all — batch-level,
 *                       because a stray piece belongs to no packing mode.
 *   packing_lines       how the pieces were packed, one line per mode (see
 *                       the rules below); stored line for line.
 *   actual_cycle_time / the run's own figures — a completion may correct
 *   active_cavities     what Start recorded (an absent key keeps it, an
 *                       explicit null clears it). standard_* are Start
 *                       Batch snapshots and are NOT accepted here.
 *   running_hours       hours the machine actually ran (≤ 24); the
 *                       expected-output engine's denominator, net of the
 *                       downtime_events recorded below.
 *   material_consumptions / closing_day_bin / scraps / downtime_events —
 *                       what the run consumed, what the bin held at close,
 *                       what was scrapped, what stopped the machine.
 *
 * The RULES are the contract every existing completion was validated
 * against and are unchanged by this naming (PackingLinesTest,
 * PackingLinesPersistTest); attributes() below is only the name a refusal
 * calls each field by.
 */
class CompleteBatchRequest extends FormRequest
{
    use ValidatesDowntimeEvents;

    /**
     * How much two piece counts may differ and still be called equal.
     * quantity_produced — the pieces produced — arrives as a numeric string
     * (the drawer sends the count as typed, with or without a decimal part)
     * while line pieces are integers; a strict comparison would fail on the
     * representation rather than on the arithmetic.
     */
    private const PIECE_EPSILON = 0.001;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'batch_number' => ['nullable', 'string', 'max:64'],
            // Pieces produced — see the class docblock for every count's meaning.
            'quantity_produced' => ['required', 'numeric', 'gt:0'],
            'quantity_scrap' => ['nullable', 'numeric', 'gte:0'],
            // WS-B (audit 17-Aug-2026): a WITHDRAWN scrap reason was
            // selectable on the floor — the completion path used a bare
            // `exists:`. Completed batches keep the reason they recorded.
            'scrap_reason_id' => ['nullable', 'integer', Rule::exists('scrap_reasons', 'id')->where('is_active', true)],
            // The pack counts THIS run packed at, and how many of each were
            // packed. nos_per_box is the carton actually packed on this run.
            'nos_per_tray' => ['nullable', 'integer', 'min:0'],
            'no_of_trays' => ['nullable', 'integer', 'min:0'],
            'nos_per_box' => ['nullable', 'integer', 'min:0'],
            'no_of_box' => ['nullable', 'integer', 'min:0'],
            // Wave A packaging: pouch count (pouch-packed products) and
            // loose pieces left after filling whole containers — previously
            // a frontend-only derivation helper, now persisted.
            'no_of_pouches' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'nos_per_pouch' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'loose_pieces' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'helper_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string'],

            // Expected-output engine inputs. standard_cycle_time /
            // standard_cavities are deliberately NOT in these rules — they
            // were snapshotted from the item master at Start Batch and are
            // never writable through any request after; validated() strips
            // any attempt to send them.
            'actual_cycle_time' => ['sometimes', 'nullable', 'numeric', 'min:0.1', 'max:9999.99'],
            'active_cavities' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'running_hours' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:24'],
            'qc_rejection_kg' => ['sometimes', 'nullable', 'numeric', 'gte:0'],

            'material_consumptions' => ['nullable', 'array'],
            'material_consumptions.*.item_id' => ['required', 'integer', 'exists:items,id'],
            // OPTIONAL for the same reason warehouse_id is on StartBatchRequest:
            // nobody on the floor is asked which store the resin, masterbatch
            // or packing film came out of. Absent or null lets the service
            // resolve it per line from where the material actually is (day bin
            // vs store) — see FactoryWarehouseResolver::consumptionSource().
            'material_consumptions.*.warehouse_id' => ['sometimes', 'nullable', 'integer', 'exists:warehouses,id'],
            'material_consumptions.*.quantity_issued_kg' => ['required', 'numeric', 'gt:0'],
            // WHY THIS RUN CONSUMED SOMETHING IT WAS NOT PLANNED ON. Required
            // only for a line naming a material outside the run's expected
            // set — see assertConsumptionsAreOnTheList(), which is where the
            // list and the authority both live. A blank string is not a
            // reason and is refused as an absent one.
            'material_consumptions.*.added_reason' => ['sometimes', 'nullable', 'string', 'max:255'],

            // Day-bin closing weight per material, same contract as
            // HandoverRequest. This is what makes automatic consumption
            // (opening + loaded − closing − returned) computable on a
            // normal completion instead of only on a handover.
            'closing_day_bin' => ['nullable', 'array'],
            'closing_day_bin.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'closing_day_bin.*.quantity_kg' => ['required', 'numeric', 'gte:0'],

            'scraps' => ['nullable', 'array'],
            'scraps.*.type' => ['required', Rule::in(['rejected_finished_good', 'lumps'])],
            'scraps.*.quantity_nos' => ['nullable', 'numeric', 'gte:0'],
            'scraps.*.quantity_kg' => ['nullable', 'numeric', 'gte:0'],
            'scraps.*.scrap_reason_id' => ['nullable', 'integer', Rule::exists('scrap_reasons', 'id')->where('is_active', true)],

            // Downtime logged with the completion (owner, 30-Jul: "power
            // outage and mold change they need add with timing … i want to
            // do this for efficiency"). Lines persist to
            // production_downtime_events and their minutes net out of
            // running hours in productionMetrics(). Shape and cross-checks
            // live in ValidatesDowntimeEvents, shared with HandoverRequest.
            ...$this->downtimeEventRules('downtime_events'),

            // Multi-mode packing lines. A product's standard exposes only the
            // packaging modes its Excel row actually carries, and a run may
            // genuinely use more than one of them (part of the shift packed
            // in trays, part in pouches) — so packing is a LIST, one line per
            // mode, not a single set of tray/pouch columns.
            //
            // The carton/box is the OUTER package in every mode; tray and
            // pouch are the inner ones. Pieces therefore derive as
            //     boxes × nos_per_box + loose_inner × nos_per_inner
            // straight from the imported pack sizes — never from
            // trays_per_box/pouches_per_box, and never from an assumed 5.
            'packing_lines' => ['sometimes', 'nullable', 'array'],
            'packing_lines.*.mode' => ['required', Rule::in([
                ProductionStandardPackaging::MODE_POUCH,
                ProductionStandardPackaging::MODE_TRAY,
                ProductionStandardPackaging::MODE_DIRECT_BOX,
            ])],
            'packing_lines.*.production_standard_packaging_id' => [
                'nullable', 'integer', 'exists:production_standard_packagings,id',
            ],
            'packing_lines.*.boxes' => ['required', 'integer', 'min:0'],
            'packing_lines.*.nos_per_box' => ['required', 'integer', 'min:1'],
            // Inner containers not yet packed into a box (loose trays /
            // loose pouches). Direct-box lines have no inner container.
            'packing_lines.*.loose_inner' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'packing_lines.*.nos_per_inner' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'packing_lines.*.derived_pieces' => ['required', 'integer', 'min:0'],
            // Editable: the counted figure may differ from the derived one
            // (a short box, a miscount) — but then it needs a reason.
            'packing_lines.*.actual_pieces' => ['required', 'integer', 'min:0'],
            'packing_lines.*.override_reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The name a refusal calls each field by — what the field IS, so a 422
     * reads "the pieces produced must be greater than 0", never a column
     * name a supervisor has to decode. Names only: no rule changes here.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'quantity_produced' => 'pieces produced',
            'quantity_scrap' => 'pieces rejected',
            'nos_per_box' => 'pieces per box actually packed',
            'no_of_box' => 'cartons packed',
            'nos_per_tray' => 'pieces per tray',
            'no_of_trays' => 'trays packed',
            'nos_per_pouch' => 'pieces per pouch',
            'no_of_pouches' => 'pouches packed',
            'loose_pieces' => 'loose pieces',
            'actual_cycle_time' => 'actual cycle time',
            'active_cavities' => 'active cavities',
            'running_hours' => 'running hours',
            'qc_rejection_kg' => 'QC rejection kg',
            'packing_lines.*.boxes' => 'cartons on this line',
            'packing_lines.*.nos_per_box' => 'pieces per box on this line',
            'packing_lines.*.loose_inner' => 'loose inner containers on this line',
            'packing_lines.*.nos_per_inner' => 'pieces per inner container on this line',
            'packing_lines.*.derived_pieces' => 'derived pieces on this line',
            'packing_lines.*.actual_pieces' => 'counted pieces on this line',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $lines = $this->input('packing_lines');
            if (is_array($lines) && $lines !== []) {
                $this->validatePackingLines($validator, $lines);
            }

            $downtime = $this->input('downtime_events');
            if (is_array($downtime) && $downtime !== []) {
                $this->validateDowntimeEvents($validator, $downtime, 'downtime_events');
            }

            $consumptions = $this->input('material_consumptions');
            if (is_array($consumptions) && $consumptions !== []) {
                $this->assertConsumptionsAreOnTheList($validator, $consumptions);
            }
        });
    }

    /**
     * A CONSUMPTION LINE NAMES A MATERIAL FROM THE LIST, OR IT IS REFUSED.
     *
     * `exists:items,id` was the only guard here until now, which is to say
     * there was none: a finished bottle, a scrap grade or a spare part could
     * be booked as this run's own input and carried straight onto a Tally
     * Stock Journal as one. RunConsumableOptionsService holds the two sets
     * and the reasoning behind them; this method is only the refusal.
     *
     * Three outcomes per line:
     *   · EXPECTED material — an ordinary line, nothing more is asked.
     *   · ALLOWED but not expected — an ADDED line. It needs a reason, and the
     *     person sending it needs `consumption-substitute.manage`. Refused
     *     without either, so a substitution can never happen quietly.
     *   · outside ALLOWED — refused outright, naming the row.
     *
     * ADDITIVE, ALWAYS. Nothing here (and nothing in the service behind it)
     * reduces, clears or reassigns the line the added material stood in for.
     * Both lines stand on the batch and both reach the voucher exactly as
     * recorded — the owner's answer, 01-Sep-2026. One material silently
     * standing in for another is the failure this whole path exists to
     * prevent.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function assertConsumptionsAreOnTheList(Validator $validator, array $lines): void
    {
        $entry = $this->route('shift_production_entry');

        if (! $entry instanceof ShiftProductionEntry) {
            return;
        }

        $options = app(RunConsumableOptionsService::class);
        $allowed = array_flip($options->allowedItemIds($entry));
        $expectedIds = $options->expectedItemIds($entry);
        $expected = array_flip($expectedIds);

        // NOTHING PLANNED MEANS NOTHING TO DEPART FROM.
        //
        // A run with no BOM, no standard and no resolvable resin has an EMPTY
        // expected set — and that is ordinary, not broken: the suggesters
        // return null rather than guess between two candidates, and plenty of
        // this catalogue's products carry no BOM at all. Reading every line of
        // such a run as a substitution would demand a reason and a special
        // permission for the resin the shift obviously ran on, and the floor
        // would learn to type "n/a" into the box — which is worse than not
        // asking, because then a real substitution reads like every other line.
        //
        // The list itself is still enforced below: a spare part or a finished
        // bottle is refused here whether or not anything was planned. Only the
        // reason-and-authority half stands down.
        $planned = $expectedIds !== [];

        // Asked ONCE, not per line: the answer cannot differ between rows of
        // one request, and a permission check inside a loop is a query inside
        // a loop.
        $mayAdd = (bool) $this->user()?->can('consumption-substitute.manage');

        foreach ($lines as $index => $line) {
            $itemId = (int) ($line['item_id'] ?? 0);

            if ($itemId <= 0 || isset($expected[$itemId])) {
                continue;
            }

            if (! isset($allowed[$itemId])) {
                $validator->errors()->add(
                    "material_consumptions.{$index}.item_id",
                    'This is not a material a production run may consume. Pick one from the materials list.',
                );

                continue;
            }

            if (! $planned) {
                continue;
            }

            if (trim((string) ($line['added_reason'] ?? '')) === '') {
                $validator->errors()->add(
                    "material_consumptions.{$index}.added_reason",
                    'This run was not planned to consume this material. Say why it did — the material it '
                    .'stood in for stays on the batch either way.',
                );
            }

            if (! $mayAdd) {
                $validator->errors()->add(
                    "material_consumptions.{$index}.item_id",
                    'Adding a material this run was not planned on needs an authorised user. Ask a plant '
                    .'manager or the office to record this line.',
                );
            }
        }
    }

    /**
     * Cross-line rules. Every message names the fix, not just the fault —
     * a supervisor holding a clipboard has to know what to change, and the
     * SPA keeps every entered value on a 422 so nothing is retyped.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function validatePackingLines(Validator $validator, array $lines): void
    {
        $entry = $this->route('shift_production_entry');
        $standardId = $entry instanceof ShiftProductionEntry ? $entry->production_standard_id : null;

        $seenModes = [];
        $totalBoxes = 0;
        $totalActual = 0;

        foreach ($lines as $index => $line) {
            $mode = $line['mode'] ?? null;
            $boxes = (int) ($line['boxes'] ?? 0);
            $nosPerBox = (int) ($line['nos_per_box'] ?? 0);
            $looseInner = (int) ($line['loose_inner'] ?? 0);
            $nosPerInner = (int) ($line['nos_per_inner'] ?? 0);
            $derived = (int) ($line['derived_pieces'] ?? 0);
            $actual = (int) ($line['actual_pieces'] ?? 0);

            $totalBoxes += $boxes;
            $totalActual += $actual;

            // One line per mode. Two tray lines are either a duplicate or an
            // attempt to count the same cartons twice; either way the total
            // would be wrong and nobody could tell which line was real.
            if ($mode !== null) {
                if (isset($seenModes[$mode])) {
                    $validator->errors()->add(
                        "packing_lines.{$index}.mode",
                        "This run already has a {$mode} line. Put all {$mode} cartons on that one line instead of adding a second.",
                    );
                } else {
                    $seenModes[$mode] = $index;
                }
            }

            // A line may only cite a packaging option belonging to the
            // standard this batch actually started against — the server half
            // of "never offer a mode the product does not have".
            $packagingId = $line['production_standard_packaging_id'] ?? null;
            if ($packagingId !== null && $standardId !== null) {
                $packaging = ProductionStandardPackaging::query()->find($packagingId);
                if ($packaging !== null && (int) $packaging->production_standard_id !== (int) $standardId) {
                    $validator->errors()->add(
                        "packing_lines.{$index}.production_standard_packaging_id",
                        'That packing option belongs to a different product standard. Pick one of the modes offered for this batch.',
                    );
                }
            }

            // Loose inners are, by definition, the ones NOT yet in a carton.
            // A full carton's worth of them is therefore a carton nobody
            // counted: the piece total still comes out right (that arithmetic
            // is mode-blind), but no_of_box understates, and with it the
            // master boxes this run consumed. 7 loose trays at 5 trays/carton
            // is 1 carton + 2, and it has to be entered that way.
            //
            // Only checkable when the line's own pack sizes say how many
            // inners make a carton — nos_per_inner is absent on direct-box
            // lines and on legacy payloads, and a carton that is not a whole
            // number of inners (perBox % perInner) states no such figure.
            // The modulo is guarded, never reached with a zero divisor.
            if ($looseInner > 0 && $nosPerInner > 0 && $nosPerBox > 0 && $nosPerBox % $nosPerInner === 0) {
                $innersPerCarton = intdiv($nosPerBox, $nosPerInner);
                if ($innersPerCarton >= 1 && $looseInner >= $innersPerCarton) {
                    // "a full carton or more", not "more than a full carton":
                    // the rule fires at exactly one carton's worth too, and a
                    // message that is false at its own boundary is the kind of
                    // sentence this screen exists to stop printing.
                    $noun = match ($mode) {
                        ProductionStandardPackaging::MODE_POUCH => 'pouches',
                        ProductionStandardPackaging::MODE_TRAY => 'trays',
                        default => 'inner containers',
                    };
                    $validator->errors()->add(
                        "packing_lines.{$index}.loose_inner",
                        "{$looseInner} loose {$noun} is a full carton or more ({$innersPerCarton} {$noun}/carton) — count the full cartons as cartons.",
                    );
                }
            }

            // The derived figure is recomputed here rather than trusted:
            // otherwise a client could send derived == actual and slip an
            // unexplained override past the reason requirement.
            $recomputed = $boxes * $nosPerBox + $looseInner * $nosPerInner;
            if ($recomputed !== $derived) {
                $validator->errors()->add(
                    "packing_lines.{$index}.derived_pieces",
                    "Derived pieces should be {$recomputed} (boxes × pcs/box + loose inner × pcs/inner), not {$derived}. Re-check the pack sizes on this line.",
                );
            }

            if ($actual !== $derived && trim((string) ($line['override_reason'] ?? '')) === '') {
                $validator->errors()->add(
                    "packing_lines.{$index}.override_reason",
                    "Counted {$actual} pieces but the pack sizes give {$derived}. Say why they differ (short box, miscount, part carton) or correct the count.",
                );
            }
        }

        // The outer carton count must be stated ONCE and add up. Without
        // this, two modes each claiming the same 10 boxes would post 20
        // cartons' worth of production off 10 physical cartons.
        $noOfBox = $this->input('no_of_box');
        if ($noOfBox === null || $noOfBox === '') {
            $validator->errors()->add(
                'no_of_box',
                "Enter the total number of cartons for this batch — it must equal the {$totalBoxes} across the packing lines.",
            );
        } elseif ((int) $noOfBox !== $totalBoxes) {
            $validator->errors()->add(
                'no_of_box',
                "The packing lines add up to {$totalBoxes} cartons but the batch total says {$noOfBox}. Every carton belongs to exactly one mode — split them, don't count the same cartons under both.",
            );
        }

        // Total pieces = sum of the lines, plus pieces loose in no container
        // at all. loose_pieces is deliberately batch-level (not per line):
        // a stray piece belongs to no packing mode.
        $expectedTotal = $totalActual + (int) ($this->input('loose_pieces') ?? 0);
        $quantityProduced = (float) $this->input('quantity_produced');

        if (abs($quantityProduced - $expectedTotal) > self::PIECE_EPSILON) {
            $validator->errors()->add(
                'quantity_produced',
                "Quantity produced must be the packing lines' {$totalActual} pieces plus any loose pieces — that is {$expectedTotal}, not {$quantityProduced}. Correct a line count or the loose pieces.",
            );
        }
    }
}
