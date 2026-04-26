<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P5 Phase D — Per-editor budgets and rate limits.
 *
 * Two new optional columns on users:
 *   ai_daily_budget_microusd: dollar cap on this editor's spend per day
 *   ai_calls_per_minute     : sliding-window rate limit (calls/min)
 *
 * NULL means uncapped/no-limit. AiClient checks both before dispatching;
 * a violation lands in ai_requests with a clear error message exactly
 * like the existing per-provider budget gate.
 *
 * Works alongside per-provider caps — both are enforced. The user gate
 * runs first because it's the cheaper check (single COUNT/SUM keyed on
 * user_id) and the more meaningful one to surface to the editor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'ai_daily_budget_microusd')) {
                $table->unsignedBigInteger('ai_daily_budget_microusd')->nullable();
            }
            if (! Schema::hasColumn('users', 'ai_calls_per_minute')) {
                $table->unsignedSmallInteger('ai_calls_per_minute')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['ai_daily_budget_microusd', 'ai_calls_per_minute'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
