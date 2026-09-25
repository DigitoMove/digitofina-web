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
        Schema::create('contract_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_contract_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->text('comment');
            $table->string('status', 20)->default('open')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['organization_contract_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_comments');
    }
};
