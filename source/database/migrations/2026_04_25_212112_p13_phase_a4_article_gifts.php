<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P13 Phase A.4 — gift articles.
 *
 * A subscriber can mint a single-use link that grants a non-subscriber
 * full access to one premium article. Tokens are stored as sha256
 * hashes (mirrors password_resets / magic_links). Per-subscriber
 * monthly cap is enforced by counting rows in this table.
 *
 * `redeemed_at` flips on first redemption; subsequent visits from the
 * same browser stay entitled via a session flag set in NewsController.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('article_gifts')) {
            Schema::create('article_gifts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('news_id')->index();
                $table->unsignedBigInteger('gifter_user_id')->index();
                $table->string('recipient_email', 255)->nullable();
                $table->string('token_hash', 64)->unique();
                $table->timestamp('expires_at')->index();
                $table->timestamp('redeemed_at')->nullable();
                $table->string('redeemed_ip', 45)->nullable();
                $table->timestamps();
                $table->index(['gifter_user_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('article_gifts');
    }
};
