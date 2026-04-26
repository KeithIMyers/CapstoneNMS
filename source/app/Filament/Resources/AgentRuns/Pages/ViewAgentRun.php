<?php

namespace App\Filament\Resources\AgentRuns\Pages;

use App\Filament\Resources\AgentRuns\AgentRunResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only transcript drill-in. Renders via a custom view because the
 * data we want to show (a JSON message log) doesn't fit Filament's
 * standard infolist layout cleanly.
 */
class ViewAgentRun extends ViewRecord
{
    protected static string $resource = AgentRunResource::class;

    protected string $view = 'filament.pages.agent-run-view';
}
