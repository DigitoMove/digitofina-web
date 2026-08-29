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
        Schema::table('nylon_pay_credentials', function (Blueprint $table) {
            $table->dropUnique('nylon_pay_credentials_profile_unique');
            $table->foreignId('organization_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->string('scope_key')->nullable()->after('organization_id')->unique();
            $table->string('webhook_token', 64)->nullable()->after('webhook_secret')->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nylon_pay_credentials', function (Blueprint $table) {
            $table->dropUnique(['webhook_token']);
            $table->dropUnique(['scope_key']);
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['scope_key', 'webhook_token']);
            $table->unique('profile');
        });
    }
};
