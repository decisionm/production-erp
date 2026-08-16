import Store from 'electron-store';

/**
 * The stock read's poison list — item GUIDs whose single-item balance fetch
 * TIMED OUT and (by the 07-Aug pattern) most likely left Tally wedged.
 *
 * Persisted on disk, unlike the in-memory resume state, because the sequence
 * that needs it is exactly the one where the agent gets restarted: item hangs
 * Tally → operator restarts Tally (and often the machine) → runs the read
 * again. Without persistence the same item would hang the same Tally on
 * every attempt, forever. With it, each run either completes or names and
 * blacklists exactly one more culprit — the run count is bounded by the
 * number of poisoned items, not by anyone's patience.
 *
 * An entry here is a DIAGNOSIS, not a deletion: the item is skipped out loud
 * on every later run, counted in the coverage warning, and stays skipped
 * until a developer clears this file after fixing whatever is wrong with the
 * item's data in Tally (file: stock-read-state.json in the agent's app-data
 * folder, next to the main config).
 */

export interface PoisonedItem {
    name: string;
    company: string;
    /** ISO timestamp of the run that blacklisted it. */
    at: string;
}

interface StockReadState {
    poisoned: Record<string, PoisonedItem>;
}

const store = new Store<StockReadState>({
    name: 'stock-read-state',
    defaults: { poisoned: {} },
});

export function poisonedItems(): Record<string, PoisonedItem> {
    return store.get('poisoned');
}

export function poisonItem(guid: string, name: string, company: string): void {
    const poisoned = store.get('poisoned');
    poisoned[guid] = { name, company, at: new Date().toISOString() };
    store.set('poisoned', poisoned);
}
