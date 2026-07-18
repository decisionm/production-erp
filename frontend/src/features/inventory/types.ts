export interface Item {
    id: number;
    sku: string;
    name: string;
    description: string | null;
    uom: string;
    reorder_level: string;
    is_active: boolean;
    created_at: string;
}

export interface Warehouse {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
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

export interface StockMovement {
    id: number;
    type: StockMovementType;
    item: Item;
    warehouse: Warehouse;
    quantity: string;
    unit_cost: string | null;
    reference: string | null;
    transfer_group: string | null;
    movement_date: string;
    notes: string | null;
}
