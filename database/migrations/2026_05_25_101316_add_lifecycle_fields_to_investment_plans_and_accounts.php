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
        Schema::table('investment_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('investment_plans', 'monthly_maintenance_fee')) {
                $table->decimal('monthly_maintenance_fee', 18, 2)->default(0)->after('maximum_amount');
            }

            if (! Schema::hasColumn('investment_plans', 'min_holding_months')) {
                $table->unsignedSmallInteger('min_holding_months')->default(36)->after('duration_unit');
            }

            if (! Schema::hasColumn('investment_plans', 'max_holding_months')) {
                $table->unsignedSmallInteger('max_holding_months')->default(60)->after('min_holding_months');
            }

            if (! Schema::hasColumn('investment_plans', 'default_required_information')) {
                $table->json('default_required_information')->nullable()->after('risk_level');
            }
        });

        Schema::table('investment_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('investment_accounts', 'target_duration_months')) {
                $table->unsignedSmallInteger('target_duration_months')->nullable()->after('maturity_date');
            }

            if (! Schema::hasColumn('investment_accounts', 'monthly_maintenance_fee')) {
                $table->decimal('monthly_maintenance_fee', 18, 2)->default(0)->after('return_rate');
            }

            if (! Schema::hasColumn('investment_accounts', 'maintenance_fee_paid')) {
                $table->decimal('maintenance_fee_paid', 18, 2)->default(0)->after('monthly_maintenance_fee');
            }

            if (! Schema::hasColumn('investment_accounts', 'client_investment_details')) {
                $table->json('client_investment_details')->nullable()->after('return_method');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('investment_accounts', function (Blueprint $table) {
            foreach (['target_duration_months', 'monthly_maintenance_fee', 'maintenance_fee_paid', 'client_investment_details'] as $column) {
                if (Schema::hasColumn('investment_accounts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('investment_plans', function (Blueprint $table) {
            foreach (['monthly_maintenance_fee', 'min_holding_months', 'max_holding_months', 'default_required_information'] as $column) {
                if (Schema::hasColumn('investment_plans', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
