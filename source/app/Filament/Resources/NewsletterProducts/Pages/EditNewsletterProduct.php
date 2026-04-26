<?php

namespace App\Filament\Resources\NewsletterProducts\Pages;

use App\Filament\Resources\NewsletterProducts\NewsletterProductResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditNewsletterProduct extends EditRecord
{
    protected static string $resource = NewsletterProductResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
