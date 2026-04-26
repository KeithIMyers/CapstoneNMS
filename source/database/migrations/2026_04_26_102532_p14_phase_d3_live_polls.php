<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P14 Phase D.3 — live polls inside live blogs.
 *
 *   live_blog_polls         one row per poll. Options stored as JSON
 *                           ([{id:0, label:'A'}, ...]). status open|
 *                           closed; tally counters maintained on
 *                           every vote so the public page doesn't
 *                           need to GROUP BY the votes table on each
 *                           render.
 *
 *   live_blog_poll_votes    one row per vote. Voter dedup via
 *                           voter_hash (sha256 of cookie+IP+poll_id)
 *                           — sufficient for casual abuse prevention
 *                           without forcing readers to register.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('live_blog_polls')) {
            Schema::create('live_blog_polls', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('live_blog_id')->index();
                $table->string('question', 500);
                $table->json('options');                 // [{id:0,label:'…'}, ...]
                $table->json('tallies')->nullable();     // {0: 12, 1: 8}
                $table->unsignedInteger('total_votes')->default(0);
                $table->string('status', 16)->default('open'); // open|closed
                $table->timestamp('closes_at')->nullable();
                $table->boolean('is_pinned')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('live_blog_poll_votes')) {
            Schema::create('live_blog_poll_votes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('poll_id')->index();
                $table->unsignedTinyInteger('choice_id');
                $table->string('voter_hash', 64)->index();
                $table->string('ip_hash', 64)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->unique(['poll_id', 'voter_hash'], 'live_poll_unique_voter');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('live_blog_poll_votes');
        Schema::dropIfExists('live_blog_polls');
    }
};
