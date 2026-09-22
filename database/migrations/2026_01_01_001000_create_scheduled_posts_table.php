<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->nullable()->constrained()->nullOnDelete();

            $table->string('platform', 32);
            $table->string('mode', 16)->default('reminder'); // auto | reminder
            $table->timestamp('scheduled_at');
            $table->string('status', 16)->default('queued'); // queued | publishing | published | failed | cancelled

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('platform_post_id')->nullable();
            $table->string('permalink')->nullable();
            $table->json('response')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index(['brand_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_posts');
    }
};
