<?php

namespace App\Filament\Pages;

use App\Models\AgentRun;
use App\Models\Subscription;
use App\Services\Newsletter\DailyBriefSender;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * One-screen workflow for the daily newsletter:
 *   1. Click "Generate brief" — runs the editorial.daily_brief agent.
 *   2. Review the rendered preview.
 *   3. Click "Send to subscribers" — fans the same content out via mail.
 *
 * The Livewire-style state lives on the page so a successful generate
 * survives until either the editor sends or refreshes. Backed by the
 * AgentRun record so the audit trail captures everything.
 */
class DailyBrief extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static string|\UnitEnum|null $navigationGroup = 'AI';

    protected static ?string $title = 'Daily brief';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.daily-brief';

    /** Markdown body of the most recently generated brief, if any. */
    public ?string $body = null;

    /** AgentRun id for the current preview, if any. */
    public ?int $agentRunId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->isEditor() ?? false;
    }

    public function mount(): void
    {
        // Start with the most recent finished brief, if any, so refreshing
        // the page picks up where the editor left off.
        $latest = AgentRun::query()
            ->where('agent_key', DailyBriefSender::AGENT_KEY)
            ->where('status', AgentRun::STATUS_DONE)
            ->orderByDesc('id')
            ->first();
        if ($latest) {
            $this->body = $latest->final_output;
            $this->agentRunId = $latest->id;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label('Generate brief')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->action(function () {
                    $sender = app(DailyBriefSender::class);
                    $run = $sender->generate(auth()->id());

                    if ($run->status !== AgentRun::STATUS_DONE || empty($run->final_output)) {
                        Notification::make()
                            ->title('Brief generation failed')
                            ->body(\Illuminate\Support\Str::limit((string) $run->error_message, 600))
                            ->danger()
                            ->send();
                        return;
                    }

                    $this->body = $run->final_output;
                    $this->agentRunId = $run->id;

                    Notification::make()
                        ->title('Brief generated')
                        ->body($run->iterations.' steps · '.number_format($run->tokens_in_total + $run->tokens_out_total).' tokens')
                        ->success()
                        ->send();
                }),

            Action::make('send')
                ->label('Send to subscribers')
                ->icon('heroicon-o-paper-airplane')
                ->color('danger')
                ->visible(fn () => ! empty($this->body))
                ->requiresConfirmation()
                ->modalHeading('Send the daily brief to all confirmed subscribers?')
                ->modalDescription(function () {
                    $count = Subscription::active()->count();
                    return "Will send to {$count} confirmed subscribers. This cannot be undone.";
                })
                ->action(function () {
                    if (empty($this->body)) {
                        Notification::make()->title('Nothing to send')->warning()->send();
                        return;
                    }
                    $result = app(DailyBriefSender::class)->send($this->body);

                    Notification::make()
                        ->title("Sent {$result['sent']}, failed {$result['failed']}")
                        ->color($result['failed'] > 0 ? 'warning' : 'success')
                        ->persistent()
                        ->send();
                }),

            Action::make('viewRun')
                ->label('Open run record')
                ->icon('heroicon-o-clipboard-document-list')
                ->color('gray')
                ->visible(fn () => $this->agentRunId !== null)
                ->url(fn () => $this->agentRunId
                    ? \App\Filament\Resources\AgentRuns\AgentRunResource::getUrl('view', ['record' => $this->agentRunId])
                    : null),
        ];
    }
}
