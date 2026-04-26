<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3 Phase C — Moderation agents.
 *
 * Adds AI verdict columns to comments. The agent classifies user-submitted
 * comments as ALLOW | REVIEW | REJECT and we store both the verdict and
 * the model's short reasoning so a human moderator can see why it ruled
 * the way it did.
 *
 * `ai_verdict_at` records when the classification ran so editors can
 * audit performance over time and detect stale verdicts after a prompt
 * change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            if (! Schema::hasColumn('comments', 'ai_verdict')) {
                $table->string('ai_verdict', 16)->nullable()->index()->after('status');
            }
            if (! Schema::hasColumn('comments', 'ai_reason')) {
                $table->text('ai_reason')->nullable()->after('ai_verdict');
            }
            if (! Schema::hasColumn('comments', 'ai_verdict_at')) {
                $table->timestamp('ai_verdict_at')->nullable()->after('ai_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            foreach (['ai_verdict', 'ai_reason', 'ai_verdict_at'] as $col) {
                if (Schema::hasColumn('comments', $col)) {
                    if ($col === 'ai_verdict') {
                        $table->dropIndex(['ai_verdict']);
                    }
                    $table->dropColumn($col);
                }
            }
        });
    }
};
