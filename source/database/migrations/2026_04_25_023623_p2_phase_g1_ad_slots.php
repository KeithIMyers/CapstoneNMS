<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2 Phase G1 — Ad slot manager. Replaces the stub top/bottom/sidebar
 * textarea settings with a real table where editors can manage multiple
 * creatives per placement, with optional scheduling windows, weights for
 * rotation, and impression / click tallies for basic reporting.
 *
 * `placement` is a short keyword used by Blade to look up slots — e.g.
 * "header", "sidebar", "in_article", "footer".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ad_slots')) {
            Schema::create('ad_slots', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('placement', 64)->index();
                $table->text('code')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamp('start_at')->nullable();
                $table->timestamp('end_at')->nullable();
                $table->unsignedSmallInteger('weight')->default(1);
                $table->unsignedBigInteger('impressions')->default(0);
                $table->unsignedBigInteger('clicks')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_slots');
    }
};
