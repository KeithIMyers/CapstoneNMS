<?php

namespace App\Filament\Pages;

use BackedEnum;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorSetup extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Account';

    protected static ?string $navigationLabel = 'Two-factor auth';

    protected static ?string $title = 'Two-factor authentication';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.two-factor-setup';

    public ?array $data = ['code' => '', 'password' => ''];

    public ?string $pendingSecret = null;

    public ?string $qrSvg = null;

    /**
     * Plain-text recovery codes shown once on enrollment. Never re-derived
     * from storage — the DB only keeps hashes.
     */
    public array $recoveryCodes = [];

    public function mount(): void
    {
        $user = auth()->user();

        if (! $user->hasTwoFactorEnabled()) {
            $this->startEnrollment();
        }

        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            TextInput::make('code')
                ->label('6-digit code from your authenticator app')
                ->length(6)
                ->numeric()
                ->autofocus()
                ->required(),
            TextInput::make('password')
                ->label('Confirm with your account password')
                ->password()
                ->revealable()
                ->required()
                ->helperText('Required to bind a new authenticator to your account.'),
        ]);
    }

    private function startEnrollment(): void
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        session(['two_factor.pending_secret' => $secret]);

        $issuer = config('app.name') ?: 'CapstoneNMS';
        $label = auth()->user()->email;
        $otpauth = $google2fa->getQRCodeUrl($issuer, $label, $secret);

        $renderer = new ImageRenderer(
            new RendererStyle(220, 1),
            new SvgImageBackEnd()
        );

        $this->pendingSecret = $secret;
        $this->qrSvg = (new Writer($renderer))->writeString($otpauth);
    }

    public function confirm(): void
    {
        $secret = session('two_factor.pending_secret');
        if (! $secret) {
            return;
        }

        $state = $this->form->getState();
        $code = trim((string) ($state['code'] ?? ''));
        $password = (string) ($state['password'] ?? '');

        $user = auth()->user();

        if (! Hash::check($password, $user->password)) {
            Notification::make()
                ->title('Password incorrect')
                ->body('Re-enter your account password to bind this authenticator.')
                ->danger()
                ->send();
            return;
        }

        $google2fa = new Google2FA();
        if (! $google2fa->verifyKey($secret, $code, 1)) {
            Notification::make()
                ->title('Invalid code')
                ->body('That code does not match. Try again.')
                ->danger()
                ->send();
            return;
        }

        // Generate plaintext recovery codes to show the user once, store
        // only their hashes. Each is single-use.
        $plain = collect(range(1, 8))->map(fn () => Str::upper(Str::random(10)))->all();
        $hashed = array_map(fn ($code) => Hash::make($code), $plain);

        $user->two_factor_secret = $secret;
        $user->two_factor_recovery_codes = json_encode($hashed);
        $user->two_factor_confirmed_at = now();
        $user->two_factor_last_used_ts = floor(time() / 30);
        $user->save();

        session()->forget('two_factor.pending_secret');
        session(['two_factor.passed_at' => now()]);

        $this->recoveryCodes = $plain;
        $this->pendingSecret = null;
        $this->qrSvg = null;

        // Reset the form so the password isn't sitting in component state
        // after a successful enrollment.
        $this->data = ['code' => '', 'password' => ''];
        $this->form->fill();

        Notification::make()
            ->title('Two-factor authentication enabled')
            ->body('Save your recovery codes in a safe place — they will not be shown again.')
            ->success()
            ->send();
    }

    /**
     * Disable 2FA. Requires a fresh TOTP code and the account password
     * passed via the action's modal form so a hijacked session cannot
     * silently strip protection in one click.
     */
    public function disable(array $data): void
    {
        $user = auth()->user();
        $code = trim((string) ($data['code'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if (! Hash::check($password, $user->password)) {
            Notification::make()->title('Password incorrect')->danger()->send();
            return;
        }

        $google2fa = new Google2FA();
        if (! $user->two_factor_secret || ! $google2fa->verifyKey($user->two_factor_secret, $code, 1)) {
            Notification::make()->title('Invalid 2FA code')->danger()->send();
            return;
        }

        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->two_factor_last_used_ts = null;
        $user->save();

        // Re-arm enrollment so the page UI keeps working.
        $this->startEnrollment();
        $this->recoveryCodes = [];

        Notification::make()
            ->title('Two-factor authentication disabled')
            ->warning()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        $actions = [];

        if (auth()->user()->hasTwoFactorEnabled()) {
            $actions[] = Action::make('disable')
                ->label('Disable 2FA')
                ->color('danger')
                ->modalHeading('Disable two-factor authentication?')
                ->modalDescription('Confirm with your password and a current 2FA code.')
                ->schema([
                    TextInput::make('code')
                        ->label('Current 6-digit code')
                        ->length(6)
                        ->numeric()
                        ->required(),
                    TextInput::make('password')
                        ->label('Account password')
                        ->password()
                        ->revealable()
                        ->required(),
                ])
                ->action(fn (array $data) => $this->disable($data));
        }

        return $actions;
    }

    public function getEnrolled(): bool
    {
        return auth()->user()->hasTwoFactorEnabled();
    }
}
