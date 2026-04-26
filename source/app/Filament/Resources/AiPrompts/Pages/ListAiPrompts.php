<?php

namespace App\Filament\Resources\AiPrompts\Pages;

use App\Filament\Resources\AiPrompts\AiPromptResource;
use App\Models\AiPrompt;
use App\Services\Ai\Assistant;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListAiPrompts extends ListRecords
{
    protected static string $resource = AiPromptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('seedDefaults')
                ->label('Seed built-in prompts')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Creates a row for every built-in assistant that doesn\'t already have one. Never overwrites edits you\'ve already made.')
                ->action(function () {
                    $created = 0;
                    foreach (Assistant::DEFAULT_PROMPTS as $key => $prompt) {
                        $existing = AiPrompt::where('key', $key)->first();
                        if (! $existing) {
                            AiPrompt::create([
                                'key'           => $key,
                                'name'          => ucwords(str_replace(['.', '_'], ' ', $key)),
                                'system_prompt' => $prompt,
                                'temperature'   => 0.4,
                                'max_tokens'    => 1024,
                            ]);
                            $created++;
                        }
                    }
                    Notification::make()
                        ->title($created > 0 ? "Seeded {$created} prompts" : 'All built-in prompts already exist')
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
