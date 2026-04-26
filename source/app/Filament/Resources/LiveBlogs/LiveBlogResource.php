<?php

namespace App\Filament\Resources\LiveBlogs;

use App\Filament\Resources\LiveBlogs\Pages\CreateLiveBlog;
use App\Filament\Resources\LiveBlogs\Pages\EditLiveBlog;
use App\Filament\Resources\LiveBlogs\Pages\ListLiveBlogs;
use App\Filament\Resources\LiveBlogs\RelationManagers\EntriesRelationManager;
use App\Filament\Resources\LiveBlogs\RelationManagers\PollsRelationManager;
use App\Filament\Resources\LiveBlogs\Schemas\LiveBlogForm;
use App\Filament\Resources\LiveBlogs\Tables\LiveBlogsTable;
use App\Models\LiveBlog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class LiveBlogResource extends Resource
{
    protected static ?string $model = LiveBlog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Live blogs';

    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isEditor() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->isEditor() ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->isEditor() ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return LiveBlogForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LiveBlogsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            EntriesRelationManager::class,
            PollsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLiveBlogs::route('/'),
            'create' => CreateLiveBlog::route('/create'),
            'edit' => EditLiveBlog::route('/{record}/edit'),
        ];
    }
}
