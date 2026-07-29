<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The controlling production standard: Machine + Product + Mould + Colour.
 *
 * Cycle time and cavities live HERE, not on the item, because the same
 * product genuinely runs at different rates on different machines — the
 * factory's own master workbook is built on that premise. items.standard_*
 * stays where it is and keeps working as the legacy fallback for products
 * with no configuration yet.
 *
 * Nothing here is ever created as approved by an import. Every workbook row
 * is marked "To Confirm", and a guessed standard that looks approved is
 * worse than no standard at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_center_id')->constrained('work_centers')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('mold_id')->nullable()->constrained('molds')->nullOnDelete();
            $table->string('colour')->nullable();

            $table->decimal('unit_weight_grams', 12, 4)->nullable();

            $table->decimal('default_cycle_time', 8, 2)->nullable();
            $table->decimal('cycle_time_min', 8, 2)->nullable();
            $table->decimal('cycle_time_max', 8, 2)->nullable();

            $table->unsignedSmallInteger('default_cavities')->nullable();
            $table->unsignedSmallInteger('cavities_min')->nullable();
            $table->unsignedSmallInteger('cavities_max')->nullable();
            $table->json('permitted_cavities')->nullable();

            // Recipe link — reuses the existing BOM rather than a competing
            // recipe model for this slice. Null = no recipe, reported as a
            // missing link rather than silently estimated.
            $table->foreignId('bom_id')->nullable()->constrained('boms')->nullOnDelete();

            // draft | approved | inactive
            $table->string('status', 16)->default('draft');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // Where this row came from (workbook mapping id, manual entry)
            // and the factory's own confirmation wording, kept verbatim.
            $table->string('source', 64)->nullable();
            $table->string('source_reference', 64)->nullable();
            $table->string('confirmation_status', 32)->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['work_center_id', 'item_id', 'status']);
            $table->index(['status', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_configurations');
    }
};
