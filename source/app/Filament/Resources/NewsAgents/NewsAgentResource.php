<?php

namespace App\Filament\Resources\NewsAgents;

use App\Filament\Resources\NewsAgents\Pages\CreateNewsAgent;
use App\Filament\Resources\NewsAgents\Pages\EditNewsAgent;
use App\Filament\Resources\NewsAgents\Pages\ListNewsAgents;
use App\Filament\Resources\NewsAgents\Schemas\NewsAgentForm;
use App\Filament\Resources\NewsAgents\Tables\NewsAgentsTable;
use App\Models\NewsAgent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class NewsAgentResource extends Resource
{
    protected static ?string $model = NewsAgent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|\UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'News agents';

    public static function canViewAny(): bool { return auth()->user()?->isAdmin() ?? false; }
    public static function canCreate(): bool { return auth()->user()?->isAdmin() ?? false; }
    public static function canEdit($record): bool { return auth()->user()?->isAdmin() ?? false; }
    public static function canDelete($record): bool { return auth()->user()?->isAdmin() ?? false; }

    public static function form(Schema $schema): Schema
    {
        return NewsAgentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NewsAgentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListNewsAgents::route('/'),
            'create' => CreateNewsAgent::route('/create'),
            'edit'   => EditNewsAgent::route('/{record}/edit'),
        ];
    }
}
