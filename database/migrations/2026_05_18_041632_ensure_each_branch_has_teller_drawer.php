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
        $now = now();
        $branches = DB::table('branches')->select(['id', 'code', 'name'])->whereNull('deleted_at')->get();

        foreach ($branches as $branch) {
            $hasDrawer = DB::table('teller_drawers')->where('branch_id', $branch->id)->exists();

            if ($hasDrawer) {
                continue;
            }

            $baseCode = trim((string) ($branch->code ?: 'BR-'.$branch->id));
            $code = $baseCode.'-TD-01';
            $suffix = 1;

            while (DB::table('teller_drawers')->where('code', $code)->exists()) {
                $suffix++;
                $code = $baseCode.'-TD-'.str_pad((string) $suffix, 2, '0', STR_PAD_LEFT);
            }

            DB::table('teller_drawers')->insert([
                'branch_id' => $branch->id,
                'assigned_user_id' => null,
                'name' => 'Main Drawer',
                'code' => $code,
                'currency' => 'UGX',
                'current_balance' => 0,
                'status' => 'closed',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
