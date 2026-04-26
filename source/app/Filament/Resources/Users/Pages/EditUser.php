<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Mirror CreateUser: bypass the model's safe-by-default $fillable
     * list because the admin user form is a trusted boundary. Editing
     * role / status / AI quotas via Filament must still work even
     * though those fields are guarded against mass-assignment from
     * arbitrary code paths.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->forceFill($data)->save();
        return $record;
    }
}
