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
        Schema::table('savings_products', function (Blueprint $table) {
            if (! Schema::hasColumn('savings_products', 'is_mandatory')) {
                $table->boolean('is_mandatory')->default(false)->after('savings_type')->index();
            }

            if (! Schema::hasColumn('savings_products', 'mandatory_amount')) {
                $table->decimal('mandatory_amount', 18, 2)->default(0)->after('minimum_balance');
            }
        });

        Schema::table('loan_products', function (Blueprint $table) {
            if (! Schema::hasColumn('loan_products', 'is_interest_free')) {
                $table->boolean('is_interest_free')->default(false)->after('annual_interest_rate')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_products', function (Blueprint $table) {
            if (Schema::hasColumn('loan_products', 'is_interest_free')) {
                $table->dropColumn('is_interest_free');
            }
        });

        Schema::table('savings_products', function (Blueprint $table) {
            foreach (['is_mandatory', 'mandatory_amount'] as $column) {
                if (Schema::hasColumn('savings_products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
