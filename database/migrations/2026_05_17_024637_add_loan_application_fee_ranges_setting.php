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
            ['key' => 'loan_application_fee_ranges'],
            [
                'label' => 'Loan application fee ranges',
                'value' => json_encode([
                    ['min' => 50000, 'max' => 100000, 'fee' => 5000],
                    ['min' => 100100, 'max' => 200000, 'fee' => 10000],
                    ['min' => 200100, 'max' => 500000, 'fee' => 15000],
                    ['min' => 500100, 'max' => null, 'fee' => 25000],
                ], JSON_PRETTY_PRINT),
                'type' => 'json',
                'group' => 'loans',
                'description' => 'JSON fee bands based on requested loan amount and used at disbursement. Each item: min, max, fee. Use null max for open-ended.',
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
        DB::table('system_settings')->where('key', 'loan_application_fee_ranges')->delete();
    }
};
