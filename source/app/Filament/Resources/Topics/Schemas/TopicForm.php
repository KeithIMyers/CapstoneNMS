<?php

namespace App\Filament\Resources\Topics\Schemas;

use App\Models\Category;
use App\Models\News;
use App\Models\Tag;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class TopicForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->columnSpanFull()->tabs([
                Tab::make('Topic')->schema(self::topicTab()),
                Tab::make('Pinned articles')->schema(self::pinnedTab()),
                Tab::make('Auto-include rules')->schema(self::rulesTab()),
            ]),
        ]);
    }

    private static function topicTab(): array
    {
        return [
            Section::make()->columns(2)->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(150)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, callable $set, $get, $record) {
                        if (! $record && empty($get('slug'))) {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(150)
                    ->helperText('URL: /topics/{slug}'),
                Textarea::make('description')
                    ->rows(3)
                    ->maxLength(1000)
                    ->columnSpanFull()
                    ->helperText('Shown on the topic landing page; useful for SEO.'),
                FileUpload::make('hero_image')
                    ->image()
                    ->imageEditor()
                    ->disk('public')
                    ->directory('topics')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(4096)
                    ->columnSpanFull(),
                Toggle::make('is_active')->default(true)->inline(false),
                TextInput::make('sort')->numeric()->default(0),
            ]),
        ];
    }

    private static function pinnedTab(): array
    {
        return [
            Section::make()
                ->description('Editorially pinned articles. These appear at the top of the topic page in the order selected.')
                ->schema([
                    Select::make('manualArticles')
                        ->label('Pinned articles')
                        ->multiple()
                        ->relationship(
                            name: 'manualArticles',
                            titleAttribute: 'title',
                            modifyQueryUsing: fn ($query) => $query->orderByDesc('published_at')->limit(500),
                        )
                        ->searchable(['title', 'slug'])
                        ->preload()
                        ->helperText('Search by title. The pivot sort field auto-assigns from selection order on save.')
                        ->saveRelationshipsUsing(function ($component, $state) {
                            $state = $state ?? [];
                            $sync = [];
                            foreach (array_values($state) as $i => $id) {
                                $sync[$id] = ['sort' => $i];
                            }
                            $component->getRecord()->manualArticles()->sync($sync);
                        })
                        ->columnSpanFull(),
                ]),
        ];
    }

    private static function rulesTab(): array
    {
        return [
            Section::make()
                ->description('Optional filters that auto-include published articles matching any of the rules below. Leave blank for a fully manual topic.')
                ->schema([
                    Select::make('auto_rules.categories')
                        ->label('Categories')
                        ->multiple()
                        ->options(fn () => Category::orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->preload(),
                    Select::make('auto_rules.tags')
                        ->label('Tags')
                        ->multiple()
                        ->options(fn () => Tag::orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->preload(),
                    TextInput::make('auto_rules.keyword')
                        ->label('Keyword')
                        ->maxLength(120)
                        ->helperText('Matches article title or excerpt. Case-insensitive.'),
                ]),
        ];
    }
}
