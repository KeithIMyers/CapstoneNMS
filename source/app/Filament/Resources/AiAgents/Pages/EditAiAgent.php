<?php

namespace App\Filament\Resources\AiAgents\Pages;

use App\Filament\Resources\AiAgents\AiAgentResource;
use App\Services\Ai\Agent;
use App\Services\Ai\DefaultAgentDefinitions;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditAiAgent extends EditRecord
{
    protected static string $resource = AiAgentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runOnce')
                ->label('Run agent')
                ->icon('heroicon-o-play')
                ->color('primary')
                ->schema([
                    Textarea::make('input')
                        ->label('Input')
                        ->required()
                        ->rows(5)
                        ->maxLength(8000)
                        ->helperText('What you want this agent to do. The agent will loop until it produces a final answer or hits max_iterations.'),
                ])
                ->action(function (array $data) {
                    $run = app(Agent::class)->run(
                        agentKey: $this->record->key,
                        input: (string) $data['input'],
                        userId: auth()->id(),
                    );

                    $title = match ($run->status) {
                        \App\Models\AgentRun::STATUS_DONE => 'Run complete · '.($run->iterations).' steps',
                        default => 'Run failed',
                    };
                    Notification::make()
                        ->title($title)
                        ->body(\Illuminate\Support\Str::limit((string) ($run->final_output ?? $run->error_message), 600))
                        ->color($run->status === \App\Models\AgentRun::STATUS_DONE ? 'success' : 'danger')
                        ->persistent()
                        ->send();

                    return redirect(\App\Filament\Resources\AgentRuns\AgentRunResource::getUrl('view', ['record' => $run->id]));
                }),

            Action::make('resetToDefaults')
                ->label('Reset to defaults')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->visible(fn () => DefaultAgentDefinitions::get($this->record->key) !== null)
                ->requiresConfirmation()
                ->modalHeading('Reset this agent to its built-in defaults?')
                ->modalDescription('Replaces the system prompt, tool list, iteration cap, temperature, and max-tokens-per-step with the values shipped with the latest version of the app. Provider / model overrides and schedule fields are preserved. Your existing customizations will be lost.')
                ->action(function () {
                    $defaults = DefaultAgentDefinitions::get($this->record->key);
                    if (! $defaults) {
                        Notification::make()
                            ->title('No defaults available')
                            ->body('This agent isn\'t one of the built-in templates.')
                            ->warning()
                            ->send();
                        return;
                    }

                    // Preserve operational fields the user has tuned per
                    // install (provider pin, schedule). Only reset the
                    // prompt + tool surface + loop knobs.
                    $this->record->forceFill([
                        'name'                 => $defaults['name'],
                        'description'          => $defaults['description'],
                        'system_prompt'        => $defaults['system_prompt'],
                        'tool_keys'            => $defaults['tool_keys'],
                        'max_iterations'       => $defaults['max_iterations'],
                        'temperature'          => $defaults['temperature'],
                        'max_tokens_per_step'  => $defaults['max_tokens_per_step'],
                        'updated_by'           => auth()->id(),
                    ])->save();

                    Notification::make()
                        ->title('Reset to defaults')
                        ->body('Provider / model overrides and schedule preserved.')
                        ->success()
                        ->send();

                    return redirect(\App\Filament\Resources\AiAgents\AiAgentResource::getUrl('edit', ['record' => $this->record->id]));
                }),

            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = auth()->id();
        return $data;
    }
}
