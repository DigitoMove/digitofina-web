<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('loan_savings_deduction_schedules')) {
            return;
        }

        DB::table('loan_savings_deduction_schedules')
            ->where('status', 'disabled')
            ->where('last_message', 'like', '%loan is fully cleared%')
            ->update(['status' => 'closed']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('loan_savings_deduction_schedules')) {
            return;
        }

        DB::table('loan_savings_deduction_schedules')
            ->where('status', 'closed')
            ->where('last_message', 'like', '%loan is fully cleared%')
            ->update(['status' => 'disabled']);
    }
};
