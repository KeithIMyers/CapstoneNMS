<?php

namespace App\Filament\Resources\AiRequests;

use App\Filament\Resources\AiRequests\Pages\ListAiRequests;
use App\Filament\Resources\AiRequests\Tables\AiRequestsTable;
use App\Models\AiRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Read-only usage log. Rows are created by the AiClient service and
 * never edited by hand — so this resource exposes a list page only.
 */
class AiRequestResource extends Resource
{
    protected static ?string $model = AiRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Usage log';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function table(Table $table): Table
    {
        return AiRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiRequests::route('/'),
        ];
    }
}
