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
        foreach ([
            [
                'key' => 'repayment_public_holidays',
                'label' => 'Repayment public holidays',
                'value' => json_encode([], JSON_PRETTY_PRINT),
                'type' => 'json',
                'group' => 'loans',
                'description' => 'JSON array of public holiday dates excluded from repayment due dates. Use YYYY-MM-DD values.',
            ],
            [
                'key' => 'loan_arrears_penalty_rate',
                'label' => 'Loan arrears penalty rate',
                'value' => '2',
                'type' => 'number',
                'group' => 'loans',
                'description' => 'Percentage penalty applied to each overdue installment once it is one day past due.',
            ],
        ] as $setting) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $setting['key']],
                $setting + [
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('system_settings')
            ->whereIn('key', ['repayment_public_holidays', 'loan_arrears_penalty_rate'])
            ->delete();
    }
};
