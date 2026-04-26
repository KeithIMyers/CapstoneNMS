<?php

namespace App\Filament\Resources\AiAgents\Pages;

use App\Filament\Resources\AiAgents\AiAgentResource;
use App\Models\AiAgent;
use App\Services\Ai\DefaultAgentDefinitions;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListAiAgents extends ListRecords
{
    protected static string $resource = AiAgentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('seedAgents')
                ->label('Seed starter agents')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Creates a row for every built-in agent that doesn\'t already exist. Never overwrites edits you\'ve already made — use the per-row "Reset to defaults" action for that.')
                ->action(function () {
                    $created = 0;
                    foreach (DefaultAgentDefinitions::all() as $key => $def) {
                        if (! AiAgent::where('key', $key)->exists()) {
                            AiAgent::create($def + ['key' => $key, 'is_active' => true]);
                            $created++;
                        }
                    }
                    Notification::make()
                        ->title($created > 0 ? "Created {$created} agents" : 'All built-in agents already exist')
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
