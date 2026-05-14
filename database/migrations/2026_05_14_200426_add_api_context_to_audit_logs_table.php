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
        Schema::table('audit_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('audit_logs', 'branch_id')) {
                $table->foreignId('branch_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
            }

            if (! Schema::hasColumn('audit_logs', 'endpoint')) {
                $table->string('endpoint')->nullable()->after('action')->index();
                $table->string('request_method', 12)->nullable()->after('endpoint')->index();
                $table->string('device_name')->nullable()->after('user_agent');
                $table->unsignedSmallInteger('response_status')->nullable()->after('request_method');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            if (Schema::hasColumn('audit_logs', 'branch_id')) {
                $table->dropConstrainedForeignId('branch_id');
            }

            if (Schema::hasColumn('audit_logs', 'endpoint')) {
                $table->dropColumn(['endpoint', 'request_method', 'device_name', 'response_status']);
            }
        });
    }
};
