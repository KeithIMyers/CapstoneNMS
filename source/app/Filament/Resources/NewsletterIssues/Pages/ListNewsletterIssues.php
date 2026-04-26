<?php

namespace App\Filament\Resources\NewsletterIssues\Pages;

use App\Filament\Resources\NewsletterIssues\NewsletterIssueResource;
use Filament\Resources\Pages\ListRecords;

class ListNewsletterIssues extends ListRecords
{
    protected static string $resource = NewsletterIssueResource::class;
}
