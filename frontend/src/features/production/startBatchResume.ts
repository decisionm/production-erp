import { z } from 'zod';

const FLOW = 'start-batch-recipe' as const;
export const START_BATCH_ROUTE = '/production/shift-production';

const positiveInteger = z.coerce.number().int().positive();
const optionalPositiveInteger = z.preprocess(
    (value) => (value === null || value === undefined || value === '' ? undefined : value),
    positiveInteger.optional(),
);
const optionalText = z.preprocess(
    (value) => (value === null || value === undefined || value === '' ? undefined : value),
    z.string().trim().min(1).max(120).optional(),
);
const optionalOutcome = z.preprocess(
    (value) => (value === null || value === undefined || value === '' ? undefined : value),
    z.enum(['created', 'cancelled']).optional(),
);

const draftSchema = z
    .object({
        machine_id: positiveInteger,
        shift_id: positiveInteger,
        production_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
        item_id: positiveInteger,
        // The finished-goods store is resolved by the screen, never chosen by
        // the supervisor — so it rides along only when the resolution had an
        // answer. A missing store must not lock the Configure door: the drawer
        // re-resolves it on return exactly as it does on a fresh open.
        warehouse_id: optionalPositiveInteger,
        operator_id: optionalPositiveInteger,
        active_cavities: optionalPositiveInteger,
        standard_id: optionalPositiveInteger,
        packaging_id: optionalPositiveInteger,
        colour: optionalText,
    })
    .superRefine((draft, ctx) => {
        if (draft.packaging_id !== undefined && draft.standard_id === undefined) {
            ctx.addIssue({
                code: 'custom',
                path: ['packaging_id'],
                message: 'A packaging choice must belong to a selected production standard.',
            });
        }
    });

const querySchema = draftSchema.and(
    z.object({
        flow: z.literal(FLOW),
        phase: z.enum(['configure', 'resume']),
        outcome: optionalOutcome,
    }),
).superRefine((query, ctx) => {
    if (query.phase === 'configure' && query.outcome !== undefined) {
        ctx.addIssue({
            code: 'custom',
            path: ['outcome'],
            message: 'Only a return to Start Batch may carry a recipe outcome.',
        });
    }
});

export type StartBatchResumeDraft = z.infer<typeof draftSchema>;
export type StartBatchResumePhase = 'configure' | 'resume';
export type StartBatchResumeOutcome = 'created' | 'cancelled';
export interface ParsedStartBatchResume {
    phase: StartBatchResumePhase;
    outcome?: StartBatchResumeOutcome;
    draft: StartBatchResumeDraft;
}

function queryObject(params: URLSearchParams): Record<string, string | null> {
    return {
        flow: params.get('flow'),
        phase: params.get('phase'),
        outcome: params.get('outcome'),
        machine_id: params.get('machine_id'),
        shift_id: params.get('shift_id'),
        production_date: params.get('production_date'),
        item_id: params.get('item_id'),
        warehouse_id: params.get('warehouse_id'),
        operator_id: params.get('operator_id'),
        active_cavities: params.get('active_cavities'),
        standard_id: params.get('standard_id'),
        packaging_id: params.get('packaging_id'),
        colour: params.get('colour'),
    };
}

/** Parse only the allowlisted scalar context; arbitrary return URLs are never accepted. */
export function parseStartBatchResume(params: URLSearchParams): ParsedStartBatchResume | null {
    if (params.get('flow') !== FLOW) return null;
    const parsed = querySchema.safeParse(queryObject(params));
    if (!parsed.success) return null;
    const { phase, outcome, flow: _flow, ...draft } = parsed.data;
    return { phase, outcome, draft };
}

export function hasStartBatchResume(params: URLSearchParams, phase: StartBatchResumePhase): boolean {
    return params.get('flow') === FLOW && params.get('phase') === phase;
}

export function encodeStartBatchResume(
    draft: StartBatchResumeDraft,
    phase: StartBatchResumePhase,
    outcome?: StartBatchResumeOutcome,
): URLSearchParams {
    const valid = draftSchema.parse(draft);
    const params = new URLSearchParams({
        flow: FLOW,
        phase,
        machine_id: String(valid.machine_id),
        shift_id: String(valid.shift_id),
        production_date: valid.production_date,
        item_id: String(valid.item_id),
    });

    if (outcome !== undefined) params.set('outcome', outcome);
    if (valid.warehouse_id !== undefined) params.set('warehouse_id', String(valid.warehouse_id));
    if (valid.operator_id !== undefined) params.set('operator_id', String(valid.operator_id));
    if (valid.active_cavities !== undefined) params.set('active_cavities', String(valid.active_cavities));
    if (valid.standard_id !== undefined) params.set('standard_id', String(valid.standard_id));
    if (valid.packaging_id !== undefined) params.set('packaging_id', String(valid.packaging_id));
    if (valid.colour !== undefined) params.set('colour', valid.colour);

    return params;
}

export function buildStartBatchRecipeUrl(draft: StartBatchResumeDraft): string {
    return `/production/boms?${encodeStartBatchResume(draft, 'configure').toString()}`;
}

/**
 * The other configuration door, and the one the readiness gate actually sends
 * people through.
 *
 * WHY THE PRODUCT STANDARD AND NOT THE RECIPE. Every finding that refuses a
 * start names one of four figures — weight, cycle time, cavities, pieces per
 * box — or the product's Tally identity, and the Product Standards page is
 * where all five are written (New product standard writes the four; Attach
 * item resolves the fifth). A missing consumption recipe is deliberately not a
 * finding at all — see ProductReadinessService, which says so and why — so the
 * recipe page is not where a blocked start gets unblocked.
 *
 * Same allowlisted `phase=configure` query as the recipe leg, so the return
 * trip and its validation are shared and there is exactly one encoding of
 * "which Start Batch was this".
 */
export function buildStartBatchStandardUrl(draft: StartBatchResumeDraft): string {
    // Product Standards is a tab of Production Configuration now. The old
    // /production/standards URL still redirects here carrying its query
    // string, but a blocked Start Batch is the one trip that must not depend
    // on a redirect surviving — so it names the real destination directly.
    return `/production/configuration?tab=products&${encodeStartBatchResume(draft, 'configure').toString()}`;
}

export function buildStartBatchReturnUrl(
    draft: StartBatchResumeDraft,
    outcome: StartBatchResumeOutcome,
): string {
    return `${START_BATCH_ROUTE}?${encodeStartBatchResume(draft, 'resume', outcome).toString()}`;
}
