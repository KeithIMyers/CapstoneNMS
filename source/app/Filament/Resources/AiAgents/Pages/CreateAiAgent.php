<?php

namespace App\Filament\Resources\AiAgents\Pages;

use App\Filament\Resources\AiAgents\AiAgentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAiAgent extends CreateRecord
{
    protected static string $resource = AiAgentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['updated_by'] = auth()->id();
        return $data;
    }
}
