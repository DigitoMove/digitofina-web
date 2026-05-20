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
            $table->string('savings_type', 40)->default('ordinary')->after('code')->index();
            $table->boolean('supports_goals')->default(false)->after('allows_overdraft');
            $table->boolean('supports_fixed_savings')->default(false)->after('supports_goals');
            $table->boolean('supports_group_savings')->default(false)->after('supports_fixed_savings');
        });

        Schema::table('savings_accounts', function (Blueprint $table) {
            $table->string('savings_type', 40)->default('ordinary')->after('savings_product_id')->index();
            $table->timestamp('last_activity_at')->nullable()->after('opening_date')->index();
            $table->timestamp('dormant_at')->nullable()->after('last_activity_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('savings_accounts', function (Blueprint $table) {
            $table->dropColumn(['savings_type', 'last_activity_at', 'dormant_at']);
        });

        Schema::table('savings_products', function (Blueprint $table) {
            $table->dropColumn(['savings_type', 'supports_goals', 'supports_fixed_savings', 'supports_group_savings']);
        });
    }
};
