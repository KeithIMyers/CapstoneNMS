<?php

namespace App\Filament\Resources\AiAgents\Schemas;

use App\Models\AiAgent;
use App\Models\AiProvider;
use App\Services\Ai\Tools\ToolRegistry;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AiAgentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('key')
                    ->required()
                    ->maxLength(80)
                    ->helperText('Stable identifier — e.g. "editorial.research". Don\'t rename once in use.')
                    ->disabled(fn ($record) => $record !== null)
                    ->dehydrated(),
                TextInput::make('name')->required()->maxLength(200),
                Textarea::make('description')
                    ->rows(2)
                    ->maxLength(500)
                    ->columnSpanFull()
                    ->helperText('What this agent does. Shown in the agent picker.'),
                Toggle::make('is_active')->default(true)->inline(false),
            ]),

            Section::make('System prompt')
                ->description('The agent\'s instructions. The runner appends a tool inventory and a strict response-format contract — keep this prompt focused on goals and workflow, not output format.')
                ->schema([
                    Textarea::make('system_prompt')
                        ->label(false)
                        ->required()
                        ->rows(14)
                        ->columnSpanFull(),
                ]),

            Section::make('Tools')
                ->description('Allow-list of tools this agent may call. Tools not listed here are invisible to the model.')
                ->schema([
                    Select::make('tool_keys')
                        ->label(false)
                        ->multiple()
                        ->required()
                        ->options(function () {
                            $registry = app(ToolRegistry::class);
                            $opts = [];
                            foreach ($registry->all() as $tool) {
                                $opts[$tool->key()] = $tool->key().' — '.\Illuminate\Support\Str::limit($tool->description(), 80);
                            }
                            return $opts;
                        })
                        ->helperText('Pick only the tools needed for the task. Fewer tools = more reliable behavior.'),
                ]),

            Section::make('Loop & model settings')->columns(3)->schema([
                TextInput::make('max_iterations')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(20)
                    ->default(8)
                    ->helperText('Hard cap on tool-call rounds before the runner gives up.'),
                TextInput::make('temperature')
                    ->numeric()
                    ->step(0.05)
                    ->minValue(0)
                    ->maxValue(2)
                    ->default(0.3),
                TextInput::make('max_tokens_per_step')
                    ->numeric()
                    ->minValue(128)
                    ->maxValue(8192)
                    ->default(1024)
                    ->helperText('Response cap per iteration.'),
                Select::make('provider_id')
                    ->label('Pin provider (optional)')
                    ->options(fn () => AiProvider::active()->orderBy('name')->pluck('name', 'id'))
                    ->placeholder('— use default —')
                    ->columnSpan(1),
                TextInput::make('model_override')
                    ->label('Pin model (optional)')
                    ->maxLength(120)
                    ->placeholder('Leave blank for provider default')
                    ->columnSpan(2),
            ]),

            Section::make('Schedule')
                ->description('Optional. When set, the agents:run-scheduled CRON command picks this agent up and runs it with the standing input below. Leave the frequency blank for manual-only agents.')
                ->columns(3)
                ->schema([
                    Select::make('schedule_frequency')
                        ->label('Frequency')
                        ->options(AiAgent::FREQUENCIES)
                        ->placeholder('— manual only —')
                        ->live(),
                    TextInput::make('schedule_hour_utc')
                        ->label('Hour of day (UTC)')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(23)
                        ->default(13) // ~6am PT
                        ->helperText('Daily / weekly: hour to fire at (0-23 UTC).')
                        ->visible(fn ($get) => in_array($get('schedule_frequency'), [AiAgent::FREQ_DAILY, AiAgent::FREQ_WEEKLY], true)),
                    TextInput::make('last_run_at')
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Stamped on each successful scheduled run.')
                        ->afterStateHydrated(function ($component, $record) {
                            $component->state($record?->last_run_at?->format('Y-m-d H:i \U\T\C') ?? 'never');
                        }),
                    Textarea::make('standing_input')
                        ->label('Standing input')
                        ->rows(3)
                        ->maxLength(2000)
                        ->columnSpanFull()
                        ->helperText('The user message sent on every scheduled run. e.g. "Compose today\'s daily brief."')
                        ->required(fn ($get) => filled($get('schedule_frequency')))
                        ->visible(fn ($get) => filled($get('schedule_frequency'))),
                ]),
        ]);
    }
}
