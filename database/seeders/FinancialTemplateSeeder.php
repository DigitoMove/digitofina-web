<?php

namespace Database\Seeders;

use App\Services\FinancialDefaultsService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class FinancialTemplateSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(FinancialDefaultsService $financialDefaults): void
    {
        $financialDefaults->syncCatalog();
    }
}
