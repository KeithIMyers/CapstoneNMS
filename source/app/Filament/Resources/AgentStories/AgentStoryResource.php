<?php

namespace App\Filament\Resources\AgentStories;

use App\Filament\Resources\AgentStories\Pages\CreateAgentStory;
use App\Filament\Resources\AgentStories\Pages\EditAgentStory;
use App\Filament\Resources\AgentStories\Pages\ListAgentStories;
use App\Models\AgentStory;
use App\Models\Category;
use App\Models\NewsAgent;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AgentStoryResource extends Resource
{
    protected static ?string $model = AgentStory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|\UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Story queue';

    public static function canViewAny(): bool { return auth()->user()?->isEditor() ?? false; }
    public static function canCreate(): bool { return auth()->user()?->isEditor() ?? false; }
    public static function canEdit($record): bool { return auth()->user()?->isEditor() ?? false; }
    public static function canDelete($record): bool { return auth()->user()?->isAdmin() ?? false; }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('agent_id')
                    ->label('Agent')
                    ->options(fn () => NewsAgent::active()->with('user')->get()->pluck('user.name', 'id'))
                    ->searchable()
                    ->required(),
                Select::make('category_id')
                    ->label('Category (optional)')
                    ->options(fn () => Category::orderBy('name')->pluck('name', 'id'))
                    ->placeholder('— agent / topic decides —')
                    ->searchable(),
                Textarea::make('topic')
                    ->label('Topic / prompt')
                    ->required()
                    ->rows(3)
                    ->maxLength(500)
                    ->columnSpanFull(),
                Textarea::make('brief')
                    ->label('Editor brief (optional)')
                    ->rows(3)
                    ->maxLength(2000)
                    ->columnSpanFull()
                    ->helperText('Extra notes for the agent: angle, sources to use, tone constraints.'),
                Textarea::make('source_urls')
                    ->label('Source URLs (one per line, optional)')
                    ->rows(3)
                    ->maxLength(2000)
                    ->columnSpanFull()
                    ->afterStateHydrated(function ($component, $state) {
                        if (is_array($state)) $component->state(implode("\n", $state));
                    })
                    ->dehydrateStateUsing(function ($state) {
                        return collect(preg_split("/\r?\n/", (string) $state))
                            ->map(fn ($l) => trim($l))
                            ->filter(fn ($l) => $l !== '' && preg_match('~^https?://~i', $l))
                            ->values()
                            ->all();
                    }),
                TextInput::make('priority')
                    ->numeric()
                    ->default(50)
                    ->minValue(0)
                    ->maxValue(255)
                    ->helperText('Lower fires sooner.'),
                DateTimePicker::make('scheduled_for')
                    ->label('Earliest run time')
                    ->seconds(false)
                    ->native(false)
                    ->helperText('Leave blank for "as soon as possible".'),
                Select::make('status')
                    ->options(AgentStory::STATUSES)
                    ->default(AgentStory::STATUS_QUEUED)
                    ->required(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('agent.user.name')->label('Agent')->searchable()->sortable(),
                TextColumn::make('topic')->limit(60)->wrap()->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        AgentStory::STATUS_QUEUED    => 'gray',
                        AgentStory::STATUS_DRAFTING  => 'warning',
                        AgentStory::STATUS_DRAFTED   => 'warning',
                        AgentStory::STATUS_PUBLISHED => 'success',
                        AgentStory::STATUS_FAILED    => 'danger',
                        AgentStory::STATUS_SKIPPED   => 'gray',
                        default                      => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => AgentStory::STATUSES[$state] ?? $state),
                TextColumn::make('priority')->sortable()->toggleable(),
                TextColumn::make('scheduled_for')->dateTime('M j · g:i a')->placeholder('asap')->toggleable(),
                TextColumn::make('news.title')
                    ->label('Article')
                    ->limit(40)
                    ->placeholder('—')
                    ->url(fn ($record) => $record->news_id
                        ? \App\Filament\Resources\News\NewsResource::getUrl('edit', ['record' => $record->news_id])
                        : null)
                    ->toggleable(),
                TextColumn::make('attempt_count')->label('Tries')->toggleable(),
                TextColumn::make('completed_at')->dateTime('M j · g:i a')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(AgentStory::STATUSES)->default(AgentStory::STATUS_QUEUED),
                SelectFilter::make('agent_id')
                    ->label('Agent')
                    ->options(fn () => NewsAgent::active()->with('user')->get()->pluck('user.name', 'id')),
            ])
            ->defaultSort('priority')
            ->recordActions([
                Action::make('runNow')
                    ->label('Run now')
                    ->icon('heroicon-o-play')
                    ->color('primary')
                    ->visible(fn ($record) => in_array($record->status, [AgentStory::STATUS_QUEUED, AgentStory::STATUS_FAILED], true))
                    ->requiresConfirmation()
                    ->action(function (AgentStory $record) {
                        $agent = $record->agent;
                        if (! $agent) {
                            Notification::make()->title('Agent missing for this story')->danger()->send();
                            return;
                        }
                        $result = app(\App\Services\News\NewsAgentDispatcher::class)->dispatchStory($agent, $record);
                        Notification::make()
                            ->title($result['ok'] ? 'Story dispatched' : 'Dispatch failed')
                            ->body(\Illuminate\Support\Str::limit((string) ($result['message'] ?? ''), 600))
                            ->color($result['ok'] ? 'success' : 'danger')
                            ->persistent()
                            ->send();
                    }),
                Action::make('skip')
                    ->label('Skip')
                    ->icon('heroicon-o-archive-box-x-mark')
                    ->color('gray')
                    ->visible(fn ($record) => in_array($record->status, [AgentStory::STATUS_QUEUED, AgentStory::STATUS_FAILED], true))
                    ->action(fn (AgentStory $record) => $record->update(['status' => AgentStory::STATUS_SKIPPED])),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('skipBulk')
                        ->label('Skip')
                        ->icon('heroicon-o-archive-box-x-mark')
                        ->action(fn (\Illuminate\Database\Eloquent\Collection $records) =>
                            AgentStory::whereIn('id', $records->pluck('id'))->update(['status' => AgentStory::STATUS_SKIPPED])),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListAgentStories::route('/'),
            'create' => CreateAgentStory::route('/create'),
            'edit'   => EditAgentStory::route('/{record}/edit'),
        ];
    }
}
