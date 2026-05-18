<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'reversal_of_id')) {
                $table->foreignId('reversal_of_id')->nullable()->after('reversed_by');
            }

            if (! Schema::hasColumn('transactions', 'reversed_at')) {
                $table->timestamp('reversed_at')->nullable()->after('status');
            }
        });

        Schema::table('transactions', function (Blueprint $table) {
            if (! $this->foreignKeyExists('transactions_reversal_fk', 'reversal_of_id')) {
                $table->foreign('reversal_of_id', 'transactions_reversal_fk')->references('id')->on('transactions')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'reversal_of_id')) {
                if ($this->foreignKeyExists('transactions_reversal_fk', 'reversal_of_id')) {
                    $table->dropForeign('transactions_reversal_fk');
                }

                $table->dropColumn('reversal_of_id');
            }

            if (Schema::hasColumn('transactions', 'reversed_at')) {
                $table->dropColumn('reversed_at');
            }
        });
    }

    private function foreignKeyExists(string $name, string $column): bool
    {
        $database = DB::getDatabaseName();

        $hasNamedConstraint = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', 'transactions')
            ->where('CONSTRAINT_NAME', $name)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();

        if ($hasNamedConstraint) {
            return true;
        }

        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', 'transactions')
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();
    }
};
