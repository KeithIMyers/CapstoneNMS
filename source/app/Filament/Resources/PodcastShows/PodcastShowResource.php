<?php

namespace App\Filament\Resources\PodcastShows;

use App\Filament\Resources\PodcastShows\Pages\CreatePodcastShow;
use App\Filament\Resources\PodcastShows\Pages\EditPodcastShow;
use App\Filament\Resources\PodcastShows\Pages\ListPodcastShows;
use App\Filament\Resources\PodcastShows\RelationManagers\EpisodesRelationManager;
use App\Filament\Resources\PodcastShows\Schemas\PodcastShowForm;
use App\Filament\Resources\PodcastShows\Tables\PodcastShowsTable;
use App\Models\PodcastShow;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PodcastShowResource extends Resource
{
    protected static ?string $model = PodcastShow::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMicrophone;

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Podcasts';

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
        return PodcastShowForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PodcastShowsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            EpisodesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPodcastShows::route('/'),
            'create' => CreatePodcastShow::route('/create'),
            'edit' => EditPodcastShow::route('/{record}/edit'),
        ];
    }
}
