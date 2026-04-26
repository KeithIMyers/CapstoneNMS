<?php

namespace App\Filament\Pages;

use App\Models\News;
use BackedEnum;
use Carbon\Carbon;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Month-grid editorial calendar. Shows every article with a published_at or
 * unpublished_at in the visible month, colored by editorial_status. Built
 * without FullCalendar (no Node dependency) — vanilla CSS grid + query-string
 * month navigation.
 */
class EditorialCalendar extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $title = 'Editorial calendar';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.editorial-calendar';

    /** ?YYYY-MM query param; defaults to current month. */
    public ?string $month = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->isEditor() ?? false;
    }

    public function mount(): void
    {
        $this->month = request()->query('month') ?: now()->format('Y-m');
    }

    /**
     * @return array<string, array{cursor: Carbon, articles: array<int, array{id:int,title:string,slug:string,time:?string,status:string,kind:string}>}>
     */
    public function getCalendarData(): array
    {
        try {
            $cursor = Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfMonth();
        } catch (\Throwable $e) {
            $cursor = now()->startOfMonth();
        }

        $gridStart = $cursor->copy()->startOfWeek(Carbon::SUNDAY);
        $gridEnd   = $cursor->copy()->endOfMonth()->endOfWeek(Carbon::SATURDAY);

        // Pull all articles whose publish or unpublish fires in the visible
        // grid window. We include both so editors can see when something is
        // scheduled to come down, not just go up.
        $articles = News::query()
            ->where(function ($q) use ($gridStart, $gridEnd) {
                $q->whereBetween('published_at', [$gridStart, $gridEnd])
                  ->orWhereBetween('unpublished_at', [$gridStart, $gridEnd]);
            })
            ->orderBy('published_at')
            ->get();

        // Bucket into day keys.
        $days = [];
        for ($d = $gridStart->copy(); $d->lte($gridEnd); $d->addDay()) {
            $days[$d->format('Y-m-d')] = [
                'cursor' => $d->copy(),
                'articles' => [],
            ];
        }

        foreach ($articles as $a) {
            foreach ([['published_at', 'publish'], ['unpublished_at', 'unpublish']] as [$field, $kind]) {
                $dt = $a->{$field};
                if (! $dt) continue;
                $key = $dt->format('Y-m-d');
                if (! isset($days[$key])) continue;
                $days[$key]['articles'][] = [
                    'id' => $a->id,
                    'title' => (string) $a->title,
                    'slug' => (string) $a->slug,
                    'time' => $dt->format('g:i a'),
                    'status' => (string) $a->editorial_status,
                    'kind' => $kind,
                ];
            }
        }

        return $days;
    }

    public function getMonthCursor(): Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfMonth();
        } catch (\Throwable $e) {
            return now()->startOfMonth();
        }
    }

    public function getPrevMonthUrl(): string
    {
        return static::getUrl().'?month='.$this->getMonthCursor()->copy()->subMonth()->format('Y-m');
    }

    public function getNextMonthUrl(): string
    {
        return static::getUrl().'?month='.$this->getMonthCursor()->copy()->addMonth()->format('Y-m');
    }

    public function getTodayUrl(): string
    {
        return static::getUrl().'?month='.now()->format('Y-m');
    }
}
