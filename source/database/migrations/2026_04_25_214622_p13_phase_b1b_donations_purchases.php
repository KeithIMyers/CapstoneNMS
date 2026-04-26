<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P13 Phase B.1b — donations + pay-per-article.
 *
 *   donations             one row per Stripe Checkout (one-time charge)
 *                         from the public /donate page. Tracks the
 *                         charge id + the optional message a donor can
 *                         attach.
 *
 *   article_purchases     one row per non-subscriber buying access to
 *                         one premium article. Paywall::canRead checks
 *                         this table after the gift gate.
 *
 * Both tables hold `status` (pending → succeeded | failed) so the Stripe
 * webhook listener has somewhere to write back. Pre-webhook rows stay
 * `pending` so the user gets a clean failure mode if Stripe drops.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('donations')) {
            Schema::create('donations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('email', 255)->nullable();
                $table->string('name', 120)->nullable();
                $table->unsignedInteger('amount_cents');
                $table->string('currency', 8)->default('USD');
                $table->string('stripe_session_id', 191)->nullable()->unique();
                $table->string('stripe_charge_id', 191)->nullable()->index();
                $table->string('status', 16)->default('pending');   // pending|succeeded|failed
                $table->text('message')->nullable();
                $table->boolean('anonymous')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('article_purchases')) {
            Schema::create('article_purchases', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('news_id')->index();
                $table->unsignedInteger('amount_cents');
                $table->string('currency', 8)->default('USD');
                $table->string('stripe_session_id', 191)->nullable()->unique();
                $table->string('stripe_charge_id', 191)->nullable()->index();
                $table->string('status', 16)->default('pending');   // pending|succeeded|failed
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'news_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('article_purchases');
        Schema::dropIfExists('donations');
    }
};
