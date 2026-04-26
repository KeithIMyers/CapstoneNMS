<?php

namespace App\Filament\Resources\LiveBlogs\RelationManagers;

use App\Models\LiveBlogPoll;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PollsRelationManager extends RelationManager
{
    protected static string $relationship = 'polls';
    protected static ?string $title = 'Polls';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('question')
                ->required()
                ->maxLength(500)
                ->helperText('Shown above the choice list on the public live-blog page.'),

            Repeater::make('options')
                ->label('Choices')
                ->minItems(2)
                ->maxItems(6)
                ->schema([
                    TextInput::make('id')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(255)
                        ->required()
                        ->helperText('Stable per-choice id, 0..255.'),
                    TextInput::make('label')
                        ->required()
                        ->maxLength(120),
                ])
                ->reorderable(true)
                ->required(),

            Select::make('status')
                ->options([
                    LiveBlogPoll::STATUS_OPEN   => 'Open (accepting votes)',
                    LiveBlogPoll::STATUS_CLOSED => 'Closed',
                ])
                ->default(LiveBlogPoll::STATUS_OPEN)
                ->required(),

            DateTimePicker::make('closes_at')
                ->seconds(false)
                ->native(false)
                ->helperText('Optional auto-close time. Leave blank to leave the poll open until manually closed.'),

            Toggle::make('is_pinned')
                ->label('Pin to top of live blog')
                ->default(true),

            TextInput::make('sort_order')->numeric()->default(0),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('question')
            ->columns([
                TextColumn::make('question')->limit(60)->wrap(),
                TextColumn::make('status')->badge()
                    ->color(fn ($s) => $s === 'open' ? 'success' : 'gray'),
                TextColumn::make('total_votes')->numeric()->label('Votes')->sortable(),
                IconColumn::make('is_pinned')->boolean()->label('📌')->toggleable(),
                TextColumn::make('closes_at')->dateTime('M j · g:i a')->toggleable()->placeholder('—'),
                TextColumn::make('created_at')->dateTime('M j · g:i a')->toggleable()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([CreateAction::make()->label('Add poll')])
            ->recordActions([
                Action::make('close')
                    ->label('Close')
                    ->color('warning')
                    ->visible(fn (LiveBlogPoll $r) => $r->status === LiveBlogPoll::STATUS_OPEN)
                    ->action(fn (LiveBlogPoll $r) => $r->forceFill(['status' => LiveBlogPoll::STATUS_CLOSED])->save()),
                Action::make('reopen')
                    ->label('Reopen')
                    ->visible(fn (LiveBlogPoll $r) => $r->status === LiveBlogPoll::STATUS_CLOSED)
                    ->action(fn (LiveBlogPoll $r) => $r->forceFill(['status' => LiveBlogPoll::STATUS_OPEN])->save()),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
