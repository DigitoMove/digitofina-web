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
        if (! Schema::hasTable('loan_repayment_allocations')) {
            Schema::create('loan_repayment_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('loan_repayment_id');
                $table->foreignId('loan_schedule_id');
                $table->decimal('penalty_paid', 18, 2)->default(0);
                $table->decimal('fees_paid', 18, 2)->default(0);
                $table->decimal('interest_paid', 18, 2)->default(0);
                $table->decimal('principal_paid', 18, 2)->default(0);
                $table->decimal('total_paid', 18, 2)->default(0);
                $table->timestamps();
            });
        }

        Schema::table('loan_repayment_allocations', function (Blueprint $table) {
            if (! Schema::hasColumn('loan_repayment_allocations', 'loan_repayment_id')) {
                $table->foreignId('loan_repayment_id')->after('id');
            }

            if (! Schema::hasColumn('loan_repayment_allocations', 'loan_schedule_id')) {
                $table->foreignId('loan_schedule_id')->after('loan_repayment_id');
            }

            foreach (['penalty_paid', 'fees_paid', 'interest_paid', 'principal_paid', 'total_paid'] as $column) {
                if (! Schema::hasColumn('loan_repayment_allocations', $column)) {
                    $table->decimal($column, 18, 2)->default(0);
                }
            }

            if (! Schema::hasColumn('loan_repayment_allocations', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }

            if (! Schema::hasColumn('loan_repayment_allocations', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        Schema::table('loan_repayment_allocations', function (Blueprint $table) {
            if (! $this->indexExists('lra_repayment_schedule_idx')) {
                $table->index(['loan_repayment_id', 'loan_schedule_id'], 'lra_repayment_schedule_idx');
            }

            if (! $this->foreignKeyExists('lra_repayment_fk', 'loan_repayment_id')) {
                $table->foreign('loan_repayment_id', 'lra_repayment_fk')->references('id')->on('loan_repayments')->cascadeOnDelete();
            }

            if (! $this->foreignKeyExists('lra_schedule_fk', 'loan_schedule_id')) {
                $table->foreign('loan_schedule_id', 'lra_schedule_fk')->references('id')->on('loan_schedules')->cascadeOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_repayment_allocations');
    }

    private function indexExists(string $name): bool
    {
        return collect(DB::select('SHOW INDEX FROM loan_repayment_allocations'))->contains(
            fn (object $index): bool => $index->Key_name === $name,
        );
    }

    private function foreignKeyExists(string $name, string $column): bool
    {
        $database = DB::getDatabaseName();

        $hasNamedConstraint = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', 'loan_repayment_allocations')
            ->where('CONSTRAINT_NAME', $name)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();

        if ($hasNamedConstraint) {
            return true;
        }

        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', 'loan_repayment_allocations')
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();
    }
};
