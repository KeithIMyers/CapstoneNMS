<?php

namespace App\Filament\Resources\EmailSequences\Pages;

use App\Filament\Resources\EmailSequences\EmailSequenceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmailSequences extends ListRecords
{
    protected static string $resource = EmailSequenceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
