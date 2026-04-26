<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P13 Phase B.3 — editorial pitch / assignment workflow.
 *
 *   assignments    a story idea owned by an editor that an author
 *                  can claim or be assigned to. Tracks the pitch
 *                  through: pitched → assigned → in_progress →
 *                  submitted → published.
 *
 * The optional news_id ties an assignment to its resulting article
 * once the author files. status = 'published' fires when the linked
 * article transitions to published, so editors see fulfilled
 * assignments without manual flipping.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assignments')) {
            Schema::create('assignments', function (Blueprint $table) {
                $table->id();
                $table->string('title', 255);
                $table->text('brief')->nullable();
                $table->string('status', 24)->default('pitched'); // pitched|assigned|in_progress|submitted|published|archived
                $table->string('priority', 16)->default('normal'); // low|normal|high|urgent
                $table->unsignedBigInteger('assigned_to_user_id')->nullable()->index();
                $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
                $table->unsignedBigInteger('category_id')->nullable()->index();
                $table->unsignedBigInteger('news_id')->nullable()->index();
                $table->timestamp('deadline')->nullable();
                $table->timestamp('claimed_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamps();
                $table->index(['status', 'deadline']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};
