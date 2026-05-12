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
        Schema::table('loan_products', function (Blueprint $table) {
            if (! Schema::hasColumn('loan_products', 'description')) {
                $table->text('description')->nullable()->after('code');
                $table->unsignedInteger('min_term')->default(1)->after('max_amount');
                $table->unsignedInteger('max_term')->default(60)->after('min_term');
                $table->string('term_unit', 20)->default('months')->after('max_term');
                $table->string('interest_frequency', 20)->default('monthly')->after('interest_method');
                $table->string('penalty_type', 20)->default('percentage')->after('grace_period_days');
                $table->decimal('penalty_rate', 8, 4)->default(0)->after('penalty_type');
                $table->decimal('processing_fee', 18, 2)->default(0)->after('penalty_rate');
                $table->decimal('insurance_fee', 18, 2)->default(0)->after('processing_fee');
                $table->decimal('application_fee', 18, 2)->default(0)->after('insurance_fee');
                $table->decimal('required_savings_percentage', 8, 4)->default(0)->after('application_fee');
                $table->boolean('require_guarantors')->default(false)->after('required_savings_percentage');
                $table->unsignedInteger('minimum_guarantors')->default(0)->after('require_guarantors');
                $table->boolean('require_collateral')->default(false)->after('minimum_guarantors');
                $table->boolean('auto_deduct_repayment_from_savings')->default(false)->after('require_collateral');
                $table->string('loan_account_number_prefix', 20)->default('LN')->after('auto_deduct_repayment_from_savings');
                $table->string('status', 30)->default('active')->after('is_active')->index();
            }
        });

        Schema::table('loans', function (Blueprint $table) {
            if (! Schema::hasColumn('loans', 'source_of_repayment')) {
                $table->string('source_of_repayment')->nullable()->after('purpose');
                $table->text('business_details')->nullable()->after('source_of_repayment');
                $table->text('existing_loans')->nullable()->after('business_details');
                $table->decimal('credit_score', 5, 2)->nullable()->after('risk_score');
                $table->decimal('savings_balance_at_application', 18, 2)->default(0)->after('credit_score');
                $table->decimal('required_savings_amount', 18, 2)->default(0)->after('savings_balance_at_application');
                $table->date('application_date')->nullable()->after('required_savings_amount');
                $table->foreignId('reviewed_by')->nullable()->after('officer_id')->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->after('reviewed_by')->constrained('users')->nullOnDelete();
                $table->text('rejection_reason')->nullable()->after('officer_recommendation');
            }
        });

        Schema::table('loan_schedules', function (Blueprint $table) {
            if (! Schema::hasColumn('loan_schedules', 'principal_paid')) {
                $table->decimal('principal_paid', 18, 2)->default(0)->after('total_due');
                $table->decimal('interest_paid', 18, 2)->default(0)->after('principal_paid');
                $table->decimal('fees_paid', 18, 2)->default(0)->after('interest_paid');
                $table->decimal('penalty_paid', 18, 2)->default(0)->after('fees_paid');
                $table->decimal('total_paid', 18, 2)->default(0)->after('penalty_paid');
                $table->decimal('balance_due', 18, 2)->default(0)->after('outstanding_amount');
                $table->unsignedInteger('days_overdue')->default(0)->after('balance_due');
            }
        });

        Schema::table('loan_repayments', function (Blueprint $table) {
            if (! Schema::hasColumn('loan_repayments', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->after('id')->constrained()->nullOnDelete();
            }

            if (! Schema::hasColumn('loan_repayments', 'client_id')) {
                $table->foreignId('client_id')->nullable()->after('loan_id')->constrained()->nullOnDelete();
                $table->foreignId('branch_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
                $table->foreignId('received_by')->nullable()->after('transaction_id')->constrained('users')->nullOnDelete();
                $table->string('payment_method', 40)->default('cash')->after('amount');
                $table->string('reference', 80)->nullable()->after('payment_method')->index();
                $table->string('status', 30)->default('successful')->after('reference')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_repayments', function (Blueprint $table) {
            if (Schema::hasColumn('loan_repayments', 'organization_id')) {
                $table->dropConstrainedForeignId('organization_id');
            }

            if (Schema::hasColumn('loan_repayments', 'client_id')) {
                $table->dropConstrainedForeignId('client_id');
                $table->dropConstrainedForeignId('branch_id');
                $table->dropConstrainedForeignId('received_by');
                $table->dropColumn(['payment_method', 'reference', 'status']);
            }
        });

        Schema::table('loan_schedules', function (Blueprint $table) {
            if (Schema::hasColumn('loan_schedules', 'principal_paid')) {
                $table->dropColumn(['principal_paid', 'interest_paid', 'fees_paid', 'penalty_paid', 'total_paid', 'balance_due', 'days_overdue']);
            }
        });

        Schema::table('loans', function (Blueprint $table) {
            if (Schema::hasColumn('loans', 'source_of_repayment')) {
                $table->dropConstrainedForeignId('reviewed_by');
                $table->dropConstrainedForeignId('approved_by');
                $table->dropColumn(['source_of_repayment', 'business_details', 'existing_loans', 'credit_score', 'savings_balance_at_application', 'required_savings_amount', 'application_date', 'rejection_reason']);
            }
        });

        Schema::table('loan_products', function (Blueprint $table) {
            if (Schema::hasColumn('loan_products', 'description')) {
                $table->dropColumn([
                    'description',
                    'min_term',
                    'max_term',
                    'term_unit',
                    'interest_frequency',
                    'penalty_type',
                    'penalty_rate',
                    'processing_fee',
                    'insurance_fee',
                    'application_fee',
                    'required_savings_percentage',
                    'require_guarantors',
                    'minimum_guarantors',
                    'require_collateral',
                    'auto_deduct_repayment_from_savings',
                    'loan_account_number_prefix',
                    'status',
                ]);
            }
        });
    }
};
