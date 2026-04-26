<?php

namespace App\Filament\Widgets;

use App\Models\News;
use Filament\Widgets\Widget;

/**
 * Renders the AI lint findings for the currently-edited article. The
 * findings array is populated by the "Pre-publish lint" header action
 * and stashed in the session keyed by article id, so the widget reads
 * it back on the next page render. Cleared from the session once the
 * editor explicitly dismisses or re-runs the lint.
 */
class ArticleLintFindings extends Widget
{
    protected string $view = 'filament.widgets.article-lint-findings';

    protected int|string|array $columnSpan = 'full';

    public ?News $record = null;

    /** @return array{findings: array<int, array<string, mixed>>, sessionKey: ?string, criticalCount: int, warnCount: int} */
    protected function getViewData(): array
    {
        if (! $this->record) {
            return ['findings' => [], 'sessionKey' => null, 'criticalCount' => 0, 'warnCount' => 0];
        }
        $key = "lint_findings_{$this->record->id}";
        $findings = (array) session($key, []);

        $criticalCount = collect($findings)->where('severity', 'critical')->count();
        $warnCount     = collect($findings)->where('severity', 'warn')->count();

        return [
            'findings'     => $findings,
            'sessionKey'   => $key,
            'criticalCount' => $criticalCount,
            'warnCount'     => $warnCount,
        ];
    }

    public function dismiss(): void
    {
        if ($this->record) {
            session()->forget("lint_findings_{$this->record->id}");
        }
    }
}
