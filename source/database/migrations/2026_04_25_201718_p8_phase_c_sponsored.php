<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P8 Phase C — sponsored / native-content support.
 *
 * `is_sponsored` flags an article as paid placement; the public view
 * renders a "Sponsored by …" header so it's clearly distinguished from
 * editorial. `sponsor_label` is the free-text "Presented by Acme"
 * string shown on that header.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news', function (Blueprint $table) {
            if (! Schema::hasColumn('news', 'is_sponsored')) {
                $table->boolean('is_sponsored')->default(false)->index()->after('is_premium');
            }
            if (! Schema::hasColumn('news', 'sponsor_label')) {
                $table->string('sponsor_label', 200)->nullable()->after('is_sponsored');
            }
        });
    }

    public function down(): void
    {
        Schema::table('news', function (Blueprint $table) {
            foreach (['is_sponsored', 'sponsor_label'] as $col) {
                if (Schema::hasColumn('news', $col)) {
                    if ($col === 'is_sponsored') $table->dropIndex(['is_sponsored']);
                    $table->dropColumn($col);
                }
            }
        });
    }
};
