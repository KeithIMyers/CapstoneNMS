<?php

namespace App\Filament\Resources\WireSources\Pages;

use App\Filament\Resources\WireSources\WireSourceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWireSource extends EditRecord
{
    protected static string $resource = WireSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
