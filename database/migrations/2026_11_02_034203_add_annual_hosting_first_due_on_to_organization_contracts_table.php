<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('organization_contracts', 'annual_hosting_first_due_on')) {
            Schema::table('organization_contracts', function (Blueprint $table): void {
                $table->date('annual_hosting_first_due_on')->nullable()->after('annual_hosting_fee');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('organization_contracts', 'annual_hosting_first_due_on')) {
            Schema::table('organization_contracts', function (Blueprint $table): void {
                $table->dropColumn('annual_hosting_first_due_on');
            });
        }
    }
};
