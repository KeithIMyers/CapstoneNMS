<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Ad creative. Multiple slots can share a placement key; AdSlot::pick()
 * picks one weighted by the `weight` field, filtered to active rows whose
 * (optional) start_at / end_at window contains "now". Impression / click
 * counters are bumped once per session per slot to keep reload-spam from
 * distorting reporting.
 */
class AdSlot extends Model
{
    public const PLACEMENTS = [
        'header'     => 'Header (above masthead)',
        'sidebar'    => 'Sidebar',
        'in_article' => 'Inside article body',
        'footer'     => 'Footer (above copyright)',
    ];

    public const KIND_HTML   = 'html';
    public const KIND_BANNER = 'banner';

    public const KINDS = [
        self::KIND_BANNER => 'Image banner (URL + image)',
        self::KIND_HTML   => 'HTML / JS snippet (network ad code)',
    ];

    protected $fillable = [
        'name', 'placement', 'kind',
        'image_path', 'click_url', 'alt_text',
        'code', 'is_active',
        'start_at', 'end_at', 'weight',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'start_at'  => 'datetime',
        'end_at'    => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(function (Builder $q) {
                $q->whereNull('start_at')->orWhere('start_at', '<=', now());
            })
            ->where(function (Builder $q) {
                $q->whereNull('end_at')->orWhere('end_at', '>', now());
            });
    }

    /**
     * The HTML the public site should render for this slot. For
     * structured banners, we build the anchor + image markup ourselves
     * so editors don't have to type HTML; for raw HTML/JS slots, we
     * emit the editor-supplied code unchanged. The site-side loader
     * extracts and re-executes any <script> tags after innerHTML
     * insertion (innerHTML alone won't run them).
     */
    public function renderHtml(): string
    {
        if ($this->kind === self::KIND_BANNER) {
            $img = (string) $this->image_path;
            if ($img === '') return '';
            $imgUrl = function_exists('image_src') ? image_src($img) : $img;
            $click  = (string) $this->click_url;
            $alt    = e((string) ($this->alt_text ?? $this->name));

            $imgTag = '<img src="'.e($imgUrl).'" alt="'.$alt.'" loading="lazy">';
            if ($click === '') return $imgTag;

            return '<a href="'.e($click).'" target="_blank" rel="noopener noreferrer sponsored">'.$imgTag.'</a>';
        }

        // Raw HTML / JS path.
        return (string) $this->code;
    }

    /**
     * Pick a slot for the given placement and record one impression per
     * session per slot. Returns null when no slot is active for the
     * placement.
     */
    public static function pick(string $placement): ?self
    {
        $candidates = self::query()
            ->active()
            ->where('placement', $placement)
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $totalWeight = $candidates->sum(fn ($s) => max(1, (int) $s->weight));
        if ($totalWeight <= 0) {
            return null;
        }

        // Weighted random pick. We don't make this sticky-per-session —
        // rotating creatives on refresh is the expected behavior for ads.
        $roll = random_int(1, $totalWeight);
        $cum = 0;
        $picked = null;
        foreach ($candidates as $s) {
            $cum += max(1, (int) $s->weight);
            if ($roll <= $cum) {
                $picked = $s;
                break;
            }
        }
        $picked = $picked ?? $candidates->first();

        // One impression per session per creative.
        $sessKey = "ad_imp_{$picked->id}";
        if (! session()->has($sessKey)) {
            session()->put($sessKey, true);
            DB::table('ad_slots')->where('id', $picked->id)->increment('impressions');
        }

        return $picked;
    }
}
