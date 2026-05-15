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
        Schema::create('offline_sync_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offline_sync_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('resource_type', 80)->index();
            $table->string('action', 30)->default('create');
            $table->string('local_temp_id', 120)->nullable()->index();
            $table->string('server_record_type')->nullable();
            $table->unsignedBigInteger('server_record_id')->nullable();
            $table->timestamp('client_updated_at')->nullable();
            $table->json('payload');
            $table->string('status', 30)->default('accepted')->index();
            $table->json('validation_errors')->nullable();
            $table->json('conflict_data')->nullable();
            $table->json('response_data')->nullable();
            $table->timestamps();

            $table->index(['server_record_type', 'server_record_id']);
            $table->index(['organization_id', 'resource_type', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offline_sync_records');
    }
};
