<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'loan_application_fee'],
            [
                'label' => 'Loan application fee',
                'value' => '10000',
                'type' => 'money',
                'group' => 'loans',
                'description' => 'Fee deducted from loan proceeds when a supervisor disburses into client savings.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('system_settings')->where('key', 'loan_application_fee')->delete();
    }
};
