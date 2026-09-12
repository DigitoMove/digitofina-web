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
        if (Schema::hasTable('traffic_visits')) {
            Schema::table('traffic_visits', function (Blueprint $table): void {
                if (! Schema::hasIndex('traffic_visits', 'traffic_visits_started_at_index')) {
                    $table->index('started_at', 'traffic_visits_started_at_index');
                }

                if (! Schema::hasIndex('traffic_visits', 'traffic_visitor_seen')) {
                    $table->index(['visitor_hash', 'last_seen_at'], 'traffic_visitor_seen');
                }

                if (! Schema::hasIndex('traffic_visits', 'traffic_visits_organization_id_started_at_index')) {
                    $table->index(['organization_id', 'started_at'], 'traffic_visits_organization_id_started_at_index');
                }
            });

            return;
        }

        Schema::create('traffic_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            // Host values are deliberately not part of a composite index.
            // Existing installations may have a 253-character host column,
            // which exceeds this MySQL server's 1000-byte index limit.
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
            $table->index('started_at', 'traffic_visits_started_at_index');
            $table->index(['visitor_hash', 'last_seen_at'], 'traffic_visitor_seen');
            $table->index(['organization_id', 'started_at'], 'traffic_visits_organization_id_started_at_index');
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
