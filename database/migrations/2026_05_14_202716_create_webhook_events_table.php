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
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('webhook_provider_id')->nullable()->index();
            $table->unsignedBigInteger('duplicate_of_id')->nullable()->index();
            $table->string('source_type', 40)->index();
            $table->string('provider_name', 120)->index();
            $table->string('provider_code', 80)->nullable()->index();
            $table->string('event_id', 191)->nullable()->index();
            $table->string('duplicate_key', 191)->index();
            $table->string('payload_hash', 64)->index();
            $table->json('headers');
            $table->longText('raw_payload');
            $table->json('payload')->nullable();
            $table->string('signature_header')->nullable();
            $table->text('signature')->nullable();
            $table->boolean('signature_valid')->nullable()->index();
            $table->string('status', 30)->default('received')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_retry_at')->nullable()->index();
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('processing_result')->nullable();
            $table->timestamps();
            $table->index(['source_type', 'provider_code', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
