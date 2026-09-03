/**
 * THE TWO TRACEABILITY REGISTERS' ORDER — lots and bags (03-Sep-2026).
 *
 * Both registers are paged on the server, so both are ORDERED there
 * (ListMaterialLotsRequest / ListMaterialBagsRequest ::SORTABLE through
 * ListSort). They share one route as two tabs, so neither keeps its view in
 * the URL — two lists on one URL would fight over `page` — and each holds
 * its sort in component state instead. This module is the pure part: which
 * columns sort, and what the arrow shows when nothing has been clicked.
 */

export const MATERIAL_LOT_SORT_FIELDS: readonly string[] = ['received_date', 'supplier_lot_no', 'bag_count', 'total_received_kg'];

/**
 * The lot register's default is the older newest/oldest switch, which the
 * server still honours when no column sort is sent — so the Received
 * column's arrow follows the switch until a header is clicked.
 */
export function materialLotDefaultSort(order: 'newest' | 'oldest'): string {
    return order === 'oldest' ? 'received_date' : '-received_date';
}

export const MATERIAL_BAG_SORT_FIELDS: readonly string[] = ['barcode', 'original_kg', 'remaining_kg', 'status', 'created_at'];

/** TraceabilityService::paginateBags' order when no sort is asked for: oldest bag first. */
export const MATERIAL_BAG_DEFAULT_SORT = 'id';
