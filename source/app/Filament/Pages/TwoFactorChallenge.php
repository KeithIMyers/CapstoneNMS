<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorChallenge extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'two-factor-challenge';

    protected static ?string $title = 'Two-factor challenge';

    protected string $view = 'filament.pages.two-factor-challenge';

    public ?array $data = ['code' => ''];

    public function mount(): void
    {
        $user = Auth::user();

        if (! $user) {
            $this->redirect('/admin/login');
            return;
        }

        if (! $user->hasTwoFactorEnabled() || session('two_factor.passed_at')) {
            $this->redirect('/admin');
            return;
        }

        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            TextInput::make('code')
                ->label('6-digit code (or recovery code)')
                ->autofocus()
                ->required()
                ->maxLength(20),
        ]);
    }

    public function verify(): void
    {
        $user = Auth::user();
        $code = trim((string) ($this->form->getState()['code'] ?? ''));

        $google2fa = new Google2FA();

        if ($user->two_factor_secret) {
            // Window 1 = ±30s. We also persist the last-accepted timestamp
            // so the same code cannot be replayed inside its 30s window.
            $lastTs = (int) ($user->two_factor_last_used_ts ?? 0);
            $newTs = $google2fa->verifyKeyNewer($user->two_factor_secret, $code, $lastTs, 1);

            if ($newTs !== false) {
                $user->two_factor_last_used_ts = is_int($newTs) ? $newTs : floor(time() / 30);
                $user->save();

                session(['two_factor.passed_at' => now()]);
                $this->redirect('/admin');
                return;
            }
        }

        // Recovery-code path. Codes are stored as bcrypt hashes; on a match,
        // remove that entry so the code can never be reused.
        if ($user->two_factor_recovery_codes) {
            $hashes = json_decode($user->two_factor_recovery_codes, true) ?: [];
            $matched = false;

            foreach ($hashes as $i => $hash) {
                if (is_string($hash) && Hash::check($code, $hash)) {
                    unset($hashes[$i]);
                    $matched = true;
                    break;
                }
            }

            if ($matched) {
                $user->two_factor_recovery_codes = json_encode(array_values($hashes));
                $user->save();
                session(['two_factor.passed_at' => now()]);
                $this->redirect('/admin');
                return;
            }
        }

        Notification::make()
            ->title('Invalid code')
            ->danger()
            ->send();
    }
}
