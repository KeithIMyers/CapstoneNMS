<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P13 Phase A.2 — passwordless sign-in via emailed magic link.
 *
 * Tokens are stored as sha256 hashes (mirrors the password_resets
 * design from Phase A.1) so a row dump can't be replayed to sign in
 * as anyone. `used_at` marks single-use; `expires_at` is the hard
 * cutoff (15 minutes by default). Throttling lives at the route via
 * the existing `throttle:auth` named limiter.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('magic_links')) {
            Schema::create('magic_links', function (Blueprint $table) {
                $table->id();
                $table->string('email')->index();
                $table->string('token_hash', 64);
                $table->timestamp('expires_at')->index();
                $table->timestamp('used_at')->nullable();
                $table->string('request_ip', 45)->nullable();
                $table->string('request_ua', 255)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['email', 'used_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('magic_links');
    }
};
