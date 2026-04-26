<?php

namespace App\Filament\Resources\AgentStories\Pages;

use App\Filament\Resources\AgentStories\AgentStoryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAgentStory extends EditRecord
{
    protected static string $resource = AgentStoryResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
