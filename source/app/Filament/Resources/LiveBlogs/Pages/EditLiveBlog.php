<?php

namespace App\Filament\Resources\LiveBlogs\Pages;

use App\Filament\Resources\LiveBlogs\LiveBlogResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLiveBlog extends EditRecord
{
    protected static string $resource = LiveBlogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
