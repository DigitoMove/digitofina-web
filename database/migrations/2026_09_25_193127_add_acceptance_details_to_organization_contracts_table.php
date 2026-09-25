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
        Schema::table('organization_contracts', function (Blueprint $table) {
            if (! Schema::hasColumn('organization_contracts', 'accepted_at')) {
                $table->timestamp('accepted_at')->nullable()->after('approved_at');
            }
            if (! Schema::hasColumn('organization_contracts', 'accepted_by_name')) {
                $table->string('accepted_by_name', 120)->nullable()->after('accepted_at');
            }
            if (! Schema::hasColumn('organization_contracts', 'accepted_by_email')) {
                $table->string('accepted_by_email', 190)->nullable()->after('accepted_by_name');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organization_contracts', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('organization_contracts', 'accepted_at') ? 'accepted_at' : null,
                Schema::hasColumn('organization_contracts', 'accepted_by_name') ? 'accepted_by_name' : null,
                Schema::hasColumn('organization_contracts', 'accepted_by_email') ? 'accepted_by_email' : null,
            ]);
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
