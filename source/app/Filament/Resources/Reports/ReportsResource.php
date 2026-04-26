<?php

namespace App\Filament\Resources\Reports;

use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Resources\Reports\Schemas\ReportsForm;
use App\Filament\Resources\Reports\Tables\ReportsTable;
use App\Models\Reports;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ReportsResource extends Resource
{
    protected static ?string $model = Reports::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|\UnitEnum|null $navigationGroup = 'Moderation';

    protected static ?int $navigationSort = 21;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isEditor() ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->isEditor() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return ReportsForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReportsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReports::route('/'),
        ];
    }
}
