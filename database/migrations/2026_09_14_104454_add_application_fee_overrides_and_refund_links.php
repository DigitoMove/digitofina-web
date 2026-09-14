<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->decimal('application_fee_override', 18, 2)->nullable();
            $table->text('application_fee_override_reason')->nullable();
        });
        Schema::table('transactions', function (Blueprint $table): void {
            $table->foreignId('application_fee_disbursement_id')->nullable()->constrained('loan_disbursements')->nullOnDelete();
            $table->foreignId('fee_refund_of_id')->nullable()->constrained('transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fee_refund_of_id');
            $table->dropConstrainedForeignId('application_fee_disbursement_id');
        });
        Schema::table('loans', function (Blueprint $table): void {
            $table->dropColumn(['application_fee_override', 'application_fee_override_reason']);
        });
    }
};
