<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Top reading countries this week. Hidden when no rows exist (i.e.,
 * GeoIP isn't wired or no traffic has been geo-resolved yet) so a
 * fresh install doesn't show empty cards.
 */
class TopCountriesWidget extends BaseWidget
{
    protected static ?int $sort = 4;

    protected ?string $heading = 'Top reading countries (last 7 days)';

    public static function canView(): bool
    {
        return DB::table('article_country_views')
            ->where('day', '>=', now()->subDays(7)->toDateString())
            ->exists();
    }

    protected function getStats(): array
    {
        $rows = DB::table('article_country_views')
            ->select('country_code', DB::raw('SUM(views) as total'))
            ->where('day', '>=', now()->subDays(7)->toDateString())
            ->groupBy('country_code')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        if ($rows->isEmpty()) {
            return [Stat::make('No geo data yet', '—')->color('gray')];
        }

        return $rows->map(fn ($r) => Stat::make(
            $this->countryName($r->country_code),
            number_format((int) $r->total),
        )
            ->description($r->country_code)
            ->color('gray'))->all();
    }

    /**
     * Best-effort ISO-2 → display name. PHP's intl extension provides
     * this when available; otherwise we just echo the code so editors
     * still see SOMETHING readable.
     */
    private function countryName(string $code): string
    {
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayRegion('-'.$code, 'en');
            if ($name) return $name;
        }
        return $code;
    }
}
