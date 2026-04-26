<?php

namespace App\Filament\Resources\AiPrompts\Schemas;

use App\Models\AiProvider;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AiPromptForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('key')
                    ->required()
                    ->maxLength(80)
                    ->helperText('Stable identifier the code looks up — e.g. "article.copyedit". Don\'t rename once in use.')
                    ->disabled(fn ($record) => $record !== null)
                    ->dehydrated(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(200),
                Textarea::make('description')
                    ->rows(2)
                    ->maxLength(500)
                    ->columnSpanFull()
                    ->helperText('Internal notes — what this prompt is for, any gotchas.'),
            ]),

            Section::make('System prompt')
                ->description('This is the single message that tells the model who it is and what to do. The user-supplied content (article body, comment, etc.) is appended automatically.')
                ->schema([
                    Textarea::make('system_prompt')
                        ->label(false)
                        ->required()
                        ->rows(14)
                        ->columnSpanFull(),
                ]),

            Section::make('Model settings')->columns(3)->schema([
                TextInput::make('temperature')
                    ->numeric()
                    ->step(0.05)
                    ->minValue(0)
                    ->maxValue(2)
                    ->default(0.4)
                    ->helperText('0 = deterministic, 2 = very creative.'),
                TextInput::make('max_tokens')
                    ->numeric()
                    ->minValue(64)
                    ->maxValue(16384)
                    ->default(1024)
                    ->helperText('Max completion length.'),
                Select::make('provider_id')
                    ->label('Pin provider (optional)')
                    ->options(fn () => AiProvider::active()->orderBy('name')->pluck('name', 'id'))
                    ->placeholder('— use default —')
                    ->helperText('Override which provider this prompt runs through.'),
                TextInput::make('model_override')
                    ->maxLength(120)
                    ->placeholder('Leave blank for provider default')
                    ->columnSpan(2)
                    ->helperText('Pin a specific model for this prompt (e.g., claude-3-5-haiku for moderation).'),
            ]),
        ]);
    }
}
