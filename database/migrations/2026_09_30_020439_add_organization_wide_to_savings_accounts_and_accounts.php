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
        if (! Schema::hasColumn('savings_accounts', 'is_organization_wide')) {
            Schema::table('savings_accounts', function (Blueprint $table): void {
                $table->boolean('is_organization_wide')->default(false)->after('branch_id')->index();
            });
        }

        if (! Schema::hasColumn('accounts', 'is_organization_wide')) {
            Schema::table('accounts', function (Blueprint $table): void {
                $table->boolean('is_organization_wide')->default(false)->after('branch_id')->index();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('savings_accounts', 'is_organization_wide')) {
            Schema::table('savings_accounts', function (Blueprint $table): void {
                $table->dropColumn('is_organization_wide');
            });
        }

        if (Schema::hasColumn('accounts', 'is_organization_wide')) {
            Schema::table('accounts', function (Blueprint $table): void {
                $table->dropColumn('is_organization_wide');
            });
        }
    }
};
