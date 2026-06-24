<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('clients')
            ->whereNull('officer_id')
            ->orderBy('id')
            ->chunkById(500, function (Collection $clients): void {
                $clientIds = $clients->pluck('id');

                $recentLoans = DB::table('loans')
                    ->select(['id', 'client_id', 'officer_id'])
                    ->whereIn('client_id', $clientIds)
                    ->whereNotNull('officer_id')
                    ->orderByDesc('application_date')
                    ->orderByDesc('id')
                    ->get()
                    ->unique('client_id');

                foreach ($recentLoans as $loan) {
                    DB::table('clients')
                        ->where('id', $loan->client_id)
                        ->whereNull('officer_id')
                        ->update([
                            'officer_id' => $loan->officer_id,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
