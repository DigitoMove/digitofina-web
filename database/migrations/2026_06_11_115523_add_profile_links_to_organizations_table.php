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
            if (! Schema::hasColumn('organizations', 'website')) {
                $table->string('website')->nullable()->after('email');
            }

            if (! Schema::hasColumn('organizations', 'social_links')) {
                $table->json('social_links')->nullable()->after('website');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            if (Schema::hasColumn('organizations', 'social_links')) {
                $table->dropColumn('social_links');
            }

            if (Schema::hasColumn('organizations', 'website')) {
                $table->dropColumn('website');
            }
        });
    }
};
