<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'account_types',
            'transaction_types',
            'teller_drawers',
            'drawer_sessions',
            'drawer_movements',
            'cash_denominations',
            'approvals',
            'loan_schedules',
            'loan_repayments',
        ] as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'organization_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->foreignId('organization_id')->nullable()->after('id')->constrained()->nullOnDelete();
                });
            }
        }

        foreach (['branches', 'users', 'roles', 'system_settings'] as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'deleted_at')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->softDeletes();
                });
            }
        }

        if (Schema::hasTable('branches') && ! Schema::hasColumn('branches', 'created_by')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->foreignId('created_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            });
        }

        if (Schema::hasTable('system_settings') && ! Schema::hasColumn('system_settings', 'description')) {
            Schema::table('system_settings', function (Blueprint $table) {
                $table->string('label')->nullable()->after('key');
                $table->text('description')->nullable()->after('type');
                $table->foreignId('updated_by')->nullable()->after('group')->constrained('users')->nullOnDelete();
            });
        }

        if (Schema::hasTable('audit_logs') && ! Schema::hasColumn('audit_logs', 'module')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->string('module', 80)->nullable()->after('action')->index();
                $table->string('record_type')->nullable()->after('module');
                $table->unsignedBigInteger('record_id')->nullable()->after('record_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('audit_logs') && Schema::hasColumn('audit_logs', 'module')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->dropColumn(['module', 'record_type', 'record_id']);
            });
        }

        if (Schema::hasTable('system_settings') && Schema::hasColumn('system_settings', 'description')) {
            Schema::table('system_settings', function (Blueprint $table) {
                $table->dropConstrainedForeignId('updated_by');
                $table->dropColumn(['label', 'description']);
            });
        }

        if (Schema::hasTable('branches') && Schema::hasColumn('branches', 'created_by')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->dropConstrainedForeignId('updated_by');
                $table->dropConstrainedForeignId('created_by');
            });
        }

        foreach (['system_settings', 'roles', 'users', 'branches'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'deleted_at')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropSoftDeletes();
                });
            }
        }

        foreach ([
            'loan_repayments',
            'loan_schedules',
            'approvals',
            'cash_denominations',
            'drawer_movements',
            'drawer_sessions',
            'teller_drawers',
            'transaction_types',
            'account_types',
        ] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'organization_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('organization_id');
                });
            }
        }
    }
};
