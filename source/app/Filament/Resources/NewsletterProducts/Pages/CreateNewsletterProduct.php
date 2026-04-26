<?php

namespace App\Filament\Resources\NewsletterProducts\Pages;

use App\Filament\Resources\NewsletterProducts\NewsletterProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNewsletterProduct extends CreateRecord
{
    protected static string $resource = NewsletterProductResource::class;
}
