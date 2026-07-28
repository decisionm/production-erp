<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 6 traceability — the FIFO override permission (design doc
 * "Allocation rules"): scanning a newer bag while an older one stays open
 * needs production.override-fifo and records who. findOrCreate keeps this
 * idempotent across fresh installs, re-runs and PermissionSeeder — it is
 * deliberately NOT part of the module view/manage catalog (it's a targeted
 * exception grant, not a module gate), so it lives here rather than in
 * PermissionService::MODULES.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate('production.override-fifo', 'web');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Deleting a permission would cascade role/user grants — leave it.
    }
};
