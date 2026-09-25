<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['organization_id' => null, 'key' => 'backup_mail_to'],
            [
                'label' => 'Backup recipient emails',
                'value' => '',
                'type' => 'string',
                'group' => 'backups',
                'description' => 'Comma-separated email addresses that receive the daily database backup archive. Super Administrator platform setting.',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('system_settings')->whereNull('organization_id')->where('key', 'backup_mail_to')->delete();
    }
};
