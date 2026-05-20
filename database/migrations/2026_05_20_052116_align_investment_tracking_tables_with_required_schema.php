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
        Schema::table('investment_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('investment_plans', 'branch_id')) {
                $table->foreignId('branch_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
            }

            if (! Schema::hasColumn('investment_plans', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('investment_plans', 'min_amount')) {
                $table->decimal('min_amount', 18, 2)->default(0)->after('type');
            }

            if (! Schema::hasColumn('investment_plans', 'max_amount')) {
                $table->decimal('max_amount', 18, 2)->nullable()->after('min_amount');
            }

            if (! Schema::hasColumn('investment_plans', 'return_method')) {
                $table->string('return_method', 40)->default('fixed_percentage')->after('expected_return_rate');
            }

            if (! Schema::hasColumn('investment_plans', 'allow_topups')) {
                $table->boolean('allow_topups')->default(false)->after('return_frequency');
            }

            if (! Schema::hasColumn('investment_plans', 'early_withdrawal_penalty_type')) {
                $table->string('early_withdrawal_penalty_type', 30)->default('percentage')->after('allow_early_withdrawal');
            }

            if (! Schema::hasColumn('investment_plans', 'early_withdrawal_penalty_value')) {
                $table->decimal('early_withdrawal_penalty_value', 18, 2)->default(0)->after('early_withdrawal_penalty_type');
            }

            if (! Schema::hasColumn('investment_plans', 'auto_reinvest')) {
                $table->boolean('auto_reinvest')->default(false)->after('early_withdrawal_penalty_value');
            }

            if (! Schema::hasColumn('investment_plans', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        Schema::table('investor_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('investor_accounts', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('investor_accounts', 'current_balance')) {
                $table->decimal('current_balance', 18, 2)->default(0)->after('total_withdrawn');
            }

            if (! Schema::hasColumn('investor_accounts', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        Schema::table('investment_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('investment_accounts', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('investment_accounts', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        Schema::table('investment_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('investment_transactions', 'client_id')) {
                $table->foreignId('client_id')->nullable()->after('investment_account_id')->constrained()->nullOnDelete();
            }

            if (! Schema::hasColumn('investment_transactions', 'transaction_reference')) {
                $table->string('transaction_reference', 80)->nullable()->after('client_id')->index();
            }

            if (! Schema::hasColumn('investment_transactions', 'transaction_type')) {
                $table->string('transaction_type', 40)->nullable()->after('transaction_reference')->index();
            }

            if (! Schema::hasColumn('investment_transactions', 'charges')) {
                $table->decimal('charges', 18, 2)->default(0)->after('amount');
            }

            if (! Schema::hasColumn('investment_transactions', 'penalty_amount')) {
                $table->decimal('penalty_amount', 18, 2)->default(0)->after('charges');
            }

            if (! Schema::hasColumn('investment_transactions', 'reversed_by')) {
                $table->foreignId('reversed_by')->nullable()->after('transaction_date')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('investment_transactions', 'reversed_at')) {
                $table->timestamp('reversed_at')->nullable()->after('reversed_by');
            }

            if (! Schema::hasColumn('investment_transactions', 'reversal_reason')) {
                $table->text('reversal_reason')->nullable()->after('reversed_at');
            }
        });

        Schema::table('savings_goals', function (Blueprint $table) {
            if (! Schema::hasColumn('savings_goals', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('savings_goals', 'goal_name')) {
                $table->string('goal_name')->nullable()->after('name');
            }

            if (! Schema::hasColumn('savings_goals', 'current_amount')) {
                $table->decimal('current_amount', 18, 2)->default(0)->after('target_amount');
            }

            if (! Schema::hasColumn('savings_goals', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        Schema::create('investment_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('investment_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->decimal('expected_amount', 18, 2)->default(0);
            $table->decimal('actual_amount', 18, 2)->default(0);
            $table->decimal('return_rate', 8, 4)->default(0);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->date('posting_date')->nullable()->index();
            $table->string('status', 30)->default('pending')->index();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('narration')->nullable();
            $table->timestamps();

            $table->index(['investment_account_id', 'status']);
            $table->index(['client_id', 'posting_date']);
        });

        Schema::create('locked_funds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('related_type');
            $table->unsignedBigInteger('related_id');
            $table->decimal('amount', 18, 2);
            $table->string('reason', 120);
            $table->string('status', 30)->default('active')->index();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index(['related_type', 'related_id']);
            $table->index(['client_id', 'status']);
        });

        DB::table('investment_plans')->update([
            'min_amount' => DB::raw('minimum_amount'),
            'max_amount' => DB::raw('maximum_amount'),
            'return_method' => DB::raw('return_calculation_method'),
            'allow_topups' => DB::raw('allow_top_ups'),
            'early_withdrawal_penalty_value' => DB::raw('early_withdrawal_penalty'),
            'auto_reinvest' => DB::raw('auto_reinvest_returns'),
        ]);
        DB::table('investor_accounts')->update(['current_balance' => DB::raw('current_investment_balance')]);
        DB::table('investment_transactions')->update([
            'client_id' => DB::raw('(select client_id from investment_accounts where investment_accounts.id = investment_transactions.investment_account_id)'),
            'transaction_reference' => DB::raw('reference'),
            'transaction_type' => DB::raw('type'),
        ]);
        DB::table('savings_goals')->update([
            'goal_name' => DB::raw('name'),
            'current_amount' => DB::raw('current_saved_amount'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locked_funds');
        Schema::dropIfExists('investment_returns');

        Schema::table('investment_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('investment_transactions', 'reversed_by')) {
                $table->dropConstrainedForeignId('reversed_by');
            }

            if (Schema::hasColumn('investment_transactions', 'client_id')) {
                $table->dropConstrainedForeignId('client_id');
            }

            foreach (['transaction_reference', 'transaction_type', 'charges', 'penalty_amount', 'reversed_at', 'reversal_reason'] as $column) {
                if (Schema::hasColumn('investment_transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('investment_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('investment_accounts', 'updated_by')) {
                $table->dropConstrainedForeignId('updated_by');
            }

            if (Schema::hasColumn('investment_accounts', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });

        Schema::table('savings_goals', function (Blueprint $table) {
            if (Schema::hasColumn('savings_goals', 'updated_by')) {
                $table->dropConstrainedForeignId('updated_by');
            }

            foreach (['goal_name', 'current_amount'] as $column) {
                if (Schema::hasColumn('savings_goals', $column)) {
                    $table->dropColumn($column);
                }
            }

            if (Schema::hasColumn('savings_goals', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });

        Schema::table('investor_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('investor_accounts', 'updated_by')) {
                $table->dropConstrainedForeignId('updated_by');
            }

            foreach (['current_balance', 'deleted_at'] as $column) {
                if (Schema::hasColumn('investor_accounts', $column)) {
                    $column === 'deleted_at' ? $table->dropSoftDeletes() : $table->dropColumn($column);
                }
            }
        });

        Schema::table('investment_plans', function (Blueprint $table) {
            foreach (['branch_id', 'updated_by'] as $column) {
                if (Schema::hasColumn('investment_plans', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }

            foreach (['min_amount', 'max_amount', 'return_method', 'allow_topups', 'early_withdrawal_penalty_type', 'early_withdrawal_penalty_value', 'auto_reinvest'] as $column) {
                if (Schema::hasColumn('investment_plans', $column)) {
                    $table->dropColumn($column);
                }
            }

            if (Schema::hasColumn('investment_plans', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
