<?php

namespace App\Filament\Resources\AiProviders\Schemas;

use App\Models\AiProvider;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AiProviderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(120)
                    ->helperText('Display label — e.g., "Claude (prod)" or "Ollama llama3.1".'),
                Select::make('kind')
                    ->label('Provider kind')
                    ->options(AiProvider::KINDS)
                    ->required()
                    ->reactive()
                    ->helperText('Selects which driver talks to the endpoint.'),

                TextInput::make('base_url')
                    ->label('Base URL')
                    ->url()
                    ->maxLength(500)
                    ->placeholder(fn ($get) => match ($get('kind')) {
                        AiProvider::KIND_OPENAI    => 'https://api.openai.com/v1',
                        AiProvider::KIND_ANTHROPIC => 'https://api.anthropic.com/v1',
                        AiProvider::KIND_OLLAMA    => 'http://localhost:11434',
                        default                    => '',
                    })
                    ->helperText('Leave blank for the provider default. Override to point at a compatible gateway (Azure OpenAI, LiteLLM, etc.).'),

                TextInput::make('api_key')
                    ->label('API key')
                    ->password()
                    ->revealable()
                    ->maxLength(500)
                    ->helperText('Encrypted at rest. Leave blank for Ollama when no gateway sits in front of it.'),

                TextInput::make('default_model')
                    ->maxLength(120)
                    ->placeholder(fn ($get) => match ($get('kind')) {
                        AiProvider::KIND_OPENAI    => 'gpt-4o-mini',
                        AiProvider::KIND_ANTHROPIC => 'claude-3-5-sonnet-latest',
                        AiProvider::KIND_OLLAMA    => 'llama3.1',
                        default                    => '',
                    })
                    ->helperText('Used when a call doesn\'t pin a specific model.'),

                TextInput::make('embedding_model')
                    ->label('Embedding model (optional)')
                    ->maxLength(120)
                    ->placeholder(fn ($get) => match ($get('kind')) {
                        AiProvider::KIND_OPENAI => 'text-embedding-3-small',
                        AiProvider::KIND_OLLAMA => 'nomic-embed-text',
                        default                 => '— not supported —',
                    })
                    ->disabled(fn ($get) => $get('kind') === AiProvider::KIND_ANTHROPIC)
                    ->helperText('Set to enable semantic search and the embeddings:rebuild CRON. Anthropic does not currently offer embeddings.'),

                TextInput::make('max_output_tokens')
                    ->numeric()
                    ->default(2048)
                    ->minValue(64)
                    ->maxValue(32768)
                    ->helperText('Ceiling for any single completion from this provider.'),

                Toggle::make('is_active')->default(true)->inline(false),
                Toggle::make('is_default')
                    ->inline(false)
                    ->helperText('Used when no provider is pinned per-call. Only one row can be the default.'),
            ]),

            \Filament\Schemas\Components\Section::make('Budget caps')
                ->description('Hard ceilings on AI spend through this provider. Calls are refused (and logged in the usage log) once a cap is hit. Leave blank for uncapped — most useful in dev / testing. Values in US dollars.')
                ->columns(2)
                ->schema([
                    TextInput::make('daily_budget_usd')
                        ->label('Daily budget (USD)')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0)
                        ->placeholder('e.g. 5.00')
                        ->helperText('Resets at midnight server time.')
                        // Stored as integer micro-USD; expose as USD in the form.
                        ->afterStateHydrated(function ($component, $state, $record) {
                            if ($record && $record->daily_budget_microusd) {
                                $component->state(round($record->daily_budget_microusd / 1_000_000, 2));
                            }
                        })
                        ->dehydrated(false)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function ($state, callable $set) {
                            $set('daily_budget_microusd', $state === null || $state === ''
                                ? null
                                : (int) round(((float) $state) * 1_000_000));
                        }),

                    TextInput::make('monthly_budget_usd')
                        ->label('Monthly budget (USD)')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0)
                        ->placeholder('e.g. 100.00')
                        ->helperText('Resets on the 1st of each calendar month.')
                        ->afterStateHydrated(function ($component, $state, $record) {
                            if ($record && $record->monthly_budget_microusd) {
                                $component->state(round($record->monthly_budget_microusd / 1_000_000, 2));
                            }
                        })
                        ->dehydrated(false)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function ($state, callable $set) {
                            $set('monthly_budget_microusd', $state === null || $state === ''
                                ? null
                                : (int) round(((float) $state) * 1_000_000));
                        }),

                    // Hidden integer fields actually persisted by Filament.
                    \Filament\Forms\Components\Hidden::make('daily_budget_microusd'),
                    \Filament\Forms\Components\Hidden::make('monthly_budget_microusd'),
                ]),
        ]);
    }
}
