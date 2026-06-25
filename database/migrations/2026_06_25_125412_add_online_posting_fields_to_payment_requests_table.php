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
        Schema::table('payment_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_requests', 'posted_at')) {
                $table->timestamp('posted_at')->nullable()->after('expires_at');
            }

            if (! Schema::hasColumn('payment_requests', 'posted_transaction_type')) {
                $table->nullableMorphs('posted_transaction');
            }

            if (! Schema::hasColumn('payment_requests', 'posting_error')) {
                $table->text('posting_error')->nullable()->after('metadata');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            if (Schema::hasColumn('payment_requests', 'posted_transaction_type') && Schema::hasColumn('payment_requests', 'posted_transaction_id')) {
                $table->dropMorphs('posted_transaction');
            }

            if (Schema::hasColumn('payment_requests', 'posting_error')) {
                $table->dropColumn('posting_error');
            }

            if (Schema::hasColumn('payment_requests', 'posted_at')) {
                $table->dropColumn('posted_at');
            }
        });
    }
};
