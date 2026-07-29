<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_notes', function (Blueprint $table) {
            $table->string('receipt_key', 100)->nullable()->unique()->after('id');
            $table->char('receipt_payload_hash', 64)->nullable()->after('receipt_key');
        });

        Schema::table('material_lots', function (Blueprint $table) {
            $table->foreignId('goods_receipt_note_line_id')
                ->nullable()
                ->after('grn_id')
                ->constrained('goods_receipt_note_lines')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('material_lots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('goods_receipt_note_line_id');
        });

        Schema::table('goods_receipt_notes', function (Blueprint $table) {
            $table->dropUnique(['receipt_key']);
            $table->dropColumn(['receipt_key', 'receipt_payload_hash']);
        });
    }
};
