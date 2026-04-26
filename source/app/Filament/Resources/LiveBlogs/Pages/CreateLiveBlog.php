<?php

namespace App\Filament\Resources\LiveBlogs\Pages;

use App\Filament\Resources\LiveBlogs\LiveBlogResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLiveBlog extends CreateRecord
{
    protected static string $resource = LiveBlogResource::class;
}
