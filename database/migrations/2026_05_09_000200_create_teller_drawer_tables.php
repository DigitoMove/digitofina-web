<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teller_drawers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('code', 40)->unique();
            $table->string('currency', 10)->default('UGX');
            $table->decimal('current_balance', 18, 2)->default(0);
            $table->string('status', 30)->default('closed')->index();
            $table->timestamps();
        });

        Schema::create('drawer_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teller_drawer_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->decimal('opening_balance', 18, 2)->default(0);
            $table->decimal('closing_balance', 18, 2)->nullable();
            $table->decimal('expected_balance', 18, 2)->nullable();
            $table->decimal('variance', 18, 2)->nullable();
            $table->string('status', 30)->default('open')->index();
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('drawer_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drawer_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 40)->index();
            $table->decimal('amount', 18, 2);
            $table->decimal('balance_after', 18, 2);
            $table->string('reference', 60)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('cash_denominations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drawer_session_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('currency', 10)->default('UGX');
            $table->integer('denomination');
            $table->integer('quantity')->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->string('context', 30)->default('closing');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_denominations');
        Schema::dropIfExists('drawer_movements');
        Schema::dropIfExists('drawer_sessions');
        Schema::dropIfExists('teller_drawers');
    }
};
