<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT THE FLOOR NEEDED, AND WHAT IT ASKED THE STORE FOR (DEC-20260831-001).
 *
 * The owner's rule: material not returned stays available in Production/WIP
 * and is the next day's opening material, and the next request must take
 * account of it — the screen shows the total required, the quantity already
 * standing in production, and the balance to request, which is the first
 * minus the second, floored at zero.
 *
 * `quantity` keeps EXACTLY the meaning it has always had: what is asked of
 * the store. Every existing reader — the store's queue, issued_quantity, the
 * outstanding arithmetic, MaterialRequestService::applyIssuedQuantities —
 * goes on answering the same question about the same column, and nothing
 * below changes a single existing row.
 *
 * The two new columns record the other two figures, and they are NULLABLE on
 * purpose: a request raised before this existed did not net anything off, and
 * a zero would be a claim that it did — "nothing was standing in production"
 * — where NULL says "not recorded". The same distinction the item category
 * columns keep (DEC-20260827-002).
 *
 * Why record them at all rather than recompute: the WIP balance moves every
 * time a batch consumes or a return comes home, so a figure recomputed next
 * week cannot answer why THIS request asked for what it did. A netted
 * request that cannot explain itself is worse than one that never netted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_request_lines', function (Blueprint $table) {
            $table->decimal('required_quantity', 15, 4)->nullable()->after('quantity');
            $table->decimal('available_in_production', 15, 4)->nullable()->after('required_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('material_request_lines', function (Blueprint $table) {
            $table->dropColumn(['required_quantity', 'available_in_production']);
        });
    }
};
