<?php

namespace App\Filament\Pages;

use App\Services\Licensing\LicenseService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

/**
 * Admin License surface.
 *
 *   GET  /admin/license       view current license status, headcount
 *                             usage vs caps, expiry / grace banner,
 *                             upload-or-paste form
 *   POST (Filament action)    Upload form: writes the envelope to
 *                             storage/app/private/licensing/license.dat
 *                             and re-runs the verifier. On success
 *                             the page reloads with the new state.
 *
 * The upload form accepts either:
 *   - a `.dat` file (the keygen `mint` --output produces this)
 *   - a paste-blob (the same envelope dropped into a textarea — the
 *     keygen also outputs this as `.txt`)
 *
 * The page is permitted through EnforceLicense so an admin whose
 * license expired can upload a new one without being locked out.
 */
class License extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'License';

    protected static ?string $title = 'CapstoneNMS license';

    protected static ?int $navigationSort = 95;

    protected static ?string $slug = 'license';

    protected string $view = 'filament.pages.license';

    public ?array $data = ['file' => null, 'paste' => ''];

    public function mount(): void
    {
        $this->form->fill();
    }

    /**
     * Only admins manage the license. Editors / authors don't need
     * to see this page; ghost agents can't reach Filament at all.
     */
    public static function canAccess(): bool
    {
        $u = auth()->user();
        return $u && method_exists($u, 'isAdmin') && $u->isAdmin();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            FileUpload::make('file')
                ->label('License file (.dat)')
                ->disk('local')
                ->directory('licensing/uploads')
                ->visibility('private')
                ->acceptedFileTypes(['text/plain', 'application/octet-stream'])
                ->maxSize(64), // 64 KB ceiling — license envelopes are tiny
            Textarea::make('paste')
                ->label('…or paste the license blob (.txt)')
                ->rows(4)
                ->maxLength(65_536)
                ->placeholder('eyJ...payload....sig'),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('upload')
                ->label('Save license')
                ->color('primary')
                ->action('upload'),
        ];
    }

    public function upload(): void
    {
        $state = $this->form->getState();
        $envelope = '';

        if (! empty($state['file'])) {
            // Filament returns the stored relative path on the disk.
            $path = is_array($state['file']) ? array_values($state['file'])[0] : $state['file'];
            try {
                $envelope = (string) Storage::disk('local')->get($path);
            } catch (\Throwable $e) {
                $envelope = '';
            }
            // Clean up the temp upload regardless of outcome.
            try { Storage::disk('local')->delete($path); } catch (\Throwable $e) {}
        }

        if ($envelope === '' && ! empty($state['paste'])) {
            $envelope = trim((string) $state['paste']);
        }

        if ($envelope === '') {
            Notification::make()->title('No license provided')->danger()->send();
            return;
        }

        // Write to the canonical path then flush the cache + re-read
        // so the page renders the new status without a hard refresh.
        Storage::disk('local')->put(LicenseService::STORAGE_PATH, $envelope);
        $svc = app(LicenseService::class);
        $svc->flush();
        $status = $svc->status();

        if (in_array($status['state'], ['active', 'grace'], true)) {
            $this->form->fill();
            Notification::make()
                ->title('License verified')
                ->body(sprintf(
                    '%s · %s · expires %s',
                    $status['tier_label'] ?? 'Custom',
                    $status['kind'] ?? 'production',
                    $status['expires_at']
                        ? \Illuminate\Support\Carbon::parse($status['expires_at'])->toDateString()
                        : 'never',
                ))
                ->success()
                ->send();
        } else {
            // Wipe the bad envelope so the gate re-triggers on the next
            // request — leaving a junk file in place would silently
            // keep the panel locked.
            Storage::disk('local')->delete(LicenseService::STORAGE_PATH);
            $svc->flush();
            Notification::make()
                ->title('License rejected')
                ->body($status['reason'] ?? 'Signature did not verify against the embedded product key.')
                ->danger()
                ->persistent()
                ->send();
        }
    }

    /** Surface the current status for the Blade view. */
    public function getStatus(): array
    {
        return app(LicenseService::class)->status();
    }

    /** Live headcount counts by bucket so the view can render N/M. */
    public function getCounts(): array
    {
        $userClass = \App\Models\User::class;
        $admins = $userClass::query()
            ->whereIn('role', ['admin', 'sub_admin'])
            ->where(fn ($q) => $q->where('is_agent', false)->orWhereNull('is_agent'))
            ->count();
        $editors = $userClass::query()
            ->where('role', 'editor')
            ->where(fn ($q) => $q->where('is_agent', false)->orWhereNull('is_agent'))
            ->count();
        $authors = $userClass::query()
            ->where('role', 'author')
            ->where(fn ($q) => $q->where('is_agent', false)->orWhereNull('is_agent'))
            ->count();
        $agents = $userClass::query()->where('is_agent', true)->count();

        return compact('admins', 'editors', 'authors', 'agents');
    }
}
