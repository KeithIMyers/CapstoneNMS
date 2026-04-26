<?php

namespace App\Filament\Resources\Comments\Schemas;

use App\Models\Comments;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class CommentsForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('content')
                ->required()
                ->rows(4)
                ->maxLength(4000)
                ->columnSpanFull(),
            Select::make('status')
                ->options([
                    Comments::STATUS_PENDING => 'Pending',
                    Comments::STATUS_APPROVED => 'Approved',
                    Comments::STATUS_SPAM => 'Spam',
                    Comments::STATUS_TRASH => 'Trash',
                ])
                ->default(Comments::STATUS_PENDING)
                ->required(),
        ]);
    }
}
