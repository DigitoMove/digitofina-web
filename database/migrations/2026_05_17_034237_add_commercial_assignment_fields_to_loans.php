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
        Schema::table('loans', function (Blueprint $table) {
            if (! Schema::hasColumn('loans', 'loan_type')) {
                $table->string('loan_type', 40)->default('standard')->after('loan_product_id')->index();
            }

            if (! Schema::hasColumn('loans', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('officer_id')->constrained('users')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            if (Schema::hasColumn('loans', 'created_by')) {
                $table->dropConstrainedForeignId('created_by');
            }

            if (Schema::hasColumn('loans', 'loan_type')) {
                $table->dropColumn('loan_type');
            }
        });
    }
};
