<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3 Phase D — Tool-calling agent framework.
 *
 *  ai_agents:  admin-editable agent definitions. Each row pairs a stable
 *    `key` with a system prompt, a list of allowed tool keys (JSON), an
 *    optional provider/model override, and a max-iterations safety cap.
 *
 *  agent_runs: append-only history of every agent invocation. The full
 *    message transcript is stored as JSON so an editor can review what
 *    the model thought, which tools it called, what each observation
 *    returned, and the final output. status tracks running | done |
 *    error so a UI can poll, though the MVP runs sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_agents')) {
            Schema::create('ai_agents', function (Blueprint $table) {
                $table->id();
                $table->string('key', 80)->unique();
                $table->string('name', 200);
                $table->text('description')->nullable();
                $table->longText('system_prompt');
                $table->json('tool_keys');               // ["search_articles", "read_article", ...]
                $table->unsignedSmallInteger('max_iterations')->default(8);
                $table->float('temperature')->default(0.3);
                $table->unsignedInteger('max_tokens_per_step')->default(1024);
                $table->unsignedBigInteger('provider_id')->nullable()->index();
                $table->string('model_override', 120)->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('agent_runs')) {
            Schema::create('agent_runs', function (Blueprint $table) {
                $table->id();
                $table->string('agent_key', 80)->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('status', 16)->default('running')->index(); // running | done | error
                $table->text('input_message');
                $table->longText('final_output')->nullable();
                $table->json('transcript')->nullable();
                $table->unsignedSmallInteger('iterations')->default(0);
                $table->unsignedInteger('tokens_in_total')->default(0);
                $table->unsignedInteger('tokens_out_total')->default(0);
                $table->unsignedBigInteger('cost_microusd')->default(0);
                $table->unsignedInteger('duration_ms')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('started_at')->useCurrent();
                $table->timestamp('finished_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
        Schema::dropIfExists('ai_agents');
    }
};
