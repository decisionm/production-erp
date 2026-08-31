<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE READ-ONLY HALF OF PROCUREMENT'S TALLY LOOKUP — what a vendor is in
 * Tally, and what the factory has actually paid for an item.
 *
 * Three changes, all additive, none of which posts anything to Tally. The
 * agent reads; a person decides; the ERP masters change only when that person
 * says so.
 *
 * 1. `ledgers` LEARNS A CONTACT AND A SYNC STAMP. The mirror already carries a
 *    GSTIN and a state (2026_08_28_120000). Email and phone complete what a
 *    vendor row can honestly be made of. MEASURED, not hoped for: in the
 *    live company's own All Masters export (1742 ledgers) only 4 carry an
 *    EMAIL and 78 a phone, and of 620 Sundry Creditors exactly 1 has an email
 *    and 8 a phone. So these columns will be overwhelmingly NULL, and that is
 *    the truth about the books rather than a fault in the pull. Nothing is
 *    invented to fill them.
 *
 *    `tally_synced_at` is stamped by the pull that wrote the row. It exists
 *    because `updated_at` cannot answer the question the screens ask: it moves
 *    on every sync whether or not a value changed, so it says when the row was
 *    touched, never when this GSTIN was last confirmed by Tally. The screens
 *    show "Tally · synced <time>" from this column.
 *
 * 2. `tally_purchase_rates` — ONE ROW PER VOUCHER LINE the agent read out of
 *    the factory's Day Book. Purchase Orders and Purchase invoices only, and
 *    only ones Tally itself does not consider cancelled, deleted or optional.
 *
 *    THE RATE IS STORED WITH ITS UNIT AND NEVER WITHOUT IT. Tally spells a
 *    rate `674.000/Kgs.` — a number AND the basis it is per. Q40 records 28
 *    of 382 purchase-order lines carrying two units, so a bare number moved
 *    onto an ERP line whose unit differs would silently restate the price.
 *    `rate_unit` is therefore NOT NULL-able-by-accident furniture: the lookup
 *    refuses to prefill when it disagrees with the item's own unit.
 *
 *    GST IS STORED PER VOUCHER LINE, NOT PER ITEM. Q39 measured that 9 of 43
 *    items appear under BOTH 5% and 18% and 3 of 20 vendors use both, so the
 *    rate is a property of neither — it belongs to the voucher, on its date.
 *    Nothing here reads or writes `gst_rates`, and nothing here may become a
 *    per-item tax master.
 *
 *    Money and quantity columns are decimal, never float (CLAUDE.md).
 *
 * 3. `tally_vendor_review_dismissals` — the ONLY thing the review screen
 *    persists. The queue of "new vendor" and "conflicting detail" rows is
 *    COMPUTED from the mirror against the vendor master every time it is
 *    asked, so it can never go stale behind a re-sync; what a person decides
 *    to set aside has to survive, and that is this table. One row per ledger
 *    and field, holding the exact value that was dismissed — so if Tally
 *    later returns a DIFFERENT value for that field, the dismissal no longer
 *    matches it and the row comes back. Dismissing a fact must not blind the
 *    factory to the next one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledgers', function (Blueprint $table): void {
            if (! Schema::hasColumn('ledgers', 'email')) {
                $table->string('email')->nullable()->after('state_name');
            }
            if (! Schema::hasColumn('ledgers', 'phone')) {
                $table->string('phone')->nullable()->after('email');
            }
            if (! Schema::hasColumn('ledgers', 'tally_synced_at')) {
                $table->timestamp('tally_synced_at')->nullable()->after('phone');
            }
        });

        if (! Schema::hasTable('tally_purchase_rates')) {
            Schema::create('tally_purchase_rates', function (Blueprint $table): void {
                $table->id();

                // WHICH VOUCHER. The GUID is Tally's stable identity for it;
                // line_index is this line's position within that voucher, and
                // the pair is what makes a re-pull an update rather than a
                // duplicate. A voucher edited in Tally to have fewer lines
                // leaves its tail rows behind — the pull deletes them, which
                // is why the pair is unique rather than merely indexed.
                $table->string('voucher_guid');
                $table->unsignedInteger('line_index');
                // 'purchase_order' | 'purchase_invoice' — the two kinds the
                // owner asked for, kept apart because the screen shows both
                // and a person must never mistake an order for a bill.
                $table->string('voucher_type', 32);
                $table->string('voucher_number')->nullable();
                $table->string('voucher_reference')->nullable();
                $table->date('voucher_date');

                // WHO. The party as Tally names it. There is no party GUID on
                // a Day Book voucher — it carries PARTYLEDGERNAME — so the
                // link to a mirrored ledger is resolved by that name at read
                // time and the name itself is what is stored.
                $table->string('party_ledger_name');
                $table->string('party_gstin', 15)->nullable();

                // WHAT. The stock item as Tally names it; the GUID is
                // resolved against the item mirror when one matches, and left
                // null when none does rather than guessed.
                $table->string('stock_item_name');
                $table->string('tally_stock_item_guid')->nullable();

                // THE RATE AND ITS BASIS — see the class docblock. Six
                // decimal places because Tally quotes three and a
                // per-thousand basis would lose the tail at two.
                $table->decimal('rate_value', 18, 6);
                $table->string('rate_unit')->nullable();
                $table->decimal('quantity', 18, 4)->nullable();
                $table->string('quantity_unit')->nullable();
                $table->decimal('amount', 18, 4)->nullable();

                // THE TAX AS THAT VOUCHER CARRIED IT, on its date.
                $table->decimal('cgst_rate', 8, 4)->nullable();
                $table->decimal('sgst_rate', 8, 4)->nullable();
                $table->decimal('igst_rate', 8, 4)->nullable();
                $table->decimal('cess_rate', 8, 4)->nullable();
                $table->string('hsn_code')->nullable();
                // The factory's own purchase ledger the line was booked to —
                // the local-versus-interstate evidence (DEC-20260812-003),
                // shown so Accounts can see WHY a tax split looks as it does.
                $table->string('purchase_ledger_name')->nullable();

                // Provenance. `tally_company` is stamped the way every other
                // mirrored master is (2026_08_03_140000).
                $table->string('tally_company')->nullable();
                $table->timestamp('tally_synced_at');

                $table->timestamps();

                $table->unique(['voucher_guid', 'line_index']);
                // The lookup's own query: newest line for this party and this
                // item, of this kind.
                $table->index(['party_ledger_name', 'stock_item_name', 'voucher_type', 'voucher_date'], 'tally_purchase_rates_lookup_index');
                $table->index('tally_stock_item_guid');
            });
        }

        if (! Schema::hasTable('tally_vendor_review_dismissals')) {
            Schema::create('tally_vendor_review_dismissals', function (Blueprint $table): void {
                $table->id();
                $table->string('tally_ledger_guid');
                // The field set aside: 'name', 'email', 'phone', 'gstin',
                // 'state_code', 'tally_ledger_name' — or '*' for "this ledger
                // is not a vendor", the whole-row dismissal of a new one.
                $table->string('field', 32);
                // The exact value dismissed. NULL means "dismissed while Tally
                // had nothing here", which is a different fact from dismissing
                // a value, and both must be distinguishable from a later one.
                $table->text('dismissed_value')->nullable();
                $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('dismissed_at');
                $table->timestamps();

                $table->unique(['tally_ledger_guid', 'field']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tally_vendor_review_dismissals');
        Schema::dropIfExists('tally_purchase_rates');

        Schema::table('ledgers', function (Blueprint $table): void {
            foreach (['email', 'phone', 'tally_synced_at'] as $column) {
                if (Schema::hasColumn('ledgers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
