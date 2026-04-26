<?php

namespace App\Filament\Resources\Reports\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class ReportsForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('message')
                ->disabled()
                ->rows(4)
                ->columnSpanFull(),
        ]);
    }
}
