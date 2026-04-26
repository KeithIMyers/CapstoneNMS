<?php

namespace App\Filament\Resources\PodcastShows\Pages;

use App\Filament\Resources\PodcastShows\PodcastShowResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPodcastShows extends ListRecords
{
    protected static string $resource = PodcastShowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
