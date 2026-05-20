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
        Schema::table('savings_accounts', function (Blueprint $table) {
            $table->decimal('locked_amount', 18, 2)->default(0)->after('available_balance');
        });

        Schema::table('investment_accounts', function (Blueprint $table) {
            $table->decimal('locked_amount', 18, 2)->default(0)->after('current_balance');
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->decimal('locked_savings_amount', 18, 2)->default(0)->after('required_savings_amount');
            $table->decimal('locked_investment_amount', 18, 2)->default(0)->after('locked_savings_amount');
            $table->boolean('auto_repay_from_investment_returns')->default(false)->after('locked_investment_amount');
        });

        Schema::create('loan_securities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('savings_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('investment_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 40)->index();
            $table->decimal('amount', 18, 2);
            $table->decimal('locked_amount', 18, 2);
            $table->string('status', 40)->default('active')->index();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['loan_id', 'status']);
            $table->index(['client_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_securities');

        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['locked_savings_amount', 'locked_investment_amount', 'auto_repay_from_investment_returns']);
        });

        Schema::table('investment_accounts', function (Blueprint $table) {
            $table->dropColumn('locked_amount');
        });

        Schema::table('savings_accounts', function (Blueprint $table) {
            $table->dropColumn('locked_amount');
        });
    }
};
