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
            $table->string('unit_label', 80)->default('share')->after('type');
            $table->decimal('unit_value', 18, 2)->default(0)->after('unit_label');
            $table->unsignedInteger('minimum_units')->default(1)->after('unit_value');
            $table->unsignedInteger('maximum_units')->nullable()->after('minimum_units');
            $table->boolean('is_share_package')->default(false)->after('maximum_units')->index();
        });

        Schema::table('investment_accounts', function (Blueprint $table) {
            $table->foreignId('sponsor_client_id')->nullable()->after('client_id')->constrained('clients')->nullOnDelete();
            $table->unsignedInteger('unit_quantity')->default(1)->after('investment_plan_id');
            $table->decimal('unit_value', 18, 2)->default(0)->after('unit_quantity');
            $table->string('unit_label', 80)->default('share')->after('unit_value');

            $table->index(['sponsor_client_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('investment_accounts', function (Blueprint $table) {
            $table->dropIndex(['sponsor_client_id', 'status']);
            $table->dropConstrainedForeignId('sponsor_client_id');
            $table->dropColumn(['unit_quantity', 'unit_value', 'unit_label']);
        });

        Schema::table('investment_plans', function (Blueprint $table) {
            $table->dropColumn(['unit_label', 'unit_value', 'minimum_units', 'maximum_units', 'is_share_package']);
        });
    }
};
