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
    tracking_type: ItemTrackingType;
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
    average_cost: string;
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
    unit_cost: string | null;
    reference: string | null;
    transfer_group: string | null;
    movement_date: string;
    notes: string | null;
}
