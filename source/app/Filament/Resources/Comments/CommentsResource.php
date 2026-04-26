<?php

namespace App\Filament\Resources\Comments;

use App\Filament\Resources\Comments\Pages\EditComments;
use App\Filament\Resources\Comments\Pages\ListComments;
use App\Filament\Resources\Comments\Schemas\CommentsForm;
use App\Filament\Resources\Comments\Tables\CommentsTable;
use App\Models\Comments;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CommentsResource extends Resource
{
    protected static ?string $model = Comments::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|\UnitEnum|null $navigationGroup = 'Moderation';

    protected static ?int $navigationSort = 20;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isEditor() ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->isEditor() ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->isEditor() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return CommentsForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CommentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListComments::route('/'),
            'edit' => EditComments::route('/{record}/edit'),
        ];
    }
}
