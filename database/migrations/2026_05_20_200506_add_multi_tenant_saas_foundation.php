<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('organizations')) {
            Schema::table('organizations', function (Blueprint $table): void {
                if (! Schema::hasColumn('organizations', 'logo')) {
                    $table->string('logo')->nullable()->after('slug');
                }

                if (! Schema::hasColumn('organizations', 'timezone')) {
                    $table->string('timezone', 80)->default('Africa/Kampala')->after('package_id');
                }
            });

            if (Schema::hasColumn('organizations', 'logo_path') && Schema::hasColumn('organizations', 'logo')) {
                DB::table('organizations')
                    ->whereNull('logo')
                    ->whereNotNull('logo_path')
                    ->update(['logo' => DB::raw('logo_path')]);
            }
        }

        if (! Schema::hasTable('organization_domains')) {
            Schema::create('organization_domains', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->string('domain')->unique();
                $table->string('type', 30)->default('subdomain')->index();
                $table->boolean('is_primary')->default(false);
                $table->string('status', 30)->default('active')->index();
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();

                $table->index(['organization_id', 'status']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_domains');

        if (Schema::hasTable('organizations')) {
            Schema::table('organizations', function (Blueprint $table): void {
                if (Schema::hasColumn('organizations', 'timezone')) {
                    $table->dropColumn('timezone');
                }

                if (Schema::hasColumn('organizations', 'logo')) {
                    $table->dropColumn('logo');
                }
            });
        }
    }
};
