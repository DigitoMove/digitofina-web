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
        $tableName = 'loan_savings_deduction_runs';

        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table): void {
                $this->defineRunsTable($table);
            });

            return;
        }

        $this->ensureExistingRunsTableIsComplete($tableName);
        $this->ensureRunsForeignKeys($tableName);
        $this->ensureRunsIndexes($tableName);
    }

    private function defineRunsTable(Blueprint $table): void
    {
        $table->id();
        $table->foreignId('organization_id');
        $table->foreignId('branch_id');
        $table->foreignId('schedule_id');
        $table->foreignId('savings_transaction_id')->nullable();
        $table->foreignId('loan_repayment_id')->nullable();
        $table->decimal('amount', 18, 2);
        $table->timestamp('due_at');
        $table->string('idempotency_key', 180);
        $table->string('status', 20);
        $table->text('failure_reason')->nullable();
        $table->timestamp('processed_at')->nullable();
        $table->timestamps();

        $this->defineRunsForeignKeys($table);
        $this->defineRunsIndexes($table);
    }

    private function defineRunsForeignKeys(Blueprint $table): void
    {
        $table->foreign('organization_id', 'lsdr_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
        $table->foreign('branch_id', 'lsdr_branch_fk')->references('id')->on('branches')->cascadeOnDelete();
        $table->foreign('schedule_id', 'lsdr_schedule_fk')->references('id')->on('loan_savings_deduction_schedules')->cascadeOnDelete();
        $table->foreign('savings_transaction_id', 'lsdr_savings_tx_fk')->references('id')->on('savings_transactions')->nullOnDelete();
        $table->foreign('loan_repayment_id', 'lsdr_repayment_fk')->references('id')->on('loan_repayments')->nullOnDelete();
    }

    private function defineRunsIndexes(Blueprint $table): void
    {
        $table->unique('idempotency_key', 'lsdr_idempotency_uq');
        $table->index('status', 'lsdr_status_idx');
        $table->index(['schedule_id', 'due_at'], 'lsdr_schedule_due_idx');
        $table->index(['organization_id', 'branch_id', 'status'], 'lsdr_org_branch_status_idx');
    }

    private function ensureExistingRunsTableIsComplete(string $tableName): void
    {
        $requiredColumns = [
            'id',
            'organization_id',
            'branch_id',
            'schedule_id',
            'savings_transaction_id',
            'loan_repayment_id',
            'amount',
            'due_at',
            'idempotency_key',
            'status',
            'failure_reason',
            'processed_at',
            'created_at',
            'updated_at',
        ];

        $missingColumns = array_values(array_filter(
            $requiredColumns,
            fn (string $column): bool => ! Schema::hasColumn($tableName, $column),
        ));

        if ($missingColumns !== []) {
            throw new RuntimeException('The existing loan savings deduction runs table is incomplete: '.implode(', ', $missingColumns).'.');
        }
    }

    private function ensureRunsForeignKeys(string $tableName): void
    {
        $foreignKeys = Schema::getForeignKeys($tableName);
        $relationships = [
            ['organization_id', 'organizations', 'lsdr_org_fk'],
            ['branch_id', 'branches', 'lsdr_branch_fk'],
            ['schedule_id', 'loan_savings_deduction_schedules', 'lsdr_schedule_fk'],
            ['savings_transaction_id', 'savings_transactions', 'lsdr_savings_tx_fk'],
            ['loan_repayment_id', 'loan_repayments', 'lsdr_repayment_fk'],
        ];
        $missingRelationships = array_values(array_filter(
            $relationships,
            fn (array $relationship): bool => ! collect($foreignKeys)->contains(fn (array $foreignKey): bool => $foreignKey['columns'] === [$relationship[0]]
                && $foreignKey['foreign_table'] === $relationship[1]
                && $foreignKey['foreign_columns'] === ['id']),
        ));

        if ($missingRelationships === []) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($missingRelationships): void {
            foreach ($missingRelationships as [$column, $foreignTable, $name]) {
                $foreign = $table->foreign($column, $name)->references('id')->on($foreignTable);

                if (in_array($column, ['savings_transaction_id', 'loan_repayment_id'], true)) {
                    $foreign->nullOnDelete();

                    continue;
                }

                $foreign->cascadeOnDelete();
            }
        });
    }

    private function ensureRunsIndexes(string $tableName): void
    {
        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            if (! Schema::hasIndex($tableName, ['idempotency_key'], 'unique')) {
                $table->unique('idempotency_key', 'lsdr_idempotency_uq');
            }

            if (! Schema::hasIndex($tableName, ['status'])) {
                $table->index('status', 'lsdr_status_idx');
            }

            if (! Schema::hasIndex($tableName, ['schedule_id', 'due_at'])) {
                $table->index(['schedule_id', 'due_at'], 'lsdr_schedule_due_idx');
            }

            if (! Schema::hasIndex($tableName, ['organization_id', 'branch_id', 'status'])) {
                $table->index(['organization_id', 'branch_id', 'status'], 'lsdr_org_branch_status_idx');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_savings_deduction_runs');
    }
};
