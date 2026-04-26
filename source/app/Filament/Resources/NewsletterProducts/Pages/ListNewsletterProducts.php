<?php

namespace App\Filament\Resources\NewsletterProducts\Pages;

use App\Filament\Resources\NewsletterProducts\NewsletterProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNewsletterProducts extends ListRecords
{
    protected static string $resource = NewsletterProductResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
