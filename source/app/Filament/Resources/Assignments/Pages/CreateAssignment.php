<?php

namespace App\Filament\Resources\Assignments\Pages;

use App\Filament\Resources\Assignments\AssignmentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAssignment extends CreateRecord
{
    protected static string $resource = AssignmentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Stamp creator from the current user automatically; the field
        // is disabled in the form so this is the only way it gets set.
        $data['created_by_user_id'] = auth()->id();
        return $data;
    }
}
