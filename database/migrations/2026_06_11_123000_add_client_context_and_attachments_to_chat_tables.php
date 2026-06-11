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
        if (Schema::hasTable('chat_conversations') && ! Schema::hasColumn('chat_conversations', 'client_id')) {
            Schema::table('chat_conversations', function (Blueprint $table): void {
                $table->foreignId('client_id')->nullable()->after('loan_id')->constrained()->nullOnDelete();
            });
        }

        if (Schema::hasTable('chat_messages') && ! Schema::hasColumn('chat_messages', 'attachment_path')) {
            Schema::table('chat_messages', function (Blueprint $table): void {
                $table->string('attachment_path')->nullable()->after('body');
                $table->string('attachment_disk', 40)->nullable()->after('attachment_path');
                $table->string('attachment_name')->nullable()->after('attachment_disk');
                $table->string('attachment_mime', 120)->nullable()->after('attachment_name');
                $table->unsignedBigInteger('attachment_size')->nullable()->after('attachment_mime');
                $table->string('attachment_type', 30)->nullable()->after('attachment_size');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('chat_messages') && Schema::hasColumn('chat_messages', 'attachment_path')) {
            Schema::table('chat_messages', function (Blueprint $table): void {
                $table->dropColumn([
                    'attachment_path',
                    'attachment_disk',
                    'attachment_name',
                    'attachment_mime',
                    'attachment_size',
                    'attachment_type',
                ]);
            });
        }

        if (Schema::hasTable('chat_conversations') && Schema::hasColumn('chat_conversations', 'client_id')) {
            Schema::table('chat_conversations', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('client_id');
            });
        }
    }
};
