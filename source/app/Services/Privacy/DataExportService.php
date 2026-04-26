<?php

namespace App\Services\Privacy;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the GDPR / CCPA "export my data" payload — every row in
 * the database that references the user, plus their profile.
 *
 * The result is a single associative array; the controller serializes
 * it to JSON and ships it as a download. Returning a structured
 * array (rather than streaming straight to disk) keeps the surface
 * easy to test and lets future callers attach a manifest, redact
 * sensitive fields, etc.
 */
class DataExportService
{
    /**
     * @return array<string, mixed>
     */
    public function exportFor(User $user): array
    {
        return [
            'meta' => [
                'generated_at'   => Carbon::now()->toAtomString(),
                'site'           => config('app.url'),
                'subject_user'   => ['id' => $user->id, 'email' => $user->email],
                'schema_version' => 1,
                'note'           => 'This export contains all rows we hold tied to your user id. Some downstream services (Stripe, Mail provider) hold their own copies of the same data — request those separately.',
            ],

            'profile' => $user->only([
                'id', 'name', 'slug', 'bio', 'twitter_handle', 'email',
                'phone', 'image', 'role', 'status', 'created_at', 'updated_at',
                'privacy_prefs',
            ]),

            'comments' => $this->dump('comments', 'user_id', $user->id,
                ['id', 'post_id', 'parent_comment_id', 'content', 'status', 'created_at', 'updated_at']),

            'comment_likes' => $this->dump('comment_likes', 'user_id', $user->id,
                ['comment_id', 'created_at']),

            'reactions' => $this->dump('reactions', 'user_id', $user->id,
                ['news_id', 'type', 'created_at']),

            // Favorites table is named in the singular on this codebase
            // (legacy from the upstream CMS); fall through gracefully on
            // installs that name it differently.
            'favorites' => $this->dumpFromAny(['favorite', 'favorites'], 'user_id', $user->id,
                ['post_id', 'created_at']),

            'reading_lists' => $this->dump('reading_lists', 'user_id', $user->id,
                ['id', 'name', 'slug', 'is_default', 'created_at']),

            'reading_list_items' => Schema::hasTable('reading_list_items') && Schema::hasTable('reading_lists')
                ? DB::table('reading_list_items')
                    ->whereIn('reading_list_id', DB::table('reading_lists')->where('user_id', $user->id)->pluck('id'))
                    ->select(['reading_list_id', 'news_id', 'note', 'added_at'])
                    ->get()->all()
                : [],

            'reading_history' => $this->dump('reading_history', 'user_id', $user->id,
                ['news_id', 'last_read_at', 'scroll_depth_pct', 'read_count']),

            'newsletter_subscriptions' => $this->dump('newsletter_subscriptions', 'email', $user->email,
                ['email', 'product_id', 'source', 'confirmed_at', 'unsubscribed_at', 'created_at']),

            'donations' => Schema::hasTable('donations')
                ? DB::table('donations')
                    ->where(function ($q) use ($user) {
                        $q->where('user_id', $user->id)->orWhere('email', $user->email);
                    })
                    ->select(['id', 'amount_cents', 'currency', 'status', 'message', 'anonymous', 'created_at'])
                    ->get()->all()
                : [],

            'article_purchases' => $this->dump('article_purchases', 'user_id', $user->id,
                ['news_id', 'amount_cents', 'currency', 'status', 'expires_at', 'created_at']),

            'article_gifts_issued' => $this->dump('article_gifts', 'gifter_user_id', $user->id,
                ['news_id', 'recipient_email', 'redeemed_at', 'expires_at', 'created_at']),

            'magic_links' => $this->dump('magic_links', 'email', $user->email,
                ['expires_at', 'used_at', 'request_ip', 'created_at']),

            'push_subscriptions' => $this->dump('push_subscriptions', 'user_id', $user->id,
                ['user_agent', 'last_used_at', 'created_at']),

            'ai_requests' => $this->dump('ai_requests', 'user_id', $user->id,
                ['model', 'purpose', 'status', 'tokens_in', 'tokens_out', 'cost_microusd', 'created_at']),

            'subscriptions' => $this->dump('subscriptions', 'user_id', $user->id,
                ['name', 'stripe_status', 'stripe_price', 'quantity', 'trial_ends_at', 'ends_at', 'created_at']),

            'assignments' => Schema::hasTable('assignments')
                ? DB::table('assignments')
                    ->where(function ($q) use ($user) {
                        $q->where('assigned_to_user_id', $user->id)
                          ->orWhere('created_by_user_id', $user->id);
                    })
                    ->select(['id', 'title', 'status', 'priority', 'deadline', 'created_at'])
                    ->get()->all()
                : [],
        ];
    }

    /** @return array<int, object> */
    private function dump(string $table, string $col, mixed $value, array $select): array
    {
        return $this->dumpResilient($table, $col, $value, $select);
    }

    /**
     * Try a list of candidate table names (handles legacy schema
     * variants like `favorite` vs `favorites`); use the first that
     * exists, return [] if none do.
     *
     * @return array<int, object>
     */
    private function dumpFromAny(array $candidates, string $col, mixed $value, array $select): array
    {
        foreach ($candidates as $t) {
            if (Schema::hasTable($t)) {
                // Some legacy tables omit created_at — fall back to *
                // when the requested columns aren't all present.
                $available = array_filter($select, fn ($c) => Schema::hasColumn($t, $c));
                if (empty($available)) $available = ['*'];
                return DB::table($t)->where($col, $value)->select($available)->get()->all();
            }
        }
        return [];
    }

    /**
     * Like `dump`, but tolerant of legacy tables missing some columns
     * (e.g. the favorite table has no created_at). Falls back to *
     * when nothing in $select exists on the table.
     *
     * @return array<int, object>
     */
    private function dumpResilient(string $table, string $col, mixed $value, array $select): array
    {
        if (! Schema::hasTable($table)) return [];
        $available = array_filter($select, fn ($c) => Schema::hasColumn($table, $c));
        if (empty($available)) $available = ['*'];
        return DB::table($table)->where($col, $value)->select($available)->get()->all();
    }
}
