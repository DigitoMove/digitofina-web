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
        Schema::table('organization_subscriptions', function (Blueprint $table) {
            $table->string('activation_source', 40)->default('nylon_pay')->after('status')->index();
            $table->string('payment_status', 30)->default('paid')->after('activation_source')->index();
            $table->foreignId('assigned_by')->nullable()->after('provider_reference')->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable()->after('assigned_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organization_subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_by');
            $table->dropColumn(['activation_source', 'payment_status', 'notes']);
        });
    }
};
