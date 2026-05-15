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
        Schema::table('clients', function (Blueprint $table) {
            $table->string('local_temp_id', 120)->nullable()->after('client_number');
            $table->string('sync_source', 40)->default('web')->after('local_temp_id')->index();
            $table->timestamp('client_modified_at')->nullable()->after('sync_source');
            $table->timestamp('synced_at')->nullable()->after('client_modified_at');
            $table->unsignedBigInteger('sync_version')->default(1)->after('synced_at');

            $table->unique(['organization_id', 'local_temp_id']);
            $table->index(['organization_id', 'branch_id', 'updated_at']);
            $table->index(['local_temp_id', 'sync_source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'local_temp_id']);
            $table->dropIndex(['organization_id', 'branch_id', 'updated_at']);
            $table->dropIndex(['local_temp_id', 'sync_source']);
            $table->dropColumn(['local_temp_id', 'sync_source', 'client_modified_at', 'synced_at', 'sync_version']);
        });
    }
};
