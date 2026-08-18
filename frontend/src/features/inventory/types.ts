export type ItemTrackingType = 'none' | 'batch' | 'serial';

export interface Item {
    id: number;
    sku: string;
    name: string;
    description: string | null;
    uom: string;
    hsn_sac_code: string | null;
    reorder_level: string;
    nominal_weight_grams: string | null;
    /** Product packing master — null until the standards data load arrives. */
    nos_per_tray: number | null;
    trays_per_box: number | null;
    nos_per_box: number | null;
    /** Pouch packing standards (Wave A) — null for items not pouch-packed. */
    nos_per_pouch: number | null;
    pouches_per_box: number | null;
    /** Product colour (drives masterbatch suggestion); "Clear" means no MB. */
    colour: string | null;
    /** Standard cycle time, seconds per shot — decimal string e.g. "10.60". */
    standard_cycle_time: string | null;
    /** Standard cavity count of the item's mold. */
    standard_cavities: number | null;
    tracking_type: ItemTrackingType;
    /**
     * The Tally stock item this product IS, once Tally has synced it. Present
     * means vouchers naming this product will post; absent means production can
     * still run and only the voucher waits — the "Tally mapping pending" state.
     */
    tally_stock_item_guid: string | null;
    /**
     * Phase 5 (P5-02): TRUE while the SKU is the name-derived one the Tally
     * pull seeded and no person has set it; a manual SKU edit clears it.
     * Optional — absent on a backend that predates the column. Marks the
     * row; never says what the SKU should be (the SKU format programme is
     * the owner's).
     */
    sku_provisional?: boolean;
    is_active: boolean;
    created_at: string;
}

export interface Warehouse {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
    /** Set only for godowns pulled from Tally — a safe voucher godown. */
    tally_guid: string | null;
    created_at: string;
}

export interface StockBalance {
    id: number;
    item: Item;
    warehouse: Warehouse;
    quantity: string;
    /**
     * Weighted average of the purchase rates received into this balance —
     * served only to finance.view/manage eyes (FC-06); the key is ABSENT,
     * never null, for everyone else. Presence is the server's ruling.
     */
    average_cost?: string;
}

export type StockMovementType = 'receipt' | 'issue' | 'transfer_in' | 'transfer_out';

export interface Batch {
    id: number;
    item: Item;
    batch_number: string;
    manufactured_date: string | null;
    expiry_date: string | null;
    notes: string | null;
    created_at: string;
}

export interface BatchOnHand {
    warehouse_id: number;
    warehouse_code: string | null;
    quantity: string;
}

export interface BatchLedger {
    batch: Batch;
    on_hand: BatchOnHand[];
    movements: StockMovement[];
}

export type SerialNumberStatus = 'registered' | 'in_stock' | 'consumed' | 'sold' | 'scrapped';

export interface SerialNumber {
    id: number;
    item: Item;
    serial_number: string;
    status: SerialNumberStatus;
    warehouse: Warehouse | null;
    movements?: StockMovement[];
    created_at: string;
}

export interface StockMovement {
    id: number;
    type: StockMovementType;
    item: Item;
    warehouse: Warehouse;
    batch?: Batch | null;
    serial_number?: SerialNumber | null;
    quantity: string;
    /** GRN purchase rate — served only to finance.view/manage eyes; absent otherwise. */
    unit_cost?: string | null;
    reference: string | null;
    transfer_group: string | null;
    movement_date: string;
    notes: string | null;
}
