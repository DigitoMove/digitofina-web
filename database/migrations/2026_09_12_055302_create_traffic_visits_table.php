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
        Schema::create('traffic_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            // Keep DNS values within the indexed length supported by MySQL
            // installations configured with utf8mb4 and a 1000-byte key limit.
            $table->string('host', 191);
            $table->string('domain', 191);
            $table->string('subdomain', 191)->nullable();
            $table->string('visitor_hash', 64);
            $table->boolean('is_demo')->default(false);
            $table->string('audience', 20)->default('guest');
            $table->string('device', 20)->default('desktop');
            $table->unsignedInteger('page_views')->default(1);
            $table->unsignedInteger('active_seconds')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->index(['started_at', 'host']);
            $table->index(['visitor_hash', 'last_seen_at'], 'traffic_visitor_seen');
            $table->index(['organization_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('traffic_visits');
    }
};
