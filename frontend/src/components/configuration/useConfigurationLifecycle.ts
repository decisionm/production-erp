import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { configurationInUse } from './configurationWords';
import type { ConfigurationAbilities, ConfigurationInUse } from './types';

/**
 * The three lifecycle calls of the Configuration Lifecycle Contract, wired
 * once for every configuration master.
 *
 *   DELETE /api/v1/<resource>/{id}          hard delete, refused unless proven unused
 *   POST   /api/v1/<resource>/{id}/archive  reversible, carries a reason
 *   POST   /api/v1/<resource>/{id}/activate reversible, carries a reason
 *
 * The verbs are the audit's (§2): DELETE copies the one working
 * implementation in the codebase (roles), while archive/activate stay POST
 * because they are reversible and carry a reason, which a DELETE verb cannot
 * express. Append-only surfaces — transactions, ledgers, posted documents —
 * are NOT configuration and are not reachable from here.
 *
 * The hook decides nothing about eligibility: it sends the request and hands
 * back what the server said. Whether a delete may even be attempted is the
 * server's `can` block, rendered by ConfigurationRowActions.
 */
export interface UseConfigurationLifecycleOptions {
    /** REST base for the resource, e.g. `production/molds`. Leading slash optional. */
    endpoint: string;
    /**
     * Every query key to refresh after a successful change. Passed by the page
     * because only the page knows which lists show this master.
     */
    invalidateKeys?: ReadonlyArray<readonly unknown[]>;
    onArchived?: () => void;
    onActivated?: () => void;
    onDeleted?: () => void;
}

export type ConfigurationId = number | string;

export interface ReasonedChange {
    id: ConfigurationId;
    /** Kept with the record. Archive and Reactivate are both explainable acts. */
    reason: string;
}

/** `show` returns the authoritative `can`; `index` rows carry `delete: null`. */
const abilitiesOf = (payload: unknown): ConfigurationAbilities | null => {
    const root = payload !== null && typeof payload === 'object' ? (payload as Record<string, unknown>) : {};
    const record = root.data !== null && typeof root.data === 'object' ? (root.data as Record<string, unknown>) : root;
    const can = record.can;
    if (can === null || typeof can !== 'object') return null;
    const flags = can as Record<string, unknown>;
    return {
        edit: flags.edit === true,
        activate: flags.activate === true,
        archive: flags.archive === true,
        // Anything that is not a boolean is UNDETERMINED, never "allowed".
        delete: typeof flags.delete === 'boolean' ? flags.delete : null,
    };
};

export function useConfigurationLifecycle(options: UseConfigurationLifecycleOptions) {
    const queryClient = useQueryClient();
    const base = `/${options.endpoint.replace(/^\/+|\/+$/g, '')}`;

    const invalidate = () => {
        for (const key of options.invalidateKeys ?? []) {
            queryClient.invalidateQueries({ queryKey: [...key] });
        }
    };

    const archive = useMutation({
        mutationFn: async ({ id, reason }: ReasonedChange) => {
            await api.post(`${base}/${id}/archive`, { reason });
        },
        onSuccess: () => {
            invalidate();
            options.onArchived?.();
        },
    });

    const activate = useMutation({
        mutationFn: async ({ id, reason }: ReasonedChange) => {
            await api.post(`${base}/${id}/activate`, { reason });
        },
        onSuccess: () => {
            invalidate();
            options.onActivated?.();
        },
    });

    const remove = useMutation({
        mutationFn: async ({ id }: { id: ConfigurationId }) => {
            await api.delete(`${base}/${id}`);
        },
        onSuccess: () => {
            invalidate();
            options.onDeleted?.();
        },
    });

    /**
     * The authoritative abilities for one record. An index row's `delete` is
     * `null` (undetermined) because a full sweep is 8–30 COUNTs per row, so
     * the confirm asks `show` before it offers the button.
     */
    const loadAbilities = async (id: ConfigurationId): Promise<ConfigurationAbilities | null> => {
        const { data } = await api.get(`${base}/${id}`);
        return abilitiesOf(data);
    };

    /** The in-use refusal of the last delete attempt, already parsed — or null. */
    const deleteRefusal: ConfigurationInUse | null = configurationInUse(remove.error);

    return {
        archive,
        activate,
        remove,
        loadAbilities,
        deleteRefusal,
        isBusy: archive.isPending || activate.isPending || remove.isPending,
    };
}
