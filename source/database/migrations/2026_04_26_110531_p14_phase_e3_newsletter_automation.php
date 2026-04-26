<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P14 Phase E.3 — A/B subject lines + drip campaigns.
 *
 * A/B test bucketing
 *   newsletter_issues.subject_variants (JSON nullable) — array of
 *     subject strings. When set, the sender round-robins recipients
 *     across the variants and stamps the chosen index on each
 *     newsletter_sends row so per-variant open rates can be
 *     reported via the existing Postmark/Mailgun webhook flow.
 *   newsletter_sends.subject_variant_index — which variant this
 *     send used. Null = single-subject issue (legacy behavior).
 *
 * Drip campaigns
 *   email_sequences        named, slug-keyed sequences with a
 *                          trigger event (subscriber_confirmed |
 *                          user_signed_up | manual).
 *   email_sequence_steps   per-step subject + body + delay_hours
 *                          relative to the previous step (or to
 *                          run start for step 0).
 *   email_sequence_runs    one row per (sequence, recipient).
 *                          Tracks current_step, started_at,
 *                          last_sent_at, completed_at, paused_at.
 *                          The hourly SendDripStepsCommand walks
 *                          active runs and dispatches the next
 *                          due step.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- A/B subject-line bucketing ----------------------------
        Schema::table('newsletter_issues', function (Blueprint $table) {
            if (! Schema::hasColumn('newsletter_issues', 'subject_variants')) {
                $table->json('subject_variants')->nullable()->after('subject');
            }
        });

        Schema::table('newsletter_sends', function (Blueprint $table) {
            if (! Schema::hasColumn('newsletter_sends', 'subject_variant_index')) {
                $table->unsignedTinyInteger('subject_variant_index')->nullable()->after('email');
            }
        });

        // --- Drip campaigns ----------------------------------------
        if (! Schema::hasTable('email_sequences')) {
            Schema::create('email_sequences', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 80)->unique();
                $table->string('name', 120);
                $table->text('description')->nullable();
                $table->string('trigger_event', 60)
                    ->default('manual'); // subscriber_confirmed | user_signed_up | manual
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('email_sequence_steps')) {
            Schema::create('email_sequence_steps', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sequence_id')->index();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->unsignedInteger('delay_hours')->default(0);
                $table->string('subject', 255);
                $table->longText('body_markdown');
                $table->timestamps();
                $table->index(['sequence_id', 'sort_order']);
            });
        }

        if (! Schema::hasTable('email_sequence_runs')) {
            Schema::create('email_sequence_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sequence_id')->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('email', 255)->index();
                $table->unsignedSmallInteger('current_step')->default(0);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('last_sent_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('paused_at')->nullable();
                $table->timestamps();
                $table->unique(['sequence_id', 'email'], 'email_seq_runs_seq_email_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_sequence_runs');
        Schema::dropIfExists('email_sequence_steps');
        Schema::dropIfExists('email_sequences');

        Schema::table('newsletter_sends', function (Blueprint $table) {
            if (Schema::hasColumn('newsletter_sends', 'subject_variant_index')) {
                $table->dropColumn('subject_variant_index');
            }
        });
        Schema::table('newsletter_issues', function (Blueprint $table) {
            if (Schema::hasColumn('newsletter_issues', 'subject_variants')) {
                $table->dropColumn('subject_variants');
            }
        });
    }
};
