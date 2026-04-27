<?php

namespace App\Filament\Pages;

use App\Services\Licensing\LicenseService;
use App\Services\Update\ProgressTracker;
use App\Services\Update\UpdateService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

class Updates extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Updates';

    protected static ?string $title = 'CapstoneNMS updates';

    protected static ?int $navigationSort = 96;

    protected static ?string $slug = 'updates';

    protected string $view = 'filament.pages.updates';

    public ?array $data = ['zip' => null, 'sig' => null];

    public ?array $manifestEntry = null;
    public ?string $manifestError = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public static function canAccess(): bool
    {
        $u = auth()->user();
        return $u && method_exists($u, 'isAdmin') && $u->isAdmin();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            FileUpload::make('zip')
                ->label('Update zip (.zip)')
                ->disk('local')
                ->directory('updates/uploads')
                ->visibility('private')
                ->acceptedFileTypes(['application/zip', 'application/octet-stream'])
                ->maxSize(204800),
            FileUpload::make('sig')
                ->label('Detached signature (.sig)')
                ->disk('local')
                ->directory('updates/uploads')
                ->visibility('private')
                ->acceptedFileTypes(['text/plain', 'application/octet-stream'])
                ->maxSize(8),
        ]);
    }

    /**
     * Header actions use closures rather than the string-method
     * binding form. Filament 4 page header actions don't always
     * resolve `->action('methodName')` reliably; closures hit the
     * Livewire invoke path directly.
     */
    protected function getHeaderActions(): array
    {
        $allowed = app(LicenseService::class)->isUpdateAllowed();
        return [
            Action::make('check')
                ->label('Check for updates')
                ->color('gray')
                ->action(fn () => $this->checkForUpdates()),
            Action::make('applyManifest')
                ->label('Download + install latest')
                ->color('primary')
                // Show only when the manifest's latest version is
                // strictly newer than what's installed. The
                // manifestEntry property is now populated by
                // checkForUpdates() regardless of version (so the
                // status card shows "Latest available"), so we have
                // to gate the action's visibility separately.
                ->visible(fn () => $allowed
                    && $this->manifestEntry !== null
                    && version_compare(
                        (string) ($this->manifestEntry['version'] ?? '0.0.0'),
                        app(UpdateService::class)->currentVersion(),
                        '>',
                    ))
                ->action(fn () => $this->applyManifest()),
            // The air-gapped "Apply uploaded files" button lives
            // inside the Air-gapped install section in the page body,
            // not in the header — keeps the form + its submit
            // button visually together.
        ];
    }

    public function checkForUpdates(): void
    {
        $svc = app(UpdateService::class);

        // Always populate the latest-known release from the manifest
        // (regardless of whether it's newer than us) so the "Latest
        // available" card shows the published version. Only the
        // Download + install button gates on availableUpdate() — that
        // returns null when manifest.latest <= currentVersion.
        $latestEntry = $svc->latestEntry();
        if ($latestEntry !== null) {
            $this->manifestEntry = $latestEntry;
        }

        $newer = $svc->availableUpdate();
        if ($newer === null) {
            Notification::make()
                ->title('You\'re up to date')
                ->body('Running the latest published version (' . $svc->currentVersion() . ').')
                ->success()
                ->send();
            return;
        }

        Notification::make()
            ->title('Update available: ' . ($newer['version'] ?? '?'))
            ->body((string) ($newer['notes'] ?? ''))
            ->success()
            ->send();
    }

    public function applyManifest(): void
    {
        if ($this->manifestEntry === null) {
            $this->checkForUpdates();
            if ($this->manifestEntry === null) return;
        }

        // Seed progress + dispatch a browser event so the in-page
        // poller starts showing live state immediately. The poller
        // and this action run in different PHP-FPM workers, so
        // download / verify / extract / migrate steps light up
        // while this request is still blocked on the long apply.
        app(ProgressTracker::class)->reset('Starting update…');
        $this->dispatch('updater-started');

        $svc = app(UpdateService::class);
        $result = $svc->applyDownload($this->manifestEntry);
        $this->surfaceResult($result);
    }

    public function applyUpload(): void
    {
        $state = $this->form->getState();
        $zipPath = is_array($state['zip']) ? array_values($state['zip'])[0] : ($state['zip'] ?? null);
        $sigPath = is_array($state['sig']) ? array_values($state['sig'])[0] : ($state['sig'] ?? null);
        if (! $zipPath || ! $sigPath) {
            Notification::make()->title('Both zip and signature files are required')->danger()->send();
            return;
        }
        $disk = Storage::disk('local');
        $zipAbs = $disk->path($zipPath);
        $sigAbs = $disk->path($sigPath);

        app(ProgressTracker::class)->reset('Starting update from uploaded zip…');
        $this->dispatch('updater-started');

        $svc = app(UpdateService::class);
        $result = $svc->applyUploaded($zipAbs, $sigAbs);
        try { $disk->delete($zipPath); $disk->delete($sigPath); } catch (\Throwable $e) {}
        $this->surfaceResult($result);
    }

    private function surfaceResult(array $result): void
    {
        if (! empty($result['ok'])) {
            $msg = 'Update applied.';
            if (! empty($result['version'])) $msg .= ' Now running ' . $result['version'] . '.';
            if (! empty($result['backup']))  $msg .= ' Pre-update snapshot: ' . $result['backup'];
            Notification::make()->title('Updated')->body($msg)->success()->persistent()->send();
            $this->form->fill();
            $this->manifestEntry = null;
            // Hard reload so the new view cache + asset URLs come
            // from the just-applied version.
            $this->redirect('/admin/updates');
            return;
        }
        Notification::make()
            ->title('Update failed')
            ->body(implode("\n", $result['errors'] ?? ['Unknown error']))
            ->danger()
            ->persistent()
            ->send();
    }

    public function getStatus(): array
    {
        $svc = app(UpdateService::class);
        return [
            'current'  => $svc->currentVersion(),
            'manifest' => $this->manifestEntry,
            'allowed'  => app(LicenseService::class)->isUpdateAllowed(),
            'license'  => app(LicenseService::class)->status(),
            'progress' => app(ProgressTracker::class)->read(),
        ];
    }
}
