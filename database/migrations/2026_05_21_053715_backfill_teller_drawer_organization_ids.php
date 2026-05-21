<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('teller_drawers')
            ->whereNull('teller_drawers.organization_id')
            ->update(['organization_id' => DB::raw('(select branches.organization_id from branches where branches.id = teller_drawers.branch_id)')]);

        DB::table('drawer_sessions')
            ->whereNull('drawer_sessions.organization_id')
            ->update(['organization_id' => DB::raw('(select teller_drawers.organization_id from teller_drawers where teller_drawers.id = drawer_sessions.teller_drawer_id)')]);

        DB::table('drawer_movements')
            ->whereNull('drawer_movements.organization_id')
            ->update(['organization_id' => DB::raw('(select drawer_sessions.organization_id from drawer_sessions where drawer_sessions.id = drawer_movements.drawer_session_id)')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Data backfill only. Organization ownership should not be removed on rollback.
    }
};
