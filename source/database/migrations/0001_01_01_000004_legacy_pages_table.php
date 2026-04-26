<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill the `pages` table for installs without it.
 *
 * The static "About" / "Privacy" / "Terms" CMS pages were originally
 * provisioned by the CodeCanyon SQL dump and never had a Laravel
 * migration. The Pages model + PagesController + the public footer
 * partial all assume the table exists; without it the homepage 500s
 * on its first render.
 *
 * Schema::hasTable guards make this idempotent — fresh installs and
 * reinstalled hosts (whose dump pre-provisioned pages) both end up
 * in the same shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pages')) return;

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('page_title', 200);
            $table->string('page_slug', 200)->index();
            $table->longText('page_content')->nullable();
            $table->unsignedInteger('page_order')->default(0)->index();
            $table->unsignedTinyInteger('status')->default(1)->index();
            // No timestamps — the original schema (and the Pages
            // model's `public $timestamps = false;`) doesn't track them.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
