<?php

namespace App\Filament\Resources\AgentStories\Pages;

use App\Filament\Resources\AgentStories\AgentStoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAgentStories extends ListRecords
{
    protected static string $resource = AgentStoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
