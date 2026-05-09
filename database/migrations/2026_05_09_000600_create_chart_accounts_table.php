<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('chart_accounts')->nullOnDelete();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('type', 30)->index();
            $table->string('normal_balance', 10);
            $table->boolean('is_cash_account')->default(false);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('chart_account_id')->nullable()->after('transaction_id')->constrained('chart_accounts')->nullOnDelete();
            $table->index(['chart_account_id', 'entry_type']);
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chart_account_id');
            $table->dropIndex(['chart_account_id', 'entry_type']);
        });

        Schema::dropIfExists('chart_accounts');
    }
};
