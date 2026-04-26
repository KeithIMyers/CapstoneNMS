<?php

namespace App\Filament\Resources\AgentRuns\Pages;

use App\Filament\Resources\AgentRuns\AgentRunResource;
use App\Models\AgentRun;
use Filament\Resources\Pages\ListRecords;

class ListAgentRuns extends ListRecords
{
    protected static string $resource = AgentRunResource::class;

    public function getSubheading(): ?string
    {
        $last = AgentRun::where('started_at', '>=', now()->subDays(30));
        $count    = (clone $last)->count();
        $done     = (clone $last)->where('status', AgentRun::STATUS_DONE)->count();
        $errors   = (clone $last)->where('status', AgentRun::STATUS_ERROR)->count();
        $costUsd  = (clone $last)->sum('cost_microusd') / 1_000_000;
        return sprintf(
            'Last 30 days: %s runs (%s done · %s error) · $%s estimated cost',
            number_format($count),
            number_format($done),
            number_format($errors),
            number_format($costUsd, 2),
        );
    }
}
