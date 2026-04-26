<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4 Phase C — Scheduled agents.
 *
 * Adds a coarse schedule + standing input + last-run bookkeeping to
 * ai_agents. The agents:run-scheduled Artisan command (driven by CRON)
 * walks the table, finds rows whose schedule next-fire window has
 * passed, and runs them with the stored input.
 *
 * `schedule_frequency` is intentionally an enum of {hourly, daily,
 * weekly} rather than a free-form cron expression — DreamHost shared
 * hosting can only run a single CRON every N minutes, and an enum
 * keeps the dispatch math trivial. Free-form cron can be a follow-up.
 *
 * `standing_input` is the message we send to the agent on every
 * scheduled run (e.g., "Compose today's brief"). Required when
 * schedule_frequency is set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_agents', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_agents', 'schedule_frequency')) {
                $table->string('schedule_frequency', 16)->nullable()->after('is_active');
            }
            if (! Schema::hasColumn('ai_agents', 'schedule_hour_utc')) {
                $table->unsignedTinyInteger('schedule_hour_utc')->nullable()->after('schedule_frequency');
            }
            if (! Schema::hasColumn('ai_agents', 'standing_input')) {
                $table->text('standing_input')->nullable()->after('schedule_hour_utc');
            }
            if (! Schema::hasColumn('ai_agents', 'last_run_at')) {
                $table->timestamp('last_run_at')->nullable()->after('standing_input');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_agents', function (Blueprint $table) {
            foreach (['schedule_frequency', 'schedule_hour_utc', 'standing_input', 'last_run_at'] as $col) {
                if (Schema::hasColumn('ai_agents', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
