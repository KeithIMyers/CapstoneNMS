<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3 Phase B — AI prompt library.
 *
 * Prompts are stored in the DB (not hardcoded in PHP) so editors can
 * tune voice/style without a code deploy. Each prompt has a stable
 * `key` that assistants look up (e.g. "article.copyedit") and an
 * editable system_prompt / temperature / max_tokens. The seeder ships
 * a sensible default for each built-in assistant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_prompts')) {
            Schema::create('ai_prompts', function (Blueprint $table) {
                $table->id();
                $table->string('key', 80)->unique();          // e.g. article.copyedit
                $table->string('name', 200);
                $table->text('description')->nullable();
                $table->longText('system_prompt');
                $table->float('temperature')->default(0.4);
                $table->unsignedInteger('max_tokens')->default(1024);
                $table->unsignedBigInteger('provider_id')->nullable()->index(); // optional override
                $table->string('model_override', 120)->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prompts');
    }
};
