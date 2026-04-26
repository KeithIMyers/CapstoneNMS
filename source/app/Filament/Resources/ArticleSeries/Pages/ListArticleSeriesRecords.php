<?php

namespace App\Filament\Resources\ArticleSeries\Pages;

use App\Filament\Resources\ArticleSeries\ArticleSeriesResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListArticleSeriesRecords extends ListRecords
{
    protected static string $resource = ArticleSeriesResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
