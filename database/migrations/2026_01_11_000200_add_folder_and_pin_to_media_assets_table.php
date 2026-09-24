<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->foreignId('folder_id')->nullable()->after('content_item_id')
                ->constrained('media_folders')->nullOnDelete();
            $table->boolean('is_pinned')->default(false)->after('folder_id');

            $table->index(['brand_id', 'folder_id']);
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folder_id');
            $table->dropColumn('is_pinned');
        });
    }
};
