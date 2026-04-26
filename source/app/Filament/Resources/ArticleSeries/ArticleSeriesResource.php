<?php

namespace App\Filament\Resources\ArticleSeries;

use App\Filament\Resources\ArticleSeries\Pages\CreateArticleSeries;
use App\Filament\Resources\ArticleSeries\Pages\EditArticleSeries;
use App\Filament\Resources\ArticleSeries\Pages\ListArticleSeriesRecords;
use App\Models\ArticleSeries;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ArticleSeriesResource extends Resource
{
    protected static ?string $model = ArticleSeries::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Article series';

    public static function canViewAny(): bool { return auth()->user()?->isEditor() ?? false; }
    public static function canCreate(): bool { return auth()->user()?->isEditor() ?? false; }
    public static function canEdit($record): bool { return auth()->user()?->isEditor() ?? false; }
    public static function canDelete($record): bool { return auth()->user()?->isAdmin() ?? false; }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(200)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, callable $set, $get, $record) {
                        if (! $record && empty($get('slug'))) {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),
                TextInput::make('slug')->required()->maxLength(200)->helperText('URL: /series/{slug}'),
                Textarea::make('description')->rows(3)->maxLength(1000)->columnSpanFull(),
                FileUpload::make('hero_image')
                    ->image()
                    ->imageEditor()
                    ->disk('public')
                    ->directory('series')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(4096)
                    ->columnSpanFull(),
                Toggle::make('is_active')->default(true)->inline(false),
                TextInput::make('sort')->numeric()->default(0),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->toggleable()->searchable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('articles_count')
                    ->label('Articles')
                    ->counts('articles')
                    ->toggleable(),
                TextColumn::make('sort')->sortable(),
                TextColumn::make('updated_at')->dateTime('M j, Y')->toggleable(),
            ])
            ->defaultSort('sort')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListArticleSeriesRecords::route('/'),
            'create' => CreateArticleSeries::route('/create'),
            'edit'   => EditArticleSeries::route('/{record}/edit'),
        ];
    }
}
