<?php

namespace App\Filament\Resources\NewsAgents\Pages;

use App\Filament\Resources\NewsAgents\NewsAgentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNewsAgents extends ListRecords
{
    protected static string $resource = NewsAgentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
