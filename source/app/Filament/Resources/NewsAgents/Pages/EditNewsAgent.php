<?php

namespace App\Filament\Resources\NewsAgents\Pages;

use App\Filament\Resources\NewsAgents\NewsAgentResource;
use App\Models\AgentStory;
use App\Services\News\NewsAgentDispatcher;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Str;

class EditNewsAgent extends EditRecord
{
    protected static string $resource = NewsAgentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runOnce')
                ->label('Dispatch a story now')
                ->icon('heroicon-o-play')
                ->color('primary')
                ->schema([
                    Textarea::make('topic')
                        ->label('Topic / prompt')
                        ->required()
                        ->rows(3)
                        ->maxLength(500)
                        ->helperText('What should this agent write about? Same input shape the queue uses.'),
                    Textarea::make('source_urls')
                        ->label('Source URLs (optional, one per line)')
                        ->rows(3)
                        ->maxLength(2000),
                    Select::make('category_id')
                        ->label('Category')
                        ->options(fn () => \App\Models\Category::orderBy('name')->pluck('name', 'id'))
                        ->searchable(),
                ])
                ->action(function (array $data) {
                    $urls = collect(preg_split("/\r?\n/", (string) ($data['source_urls'] ?? '')))
                        ->map(fn ($l) => trim($l))
                        ->filter(fn ($l) => $l !== '' && preg_match('~^https?://~i', $l))
                        ->values()
                        ->all();

                    $story = AgentStory::create([
                        'agent_id'    => $this->record->id,
                        'topic'       => (string) $data['topic'],
                        'source_urls' => $urls,
                        'category_id' => $data['category_id'] ?? null,
                        'status'      => AgentStory::STATUS_QUEUED,
                        'priority'    => 1, // ahead of CRON-queued items
                        'scheduled_for' => now(),
                    ]);

                    $result = app(NewsAgentDispatcher::class)->dispatchStory($this->record, $story);

                    Notification::make()
                        ->title($result['ok'] ? 'Story drafted' : 'Story failed')
                        ->body(Str::limit((string) ($result['message'] ?? ''), 600))
                        ->color($result['ok'] ? 'success' : 'danger')
                        ->persistent()
                        ->send();

                    if (! empty($result['news_id'])) {
                        return redirect(\App\Filament\Resources\News\NewsResource::getUrl('edit', ['record' => $result['news_id']]));
                    }
                }),

            DeleteAction::make(),
        ];
    }
}
