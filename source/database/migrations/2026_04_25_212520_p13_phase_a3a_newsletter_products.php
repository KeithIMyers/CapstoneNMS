<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P13 Phase A.3a — multiple newsletter products.
 *
 *   newsletter_products       metadata for each product readers can
 *                             subscribe to (Daily Brief, Weekly,
 *                             Breaking, Topic newsletters, …).
 *
 *   newsletter_subscriptions  gains product_id; a single email can
 *                             now sit in N rows, one per product,
 *                             each with its own confirm + unsub state
 *                             and its own token.
 *
 * Fully idempotent so a partial run (e.g. table created + column
 * added but index swap failed) can re-run cleanly. We drop indexes
 * by querying information_schema for the *actual* index name —
 * this table was renamed from `subscriptions` so the legacy index
 * is named `subscriptions_email_unique`, not the convention-derived
 * `newsletter_subscriptions_email_unique`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Step 1: products table + default row.
        if (! Schema::hasTable('newsletter_products')) {
            Schema::create('newsletter_products', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 80)->unique();
                $table->string('name', 120);
                $table->text('description')->nullable();
                $table->string('cadence', 40)->nullable();
                $table->boolean('is_default')->default(false);
                $table->boolean('active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! DB::table('newsletter_products')->where('is_default', true)->exists()) {
            DB::table('newsletter_products')->updateOrInsert(
                ['slug' => 'default'],
                [
                    'name'        => 'Daily brief',
                    'description' => "Today's top stories from the newsroom.",
                    'cadence'     => 'daily',
                    'is_default'  => true,
                    'active'      => true,
                    'sort_order'  => 0,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ],
            );
        }

        $defaultId = (int) DB::table('newsletter_products')->where('is_default', true)->value('id');

        // Step 2: product_id column + backfill.
        if (Schema::hasTable('newsletter_subscriptions') && ! Schema::hasColumn('newsletter_subscriptions', 'product_id')) {
            Schema::table('newsletter_subscriptions', function (Blueprint $table) {
                $table->unsignedBigInteger('product_id')->nullable()->after('email');
                $table->index('product_id');
            });
        }

        DB::table('newsletter_subscriptions')->whereNull('product_id')->update([
            'product_id' => $defaultId,
        ]);

        // Step 3: drop legacy unique-on-email by its actual name.
        // The table was renamed from `subscriptions`, so the legacy
        // index keeps the `subscriptions_*` prefix.
        $existing = $this->indexesOn('newsletter_subscriptions');
        foreach ($existing as $name => $cols) {
            if ($cols === ['email']) {
                Schema::table('newsletter_subscriptions', function (Blueprint $t) use ($name) {
                    $t->dropUnique($name);
                });
            }
        }

        // Step 4: enforce NOT NULL on product_id.
        if (Schema::hasColumn('newsletter_subscriptions', 'product_id')) {
            try {
                Schema::table('newsletter_subscriptions', function (Blueprint $t) {
                    $t->unsignedBigInteger('product_id')->nullable(false)->change();
                });
            } catch (\Throwable $e) {
                // Some MariaDB builds throw on a no-op change; ignore.
            }
        }

        // Step 5: add the new (email, product_id) unique constraint
        // unless it's already present from a partial run.
        $existingAfter = $this->indexesOn('newsletter_subscriptions');
        $hasComposite = false;
        foreach ($existingAfter as $name => $cols) {
            if ($cols === ['email', 'product_id']) {
                $hasComposite = true;
                break;
            }
        }
        if (! $hasComposite) {
            Schema::table('newsletter_subscriptions', function (Blueprint $t) {
                $t->unique(['email', 'product_id'], 'newsletter_subs_email_product_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('newsletter_subscriptions') && Schema::hasColumn('newsletter_subscriptions', 'product_id')) {
            $existing = $this->indexesOn('newsletter_subscriptions');
            foreach ($existing as $name => $cols) {
                if ($cols === ['email', 'product_id']) {
                    Schema::table('newsletter_subscriptions', function (Blueprint $t) use ($name) {
                        $t->dropUnique($name);
                    });
                }
            }
            Schema::table('newsletter_subscriptions', function (Blueprint $t) {
                $t->dropColumn('product_id');
            });
        }

        Schema::dropIfExists('newsletter_products');
    }

    /**
     * Return a map of [index_name => [col1, col2, ...]] for the table.
     * Lets us drop indexes by their actual name regardless of how the
     * table was originally created or renamed.
     *
     * @return array<string, array<int,string>>
     */
    private function indexesOn(string $table): array
    {
        $rows = DB::select('SHOW INDEX FROM '.$table);
        $byName = [];
        foreach ($rows as $r) {
            $byName[$r->Key_name][(int) $r->Seq_in_index - 1] = $r->Column_name;
        }
        $out = [];
        foreach ($byName as $name => $cols) {
            ksort($cols);
            $out[$name] = array_values($cols);
        }
        return $out;
    }
};
