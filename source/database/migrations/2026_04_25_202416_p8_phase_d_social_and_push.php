<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P8 Phase D — distribution channels.
 *
 *   social_posts        per-channel attempt log when an article is
 *                       auto-posted to a social network on publish.
 *                       Idempotent on (news_id, channel) so a re-
 *                       publish doesn't double-post.
 *
 *   push_subscriptions  Web Push endpoints visitors register from
 *                       the public site. Used by the breaking-news
 *                       broadcaster to send notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('social_posts')) {
            Schema::create('social_posts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('news_id')->index();
                $table->string('channel', 32);                   // mastodon, bluesky, etc.
                $table->string('status', 16)->default('queued'); // queued | sent | error
                $table->string('post_url', 500)->nullable();
                $table->text('response')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('posted_at')->nullable();
                $table->timestamps();
                $table->unique(['news_id', 'channel']);
            });
        }

        if (! Schema::hasTable('push_subscriptions')) {
            Schema::create('push_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('endpoint', 500)->unique();
                $table->string('p256dh', 200);
                $table->string('auth', 100);
                $table->string('user_agent', 255)->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('social_posts');
    }
};
