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
        Schema::table('organization_contracts', function (Blueprint $table) {
            if (! Schema::hasColumn('organization_contracts', 'balance_due_date')) {
                $table->date('balance_due_date')->nullable()->after('balance_due');
            }

            if (! Schema::hasColumn('organization_contracts', 'balance_due_after_days')) {
                $table->unsignedInteger('balance_due_after_days')->nullable()->after('balance_due_date');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organization_contracts', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('organization_contracts', 'balance_due_date') ? 'balance_due_date' : null,
                Schema::hasColumn('organization_contracts', 'balance_due_after_days') ? 'balance_due_after_days' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
