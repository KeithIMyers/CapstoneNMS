<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P14 Phase D.4 — multi-stage editorial workflow gates.
 *
 * Builds on the existing fact_check_status (verified | disputed |
 * unverified) by adding two opt-in pre-publish gates:
 *
 *   - Fact check: editor flags `requires_fact_check`. The model's
 *     canPublish() check refuses to flip editorial_status to
 *     published until fact_check_status='verified' AND
 *     fact_checked_by_user_id is set.
 *
 *   - Legal review: editor flags `requires_legal_review`. canPublish()
 *     refuses publish until legal_review_status='approved'.
 *
 * Both gates are off by default — articles that don't carry legal
 * or factual risk skip them entirely. The author records (who, when)
 * are kept for audit and are visible in the new "Editorial gates"
 * section on the article edit page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news', function (Blueprint $table) {
            if (! Schema::hasColumn('news', 'requires_fact_check')) {
                $table->boolean('requires_fact_check')->default(false)->after('fact_check_status');
            }
            if (! Schema::hasColumn('news', 'fact_checked_by_user_id')) {
                $table->unsignedBigInteger('fact_checked_by_user_id')->nullable()->after('requires_fact_check');
            }
            if (! Schema::hasColumn('news', 'fact_checked_at')) {
                $table->timestamp('fact_checked_at')->nullable()->after('fact_checked_by_user_id');
            }
            if (! Schema::hasColumn('news', 'requires_legal_review')) {
                $table->boolean('requires_legal_review')->default(false)->after('fact_checked_at');
            }
            if (! Schema::hasColumn('news', 'legal_review_status')) {
                $table->string('legal_review_status', 24)->nullable()->after('requires_legal_review');
                // Values: pending|approved|changes_requested|rejected
            }
            if (! Schema::hasColumn('news', 'legal_reviewed_by_user_id')) {
                $table->unsignedBigInteger('legal_reviewed_by_user_id')->nullable()->after('legal_review_status');
            }
            if (! Schema::hasColumn('news', 'legal_reviewed_at')) {
                $table->timestamp('legal_reviewed_at')->nullable()->after('legal_reviewed_by_user_id');
            }
            if (! Schema::hasColumn('news', 'legal_review_notes')) {
                $table->text('legal_review_notes')->nullable()->after('legal_reviewed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('news', function (Blueprint $table) {
            foreach ([
                'requires_fact_check', 'fact_checked_by_user_id', 'fact_checked_at',
                'requires_legal_review', 'legal_review_status',
                'legal_reviewed_by_user_id', 'legal_reviewed_at', 'legal_review_notes',
            ] as $col) {
                if (Schema::hasColumn('news', $col)) $table->dropColumn($col);
            }
        });
    }
};
