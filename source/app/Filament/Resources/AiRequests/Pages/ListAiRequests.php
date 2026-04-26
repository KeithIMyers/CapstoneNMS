<?php

namespace App\Filament\Resources\AiRequests\Pages;

use App\Filament\Resources\AiRequests\AiRequestResource;
use App\Models\AiRequest;
use Filament\Resources\Pages\ListRecords;

class ListAiRequests extends ListRecords
{
    protected static string $resource = AiRequestResource::class;

    /**
     * Totals strip rendered above the table. Updated live with the table
     * state so filters / search narrow the totals along with the rows.
     */
    protected function getHeaderWidgets(): array
    {
        return [];
    }

    public function getSubheading(): ?string
    {
        $last30 = AiRequest::where('created_at', '>=', now()->subDays(30));

        $count   = (clone $last30)->count();
        $tokIn   = (clone $last30)->sum('tokens_in');
        $tokOut  = (clone $last30)->sum('tokens_out');
        $costUsd = (clone $last30)->sum('cost_microusd') / 1_000_000;

        return sprintf(
            'Last 30 days: %s requests · %s prompt tokens · %s completion tokens · $%s estimated cost',
            number_format($count),
            number_format($tokIn),
            number_format($tokOut),
            number_format($costUsd, 2),
        );
    }
}
