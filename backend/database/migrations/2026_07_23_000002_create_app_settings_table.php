<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Config-in-DB (not hardcoded), per the single-tenant model: this one
        // instance's settings — e.g. which Tally company to sync — live here as
        // key/value, editable from the app's Settings UI. No tenant_id: this is
        // one company's config, matching TECHNICAL-DOCS.md §2.
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
