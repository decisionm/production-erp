<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Flags non-human accounts (e.g. the Tally Sync Agent service
            // user — see App\Modules\TallySync\Services\AgentTokenService)
            // so they're excluded from the regular Users management page
            // without needing a real login, role, or password anyone knows.
            $table->boolean('is_system')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_system');
        });
    }
};
