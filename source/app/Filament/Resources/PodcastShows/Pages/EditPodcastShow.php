<?php

namespace App\Filament\Resources\PodcastShows\Pages;

use App\Filament\Resources\PodcastShows\PodcastShowResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPodcastShow extends EditRecord
{
    protected static string $resource = PodcastShowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
