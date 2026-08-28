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
        Schema::table('loan_products', function (Blueprint $table) {
            $table->dropUnique('loan_products_code_unique');
            $table->unique(['organization_id', 'code'], 'loan_products_organization_code_unique');
        });

        Schema::table('chart_accounts', function (Blueprint $table) {
            $table->dropUnique('chart_accounts_code_unique');
            $table->unique(['organization_id', 'code'], 'chart_accounts_organization_code_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_products', function (Blueprint $table) {
            $table->dropUnique('loan_products_organization_code_unique');
            $table->unique('code');
        });

        Schema::table('chart_accounts', function (Blueprint $table) {
            $table->dropUnique('chart_accounts_organization_code_unique');
            $table->unique('code');
        });
    }
};
