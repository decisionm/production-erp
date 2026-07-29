<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Typed factory settings — the values a factory must be able to change
 * without a code deploy. config/production.php stays as the DEFAULT source;
 * a row here overrides it. That ordering matters: a fresh deployment behaves
 * exactly as the config file says, and every override is an explicit,
 * attributable row rather than an invisible environment difference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factory_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            // string | integer | decimal | boolean | json — how value is cast
            // on read. Stored as text so one table holds every type.
            $table->string('data_type', 16)->default('string');
            $table->string('scope', 32)->default('global');
            $table->string('label')->nullable();
            $table->text('description')->nullable();
            // Workbook "Confirmation Status" (Discussion Confirmed / To
            // Confirm / Recommended). A setting the factory has not confirmed
            // is still visible and still editable — it is simply labelled.
            $table->string('confirmation_status', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('change_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factory_settings');
    }
};
