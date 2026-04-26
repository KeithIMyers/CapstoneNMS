<?php

namespace App\Filament\Pages;

use App\Services\Licensing\LicenseService;
use App\Services\Update\UpdateService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

/**
 * Admin Updates page.
 *
 *   Status        current version, latest available, last applied
 *   Check         pulls https://update.capstonenms.com/manifest.json
 *                 (signed) and surfaces the diff
 *   One-click     downloads + verifies + applies the latest release
 *   Air-gapped    upload a zip + .sig pair manually
 *
 * Gated on isUpdateAllowed() — past expiry, the buttons disable and
 * a renewal CTA shows instead. The page itself stays accessible so
 * the customer can see they're behind on updates.
 */
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
                ->maxSize(204800), // 200 MB ceiling per release
            FileUpload::make('sig')
                ->label('Detached signature (.sig)')
                ->disk('local')
                ->directory('updates/uploads')
                ->visibility('private')
                ->acceptedFileTypes(['text/plain', 'application/octet-stream'])
                ->maxSize(8),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $allowed = app(LicenseService::class)->isUpdateAllowed();
        return [
            Action::make('check')
                ->label('Check for updates')
                ->color('gray')
                ->action('checkForUpdates'),
            Action::make('applyManifest')
                ->label('Download + install latest')
                ->color('primary')
                ->visible(fn () => $allowed && $this->manifestEntry !== null)
                ->action('applyManifest'),
            Action::make('applyUpload')
                ->label('Apply uploaded files')
                ->color('primary')
                ->visible(fn () => $allowed)
                ->action('applyUpload'),
        ];
    }

    public function checkForUpdates(): void
    {
        $svc = app(UpdateService::class);
        $entry = $svc->availableUpdate();
        if ($entry === null) {
            Notification::make()->title('You\'re up to date')->success()->send();
            return;
        }
        $this->manifestEntry = $entry;
        Notification::make()
            ->title('Update available: ' . ($entry['version'] ?? '?'))
            ->body((string) ($entry['notes'] ?? ''))
            ->success()
            ->send();
    }

    public function applyManifest(): void
    {
        if ($this->manifestEntry === null) {
            $this->checkForUpdates();
            if ($this->manifestEntry === null) return;
        }
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

        $svc = app(UpdateService::class);
        $result = $svc->applyUploaded($zipAbs, $sigAbs);
        // Clean up the uploads regardless.
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
        ];
    }
}
