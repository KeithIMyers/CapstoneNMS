<?php

namespace App\Filament\Resources\AdSlots\Pages;

use App\Filament\Resources\AdSlots\AdSlotResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAdSlot extends EditRecord
{
    protected static string $resource = AdSlotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
