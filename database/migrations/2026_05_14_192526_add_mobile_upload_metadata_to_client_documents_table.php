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
        Schema::table('client_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('client_documents', 'original_name')) {
                $table->string('original_name')->nullable()->after('title');
                $table->string('content_hash', 128)->nullable()->after('size')->index();
                $table->unsignedInteger('image_width')->nullable()->after('content_hash');
                $table->unsignedInteger('image_height')->nullable()->after('image_width');
                $table->json('compression_metadata')->nullable()->after('image_height');
                $table->uuid('local_uuid')->nullable()->after('compression_metadata')->index();
                $table->timestamp('captured_at')->nullable()->after('local_uuid');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_documents', function (Blueprint $table) {
            if (Schema::hasColumn('client_documents', 'original_name')) {
                $table->dropColumn([
                    'original_name',
                    'content_hash',
                    'image_width',
                    'image_height',
                    'compression_metadata',
                    'local_uuid',
                    'captured_at',
                ]);
            }
        });
    }
};
