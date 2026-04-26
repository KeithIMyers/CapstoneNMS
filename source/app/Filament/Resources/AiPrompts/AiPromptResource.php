<?php

namespace App\Filament\Resources\AiPrompts;

use App\Filament\Resources\AiPrompts\Pages\CreateAiPrompt;
use App\Filament\Resources\AiPrompts\Pages\EditAiPrompt;
use App\Filament\Resources\AiPrompts\Pages\ListAiPrompts;
use App\Filament\Resources\AiPrompts\Schemas\AiPromptForm;
use App\Filament\Resources\AiPrompts\Tables\AiPromptsTable;
use App\Models\AiPrompt;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AiPromptResource extends Resource
{
    protected static ?string $model = AiPrompt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|\UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Prompts';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return AiPromptForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AiPromptsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiPrompts::route('/'),
            'create' => CreateAiPrompt::route('/create'),
            'edit' => EditAiPrompt::route('/{record}/edit'),
        ];
    }
}
