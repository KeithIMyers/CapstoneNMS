<?php

namespace App\Filament\Resources\AiPrompts\Pages;

use App\Filament\Resources\AiPrompts\AiPromptResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAiPrompt extends CreateRecord
{
    protected static string $resource = AiPromptResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['updated_by'] = auth()->id();
        return $data;
    }
}
