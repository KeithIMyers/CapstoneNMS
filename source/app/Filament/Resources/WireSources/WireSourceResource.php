<?php

namespace App\Filament\Resources\WireSources;

use App\Filament\Resources\WireSources\Pages\CreateWireSource;
use App\Filament\Resources\WireSources\Pages\EditWireSource;
use App\Filament\Resources\WireSources\Pages\ListWireSources;
use App\Models\Category;
use App\Models\User;
use App\Models\WireSource;
use App\Services\Wire\WireIngestor;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WireSourceResource extends Resource
{
    protected static ?string $model = WireSource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRss;

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Wire sources';

    public static function canViewAny(): bool { return auth()->user()?->isEditor() ?? false; }
    public static function canCreate(): bool { return auth()->user()?->isAdmin() ?? false; }
    public static function canEdit($record): bool { return auth()->user()?->isAdmin() ?? false; }
    public static function canDelete($record): bool { return auth()->user()?->isAdmin() ?? false; }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(150),
                TextInput::make('feed_url')->url()->required()->maxLength(500),
                Toggle::make('is_active')->default(true)->inline(false),
                Toggle::make('ai_rewrite')
                    ->label('Run through copyedit assistant before saving')
                    ->helperText('When on, item bodies are rephrased via the article.copyedit prompt before landing as drafts.')
                    ->inline(false),
                Select::make('default_category_id')
                    ->label('Default category')
                    ->options(fn () => Category::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required(),
                Select::make('default_author_id')
                    ->label('Default byline')
                    ->options(fn () => User::query()->whereIn('role', ['admin', 'sub_admin', 'editor', 'author'])->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->helperText('Author attributed to drafts created from this source.'),
                TextInput::make('limit_per_run')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(50)
                    ->default(10)
                    ->helperText('Max items pulled in one CRON run. Keeps a busy feed from blowing the AI budget.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('feed_url')->limit(40)->toggleable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                IconColumn::make('ai_rewrite')->label('AI rewrite')->boolean()->toggleable(),
                TextColumn::make('defaultCategory.name')->label('Category')->toggleable(),
                TextColumn::make('last_fetched_at')->label('Last fetched')->since()->placeholder('never')->toggleable(),
            ])
            ->recordActions([
                Action::make('ingestNow')
                    ->label('Ingest now')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(function (WireSource $record) {
                        $stats = app(WireIngestor::class)->ingestOne($record);
                        Notification::make()
                            ->title("fetched={$stats['fetched']} · created={$stats['created']} · errors={$stats['errors']}")
                            ->color($stats['errors'] > 0 ? 'warning' : 'success')
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListWireSources::route('/'),
            'create' => CreateWireSource::route('/create'),
            'edit'   => EditWireSource::route('/{record}/edit'),
        ];
    }
}
