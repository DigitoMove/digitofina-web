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
        Schema::create('chart_account_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('scope', 20)->index();
            $table->string('code', 30);
            $table->string('name');
            $table->string('type', 30)->index();
            $table->string('normal_balance', 10);
            $table->string('drawer_direction', 20)->nullable();
            $table->boolean('is_cash_account')->default(false);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unique(['scope', 'code']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chart_account_templates');
    }
};
