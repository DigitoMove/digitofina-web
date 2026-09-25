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
        if (! Schema::hasColumn('organization_contracts', 'term_type')) {
            Schema::table('organization_contracts', function (Blueprint $table): void {
                $table->string('term_type', 20)->default('fixed_term')->after('status');
            });
        }

        if (Schema::hasColumn('organization_contracts', 'term_months')) {
            Schema::table('organization_contracts', function (Blueprint $table): void {
                $table->unsignedInteger('term_months')->nullable()->default(null)->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('organization_contracts', 'term_type')) {
            Schema::table('organization_contracts', function (Blueprint $table): void {
                $table->dropColumn('term_type');
            });
        }
    }
};
