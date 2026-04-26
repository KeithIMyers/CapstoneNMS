<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P13 Phase A.3b — newsletter analytics ledgers.
 *
 *   newsletter_issues   one row per blast (e.g. one daily brief).
 *                       Subject, product, sender, when, summary stats.
 *   newsletter_sends    one row per recipient × issue. Provider
 *                       message-id (so webhook events can find the
 *                       matching row), per-recipient event counters,
 *                       and the bounced/complained/unsubscribed
 *                       lifecycle bits used to decide whether the
 *                       email is still mailable next time.
 *
 * Webhook receivers (PostmarkWebhookController, MailgunWebhookController)
 * land event-level data on these counters. We deliberately don't keep
 * a per-event row table — the volume isn't useful and the counters
 * are what every dashboard reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('newsletter_issues')) {
            Schema::create('newsletter_issues', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id')->nullable()->index();
                $table->string('subject', 255);
                $table->string('sender_email', 255)->nullable();
                $table->text('body_summary')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->unsignedInteger('queued_count')->default(0);
                $table->unsignedInteger('sent_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('newsletter_sends')) {
            Schema::create('newsletter_sends', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('issue_id')->index();
                $table->unsignedBigInteger('subscription_id')->nullable()->index();
                $table->string('email', 255)->index();
                $table->string('provider', 24)->nullable();              // postmark | mailgun | smtp
                $table->string('provider_message_id', 191)->nullable();  // X-PM-Message-Id / Mailgun message-id
                $table->string('status', 24)->default('queued');         // queued|sent|delivered|bounced|complained|failed
                $table->unsignedInteger('opens_count')->default(0);
                $table->unsignedInteger('clicks_count')->default(0);
                $table->timestamp('first_opened_at')->nullable();
                $table->timestamp('last_event_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('bounced_at')->nullable();
                $table->timestamp('complained_at')->nullable();
                $table->timestamp('unsubscribed_at')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();

                // Webhook handlers look up by message id to attribute
                // events back to the right send.
                $table->index(['provider', 'provider_message_id'], 'newsletter_sends_provider_msgid_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_sends');
        Schema::dropIfExists('newsletter_issues');
    }
};
