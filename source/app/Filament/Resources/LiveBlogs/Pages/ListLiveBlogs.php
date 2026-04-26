<?php

namespace App\Filament\Resources\LiveBlogs\Pages;

use App\Filament\Resources\LiveBlogs\LiveBlogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLiveBlogs extends ListRecords
{
    protected static string $resource = LiveBlogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
