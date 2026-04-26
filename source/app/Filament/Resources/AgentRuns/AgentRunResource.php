<?php

namespace App\Filament\Resources\AgentRuns;

use App\Filament\Resources\AgentRuns\Pages\ListAgentRuns;
use App\Filament\Resources\AgentRuns\Pages\ViewAgentRun;
use App\Filament\Resources\AgentRuns\Tables\AgentRunsTable;
use App\Models\AgentRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AgentRunResource extends Resource
{
    protected static ?string $model = AgentRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Agent runs';

    public static function canViewAny(): bool { return auth()->user()?->isAdmin() ?? false; }
    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return auth()->user()?->isAdmin() ?? false; }

    public static function table(Table $table): Table
    {
        return AgentRunsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentRuns::route('/'),
            'view'  => ViewAgentRun::route('/{record}'),
        ];
    }
}
