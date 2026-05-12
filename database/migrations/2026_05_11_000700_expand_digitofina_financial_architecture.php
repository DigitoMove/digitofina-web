<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organizations')) {
            Schema::create('organizations', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('registration_number')->nullable();
                $table->string('tax_identification_number')->nullable();
                $table->string('logo_path')->nullable();
                $table->string('phone', 40)->nullable();
                $table->string('email')->nullable();
                $table->text('address')->nullable();
                $table->string('currency', 10)->default('UGX');
                $table->string('financial_year_start_month', 20)->default('january');
                $table->json('working_days')->nullable();
                $table->json('interest_settings')->nullable();
                $table->json('sms_settings')->nullable();
                $table->json('mobile_money_settings')->nullable();
                $table->string('status', 30)->default('active')->index();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        $this->addTenantColumns();
        $this->addClientProfileColumns();
        $this->createClientKycTables();
        $this->createSavingsTables();
        $this->createLoanWorkflowTables();
        $this->createSharesAndFixedDepositTables();
        $this->createAccountingTables();
        $this->createIntegrationTables();
        $this->createOperationalTables();
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('document_records');
        Schema::dropIfExists('sms_logs');
        Schema::dropIfExists('sms_providers');
        Schema::dropIfExists('mobile_money_transactions');
        Schema::dropIfExists('payment_callbacks');
        Schema::dropIfExists('payment_requests');
        Schema::dropIfExists('payment_providers');
        Schema::dropIfExists('journal_lines');
        $this->dropJournalHeaderColumns();
        Schema::dropIfExists('fixed_deposit_transactions');
        Schema::dropIfExists('fixed_deposit_accounts');
        Schema::dropIfExists('fixed_deposit_products');
        Schema::dropIfExists('share_transactions');
        Schema::dropIfExists('share_accounts');
        Schema::dropIfExists('share_products');
        Schema::dropIfExists('loan_restructures');
        Schema::dropIfExists('loan_write_offs');
        Schema::dropIfExists('loan_penalties');
        Schema::dropIfExists('loan_disbursements');
        Schema::dropIfExists('loan_approvals');
        Schema::dropIfExists('collaterals');
        Schema::dropIfExists('guarantors');
        Schema::dropIfExists('savings_transactions');
        Schema::dropIfExists('savings_accounts');
        Schema::dropIfExists('savings_products');
        Schema::dropIfExists('client_notes');
        Schema::dropIfExists('client_documents');
        Schema::dropIfExists('client_group_members');
        Schema::dropIfExists('client_groups');

        $this->dropClientProfileColumns();
        $this->dropTenantColumns();

        Schema::dropIfExists('organizations');
    }

    private function addTenantColumns(): void
    {
        foreach (['branches', 'roles', 'permissions', 'users', 'clients', 'accounts', 'transactions', 'loan_products', 'loans', 'audit_logs', 'system_settings', 'chart_accounts'] as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'organization_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->foreignId('organization_id')->nullable()->after('id')->constrained()->nullOnDelete();
                });
            }
        }

        if (Schema::hasTable('branches') && ! Schema::hasColumn('branches', 'manager_user_id')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->foreignId('manager_user_id')->nullable()->after('organization_id')->constrained('users')->nullOnDelete();
                $table->string('status', 30)->default('active')->after('is_active')->index();
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'status')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('status', 30)->default('active')->after('is_active')->index();
                $table->string('two_factor_secret')->nullable()->after('remember_token');
                $table->timestamp('last_login_at')->nullable()->after('two_factor_secret');
            });
        }
    }

    private function dropTenantColumns(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'status')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn(['status', 'two_factor_secret', 'last_login_at']);
            });
        }

        if (Schema::hasTable('branches') && Schema::hasColumn('branches', 'manager_user_id')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->dropConstrainedForeignId('manager_user_id');
                $table->dropColumn('status');
            });
        }

        foreach (['chart_accounts', 'system_settings', 'audit_logs', 'loans', 'loan_products', 'transactions', 'accounts', 'clients', 'users', 'permissions', 'roles', 'branches'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'organization_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('organization_id');
                });
            }
        }
    }

    private function addClientProfileColumns(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'client_type')) {
                $table->string('client_type', 30)->default('individual')->after('user_id')->index();
                $table->string('middle_name')->nullable()->after('first_name');
                $table->string('gender', 30)->nullable()->after('last_name');
                $table->date('date_of_birth')->nullable()->after('gender');
                $table->string('nationality', 80)->nullable()->after('date_of_birth');
                $table->string('alternative_phone', 40)->nullable()->after('phone');
                $table->string('occupation')->nullable()->after('email');
                $table->string('employer_name')->nullable()->after('occupation');
                $table->string('marital_status', 40)->nullable()->after('employer_name');
                $table->string('village')->nullable()->after('address');
                $table->string('parish')->nullable()->after('village');
                $table->string('sub_county')->nullable()->after('parish');
                $table->string('district')->nullable()->after('sub_county');
                $table->string('region')->nullable()->after('district');
                $table->decimal('gps_latitude', 10, 7)->nullable()->after('region');
                $table->decimal('gps_longitude', 10, 7)->nullable()->after('gps_latitude');
                $table->string('risk_rating', 30)->nullable()->after('kyc_status')->index();
                $table->timestamp('closed_at')->nullable()->after('registered_at');
                $table->softDeletes();
            }
        });
    }

    private function dropClientProfileColumns(): void
    {
        if (! Schema::hasColumn('clients', 'client_type')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'client_type',
                'middle_name',
                'gender',
                'date_of_birth',
                'nationality',
                'alternative_phone',
                'occupation',
                'employer_name',
                'marital_status',
                'village',
                'parish',
                'sub_county',
                'district',
                'region',
                'gps_latitude',
                'gps_longitude',
                'risk_rating',
                'closed_at',
                'deleted_at',
            ]);
        });
    }

    private function createClientKycTables(): void
    {
        Schema::create('client_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('chairperson_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('secretary_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('treasurer_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('group_number', 50)->unique();
            $table->string('name');
            $table->string('registration_number')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('address')->nullable();
            $table->string('meeting_day', 30)->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('client_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('role', 60)->default('member');
            $table->date('joined_at')->nullable();
            $table->date('left_at')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();
            $table->unique(['client_group_id', 'client_id']);
        });

        Schema::create('client_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 80)->index();
            $table->string('title');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('verification_status', 30)->default('pending')->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('client_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 60)->default('general')->index();
            $table->text('note');
            $table->timestamps();
        });
    }

    private function createSavingsTables(): void
    {
        Schema::create('savings_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('code', 40)->unique();
            $table->decimal('minimum_opening_balance', 18, 2)->default(0);
            $table->decimal('minimum_balance', 18, 2)->default(0);
            $table->decimal('interest_rate', 8, 4)->default(0);
            $table->string('interest_posting_frequency', 30)->default('monthly');
            $table->decimal('withdrawal_charge', 18, 2)->default(0);
            $table->decimal('deposit_charge', 18, 2)->default(0);
            $table->decimal('monthly_maintenance_fee', 18, 2)->default(0);
            $table->string('account_number_prefix', 20)->default('SAV');
            $table->boolean('allows_overdraft')->default(false);
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('savings_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('savings_product_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('account_number', 50)->unique();
            $table->date('opening_date');
            $table->decimal('current_balance', 18, 2)->default(0);
            $table->decimal('available_balance', 18, 2)->default(0);
            $table->string('status', 30)->default('pending')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('savings_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('savings_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('teller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference', 70)->unique();
            $table->string('type', 40)->index();
            $table->decimal('amount', 18, 2);
            $table->decimal('charges', 18, 2)->default(0);
            $table->decimal('balance_before', 18, 2);
            $table->decimal('balance_after', 18, 2);
            $table->string('payment_method', 40)->default('cash');
            $table->text('narration')->nullable();
            $table->date('transaction_date');
            $table->date('value_date');
            $table->string('status', 30)->default('successful')->index();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
        });
    }

    private function createLoanWorkflowTables(): void
    {
        Schema::create('guarantors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('guarantor_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('type', 40)->default('existing_client')->index();
            $table->string('name');
            $table->string('phone', 40)->nullable();
            $table->string('national_id', 80)->nullable();
            $table->text('address')->nullable();
            $table->string('relationship')->nullable();
            $table->decimal('guaranteed_amount', 18, 2);
            $table->string('document_path')->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('collaterals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('valued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 60)->index();
            $table->text('description');
            $table->decimal('estimated_value', 18, 2)->default(0);
            $table->decimal('forced_sale_value', 18, 2)->default(0);
            $table->string('ownership_proof')->nullable();
            $table->string('document_path')->nullable();
            $table->string('photo_path')->nullable();
            $table->date('valuation_date')->nullable();
            $table->string('status', 40)->default('pending_verification')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('loan_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('approval_level')->default(1);
            $table->string('role_required', 80)->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->text('notes')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('loan_disbursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('disbursed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('disbursed_amount', 18, 2);
            $table->decimal('fees_deducted', 18, 2)->default(0);
            $table->decimal('net_amount', 18, 2);
            $table->string('payment_method', 40);
            $table->string('transaction_reference', 80)->unique();
            $table->date('disbursement_date');
            $table->string('status', 30)->default('successful')->index();
            $table->timestamps();
        });

        Schema::create('loan_penalties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('loan_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 18, 2);
            $table->integer('days_overdue')->default(0);
            $table->date('calculated_for_date');
            $table->string('status', 30)->default('pending')->index();
            $table->timestamps();
        });

        Schema::create('loan_write_offs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('principal_amount', 18, 2)->default(0);
            $table->decimal('interest_amount', 18, 2)->default(0);
            $table->decimal('fees_amount', 18, 2)->default(0);
            $table->decimal('penalty_amount', 18, 2)->default(0);
            $table->text('reason');
            $table->date('written_off_at');
            $table->string('status', 30)->default('approved')->index();
            $table->timestamps();
        });

        Schema::create('loan_restructures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('previous_terms');
            $table->json('new_terms');
            $table->text('reason');
            $table->date('effective_date');
            $table->string('status', 30)->default('approved')->index();
            $table->timestamps();
        });
    }

    private function createSharesAndFixedDepositTables(): void
    {
        Schema::create('share_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('code', 40)->unique();
            $table->decimal('share_value', 18, 2);
            $table->integer('minimum_shares')->default(1);
            $table->integer('maximum_shares')->nullable();
            $table->boolean('allows_transfer')->default(true);
            $table->boolean('dividend_eligible')->default(true);
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('share_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('share_product_id')->constrained()->restrictOnDelete();
            $table->string('account_number', 50)->unique();
            $table->integer('total_shares')->default(0);
            $table->decimal('share_value', 18, 2);
            $table->decimal('total_share_capital', 18, 2)->default(0);
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('share_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('share_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference', 70)->unique();
            $table->string('type', 40)->index();
            $table->integer('shares');
            $table->decimal('amount', 18, 2);
            $table->text('narration')->nullable();
            $table->string('status', 30)->default('successful')->index();
            $table->timestamps();
        });

        Schema::create('fixed_deposit_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('code', 40)->unique();
            $table->decimal('minimum_amount', 18, 2);
            $table->decimal('interest_rate', 8, 4);
            $table->integer('term_days');
            $table->string('interest_payment_frequency', 30)->default('maturity');
            $table->decimal('early_withdrawal_penalty_rate', 8, 4)->default(0);
            $table->boolean('allows_rollover')->default(true);
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('fixed_deposit_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('fixed_deposit_product_id')->constrained()->restrictOnDelete();
            $table->string('account_number', 50)->unique();
            $table->decimal('amount', 18, 2);
            $table->date('start_date');
            $table->date('maturity_date');
            $table->decimal('interest_accrued', 18, 2)->default(0);
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('fixed_deposit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_deposit_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference', 70)->unique();
            $table->string('type', 40)->index();
            $table->decimal('amount', 18, 2);
            $table->text('narration')->nullable();
            $table->string('status', 30)->default('successful')->index();
            $table->timestamps();
        });
    }

    private function createAccountingTables(): void
    {
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('chart_account_id')->constrained('chart_accounts')->restrictOnDelete();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->text('narration')->nullable();
            $table->timestamps();
        });

        if (Schema::hasTable('journal_entries') && ! Schema::hasColumn('journal_entries', 'journal_number')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->foreignId('organization_id')->nullable()->after('id')->constrained()->nullOnDelete();
                $table->foreignId('branch_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
                $table->foreignId('created_by')->nullable()->after('branch_id')->constrained('users')->nullOnDelete();
                $table->foreignId('posted_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
                $table->string('journal_number', 70)->nullable()->after('posted_by')->unique();
                $table->date('journal_date')->nullable()->after('journal_number');
                $table->text('description')->nullable()->after('journal_date');
                $table->string('status', 30)->default('draft')->after('description')->index();
                $table->timestamp('posted_at')->nullable()->after('status');
                $table->foreignId('reversed_entry_id')->nullable()->after('posted_at')->constrained('journal_entries')->nullOnDelete();
            });
        }
    }

    private function createIntegrationTables(): void
    {
        Schema::create('payment_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->string('type', 50)->default('mobile_money');
            $table->json('configuration')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('payment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_provider_id')->constrained()->restrictOnDelete();
            $table->nullableMorphs('payable');
            $table->string('reference', 80)->unique();
            $table->string('external_reference', 120)->nullable()->index();
            $table->string('payer_phone', 40)->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('currency', 10)->default('UGX');
            $table->string('status', 30)->default('initiated')->index();
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_callbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_reference', 120)->nullable()->index();
            $table->json('payload');
            $table->string('signature')->nullable();
            $table->string('status', 30)->default('received')->index();
            $table->timestamps();
        });

        Schema::create('mobile_money_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_request_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('allocatable');
            $table->string('provider_transaction_id')->nullable()->index();
            $table->string('phone_number', 40);
            $table->decimal('amount', 18, 2);
            $table->string('currency', 10)->default('UGX');
            $table->string('status', 30)->default('pending')->index();
            $table->json('raw_response')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sms_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->string('sender_id', 30)->nullable();
            $table->json('configuration')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sms_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->nullableMorphs('notifiable');
            $table->string('recipient', 40)->index();
            $table->string('type', 80)->index();
            $table->text('message');
            $table->decimal('cost', 18, 4)->nullable();
            $table->string('status', 30)->default('queued')->index();
            $table->timestamp('sent_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    private function createOperationalTables(): void
    {
        Schema::create('document_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->nullableMorphs('documentable');
            $table->string('type', 80)->index();
            $table->string('title');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('verification_status', 30)->default('pending')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('report_type', 80)->index();
            $table->json('filters')->nullable();
            $table->string('format', 20);
            $table->string('disk')->default('local');
            $table->string('path')->nullable();
            $table->string('status', 30)->default('queued')->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->json('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    private function dropJournalHeaderColumns(): void
    {
        if (! Schema::hasTable('journal_entries') || ! Schema::hasColumn('journal_entries', 'journal_number')) {
            return;
        }

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversed_entry_id');
            $table->dropConstrainedForeignId('posted_by');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['journal_number', 'journal_date', 'description', 'status', 'posted_at']);
        });
    }
};
