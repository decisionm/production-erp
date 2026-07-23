<?php

namespace Tests\Feature\TallySync;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Procurement\Events\GoodsReceiptNoteReceived;
use App\Modules\Procurement\Models\GoodsReceiptNote;
use App\Modules\Procurement\Models\GoodsReceiptNoteLine;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Production\Events\ShiftProductionEntryApproved;
use App\Modules\Production\Models\ShiftMaterialConsumption;
use App\Modules\Production\Models\ShiftProductionEntry;
use App\Modules\Sales\Events\DeliveryDispatched;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\TallySync\Models\TallySyncEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises the full domain-event → listener → queue chain for the three new
 * outbound vouchers. Entities are built in memory with their relations set and
 * marked as existing, so the dispatch path is real without a heavy DB graph.
 */
class OutboundVoucherTest extends TestCase
{
    use RefreshDatabase;

    public function test_goods_receipt_enqueues_a_receipt_note(): void
    {
        $vendor = new Vendor(['name' => 'Reliance Industries', 'gstin' => '27AAACR1234A1Z5']);
        $po = new PurchaseOrder;
        $po->setRelation('vendor', $vendor);

        $line = new GoodsReceiptNoteLine(['quantity' => '100.0000', 'unit_cost' => '85.0000']);
        $line->setRelation('item', new Item(['sku' => 'RES-1', 'name' => 'PET Resin']));

        $grn = $this->existing(new GoodsReceiptNote(['received_date' => now(), 'notes' => 'Lorry TN01']), 7);
        $grn->setRelation('lines', collect([$line]));
        $grn->setRelation('warehouse', new Warehouse(['name' => 'RM Store']));
        $grn->setRelation('purchaseOrder', $po);

        event(new GoodsReceiptNoteReceived($grn));

        $entry = TallySyncEntry::where('tally_voucher_type', 'Receipt Note')->firstOrFail();
        $this->assertSame('GRN-7', $entry->payload['voucher_number']);
        $this->assertSame('Reliance Industries', $entry->payload['party_ledger']);
        $this->assertSame('RM Store', $entry->payload['godown']);
        $this->assertSame('PET Resin', $entry->payload['lines'][0]['item']); // exact Tally name
        $this->assertSame('8500.0000', $entry->payload['lines'][0]['amount']);
        $this->assertSame('8500.0000', $entry->payload['total_amount']);
    }

    public function test_delivery_enqueues_a_delivery_note(): void
    {
        $customer = new Customer(['name' => 'Sri Aurobindo Beverages', 'gstin' => '34AABCA1122G1Z4']);
        $so = new SalesOrder;
        $so->setRelation('customer', $customer);

        $line = new DeliveryLine(['quantity' => '2000.0000']);
        $line->setRelation('item', new Item(['sku' => 'BTL-500', 'name' => '500ml PET Bottle']));

        $delivery = $this->existing(new Delivery(['delivered_date' => now(), 'notes' => 'Truck A']), 3);
        $delivery->setRelation('lines', collect([$line]));
        $delivery->setRelation('warehouse', new Warehouse(['name' => 'FG Store']));
        $delivery->setRelation('salesOrder', $so);

        event(new DeliveryDispatched($delivery));

        $entry = TallySyncEntry::where('tally_voucher_type', 'Delivery Note')->firstOrFail();
        $this->assertSame('DN-3', $entry->payload['voucher_number']);
        $this->assertSame('Sri Aurobindo Beverages', $entry->payload['party_ledger']);
        $this->assertSame('500ml PET Bottle', $entry->payload['lines'][0]['item']);
        $this->assertSame('2000.0000', $entry->payload['lines'][0]['quantity']);
    }

    public function test_approved_production_enqueues_a_manufacturing_journal(): void
    {
        $consumption = new ShiftMaterialConsumption(['quantity_issued_kg' => '250.0000']);
        $consumption->setRelation('item', new Item(['sku' => 'RES-1', 'name' => 'PET Resin']));

        $spe = $this->existing(new ShiftProductionEntry([
            'production_date' => now(),
            'quantity_produced' => '5000.0000',
            'batch_number' => 'L1-N-20260723-1',
            'notes' => 'Night shift',
        ]), 9);
        $spe->setRelation('item', new Item(['sku' => 'BTL-500', 'name' => '500ml PET Bottle']));
        $spe->setRelation('warehouse', new Warehouse(['name' => 'FG Store']));
        $spe->setRelation('materialConsumptions', collect([$consumption]));

        event(new ShiftProductionEntryApproved($spe));

        $entry = TallySyncEntry::where('tally_voucher_type', 'Manufacturing Journal')->firstOrFail();
        $this->assertSame('SPE-9', $entry->payload['voucher_number']);
        $this->assertSame('L1-N-20260723-1', $entry->payload['batch_number']);
        $this->assertSame('500ml PET Bottle', $entry->payload['produced'][0]['item']);
        $this->assertSame('5000.0000', $entry->payload['produced'][0]['quantity']);
        $this->assertSame('PET Resin', $entry->payload['consumed'][0]['item']);
        $this->assertSame('250.0000', $entry->payload['consumed'][0]['quantity']);
    }

    /** Mark an in-memory model as an existing (persisted) record without a DB write. */
    private function existing(object $model, int $id): object
    {
        $model->id = $id;
        $model->exists = true;

        return $model;
    }
}
