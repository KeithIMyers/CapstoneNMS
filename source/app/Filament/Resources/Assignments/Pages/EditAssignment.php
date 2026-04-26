<?php

namespace App\Filament\Resources\Assignments\Pages;

use App\Filament\Resources\Assignments\AssignmentResource;
use App\Models\Assignment;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAssignment extends EditRecord
{
    protected static string $resource = AssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return auth()->user()?->isEditor() ? [DeleteAction::make()] : [];
    }

    /**
     * Track lifecycle timestamps as status changes — one place to
     * keep the audit trail accurate without scattering the logic
     * across multiple actions.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $newStatus = $data['status'] ?? null;
        $oldStatus = $this->record->status;

        if ($newStatus === Assignment::STATUS_ASSIGNED && ! $this->record->claimed_at) {
            $data['claimed_at'] = now();
        }
        if ($newStatus === Assignment::STATUS_SUBMITTED && ! $this->record->submitted_at) {
            $data['submitted_at'] = now();
        }
        return $data;
    }
}
