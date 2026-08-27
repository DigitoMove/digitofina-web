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
        Schema::table('organizations', function (Blueprint $table): void {
            $table->boolean('is_demo')->default(false)->after('status')->index();
            $table->timestamp('demo_started_at')->nullable()->after('is_demo');
            $table->timestamp('demo_expires_at')->nullable()->after('demo_started_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropIndex(['demo_expires_at']);
            $table->dropIndex(['is_demo']);
            $table->dropColumn(['is_demo', 'demo_started_at', 'demo_expires_at']);
        });
    }
};
