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
            $table->foreignId('loan_product_template_id')
                ->nullable()
                ->after('organization_id')
                ->constrained()
                ->restrictOnDelete();
        });

        Schema::table('chart_accounts', function (Blueprint $table) {
            $table->foreignId('chart_account_template_id')
                ->nullable()
                ->after('organization_id')
                ->constrained()
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chart_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chart_account_template_id');
        });

        Schema::table('loan_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('loan_product_template_id');
        });
    }
};
