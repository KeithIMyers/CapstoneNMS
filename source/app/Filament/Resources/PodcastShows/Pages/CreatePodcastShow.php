<?php

namespace App\Filament\Resources\PodcastShows\Pages;

use App\Filament\Resources\PodcastShows\PodcastShowResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePodcastShow extends CreateRecord
{
    protected static string $resource = PodcastShowResource::class;
}
