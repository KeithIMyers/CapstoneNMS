<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4 Phase B — Per-provider cost budgets.
 *
 * Adds optional daily / monthly budget caps to ai_providers, expressed
 * in micro-USD to match the existing cost_microusd accounting on
 * ai_requests. NULL means uncapped. AiClient checks the budget before
 * each completion and refuses to dispatch when adding the next call's
 * worst-case cost would exceed the cap.
 *
 * Why integer micro-USD instead of decimal: avoids float drift across
 * thousands of small entries in the ai_requests audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_providers', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_providers', 'daily_budget_microusd')) {
                $table->unsignedBigInteger('daily_budget_microusd')->nullable()->after('max_output_tokens');
            }
            if (! Schema::hasColumn('ai_providers', 'monthly_budget_microusd')) {
                $table->unsignedBigInteger('monthly_budget_microusd')->nullable()->after('daily_budget_microusd');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_providers', function (Blueprint $table) {
            foreach (['daily_budget_microusd', 'monthly_budget_microusd'] as $col) {
                if (Schema::hasColumn('ai_providers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
