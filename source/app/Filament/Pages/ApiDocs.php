<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class ApiDocs extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracket;

    protected static string|\UnitEnum|null $navigationGroup = 'API';

    protected static ?string $title = 'API documentation';

    protected static ?int $navigationSort = 51;

    protected string $view = 'filament.pages.api-docs';

    public function getBaseUrl(): string
    {
        return rtrim(config('app.url') ?: url('/'), '/');
    }
}
