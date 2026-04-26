<?php

namespace App\Filament\Resources\AiAgents;

use App\Filament\Resources\AiAgents\Pages\CreateAiAgent;
use App\Filament\Resources\AiAgents\Pages\EditAiAgent;
use App\Filament\Resources\AiAgents\Pages\ListAiAgents;
use App\Filament\Resources\AiAgents\Schemas\AiAgentForm;
use App\Filament\Resources\AiAgents\Tables\AiAgentsTable;
use App\Models\AiAgent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AiAgentResource extends Resource
{
    protected static ?string $model = AiAgent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|\UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Agents';

    public static function canViewAny(): bool { return auth()->user()?->isAdmin() ?? false; }
    public static function canCreate(): bool { return auth()->user()?->isAdmin() ?? false; }
    public static function canEdit($record): bool { return auth()->user()?->isAdmin() ?? false; }
    public static function canDelete($record): bool { return auth()->user()?->isAdmin() ?? false; }

    public static function form(Schema $schema): Schema
    {
        return AiAgentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AiAgentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiAgents::route('/'),
            'create' => CreateAiAgent::route('/create'),
            'edit' => EditAiAgent::route('/{record}/edit'),
        ];
    }
}
