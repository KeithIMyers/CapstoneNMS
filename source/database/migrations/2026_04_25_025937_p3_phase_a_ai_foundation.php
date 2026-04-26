<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3 Phase A — Agentic CMS foundation.
 *
 *  - ai_providers:  endpoint configuration for each LLM backend the newsroom
 *    can route through. `api_key` is stored via Laravel's Crypt facade
 *    (mutated on the model) — never in plaintext at rest. `kind` is one of
 *    openai | anthropic | ollama and selects the driver class.
 *
 *  - ai_requests:   append-only audit log of every completion this app
 *    initiates. Tracks token counts (when the provider returns them),
 *    latency, cost estimate in micro-dollars (integer to avoid floats),
 *    and which editor / purpose drove the call. Read by the usage log
 *    page in the admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_providers')) {
            Schema::create('ai_providers', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);               // display label
                $table->string('kind', 32);                // openai | anthropic | ollama
                $table->string('base_url', 500)->nullable(); // e.g. http://localhost:11434
                $table->text('api_key')->nullable();       // encrypted
                $table->string('default_model', 120)->nullable();
                $table->unsignedInteger('max_output_tokens')->default(2048);
                $table->boolean('is_active')->default(true)->index();
                $table->boolean('is_default')->default(false)->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_requests')) {
            Schema::create('ai_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('provider_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('model', 120)->nullable();
                $table->string('purpose', 80)->nullable()->index(); // e.g. article.copyedit
                $table->string('status', 16)->default('ok');        // ok | error | timeout
                $table->unsignedInteger('tokens_in')->nullable();
                $table->unsignedInteger('tokens_out')->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->unsignedBigInteger('cost_microusd')->nullable(); // integer cents/1000
                $table->text('error_message')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
        Schema::dropIfExists('ai_providers');
    }
};
