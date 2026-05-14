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
        Schema::create('webhook_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('code', 80);
            $table->string('source_type', 40)->index();
            $table->string('signature_header')->default('X-Signature');
            $table->string('signature_algorithm', 20)->default('sha256');
            $table->text('signing_secret')->nullable();
            $table->json('configuration')->nullable();
            $table->boolean('requires_signature')->default(false);
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();
            $table->unique(['organization_id', 'source_type', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_providers');
    }
};
