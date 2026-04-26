<x-filament-panels::page>
    @if ($this->getEnrolled())
        <x-filament::section>
            <x-slot name="heading">Two-factor authentication is enabled</x-slot>
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Your account is protected by TOTP. You'll be asked for a code every time you sign in from a new session.
            </p>
        </x-filament::section>

        @if (count($recoveryCodes))
            <x-filament::section>
                <x-slot name="heading">Recovery codes</x-slot>
                <x-slot name="description">
                    Save these somewhere safe. Each can be used once if you lose access to your authenticator.
                </x-slot>
                <pre class="text-sm bg-gray-50 dark:bg-gray-900 p-4 rounded-md">{{ implode("\n", $recoveryCodes) }}</pre>
            </x-filament::section>
        @endif
    @else
        <x-filament::section>
            <x-slot name="heading">Scan this QR code with your authenticator app</x-slot>
            <x-slot name="description">
                Use Google Authenticator, 1Password, Authy, or any TOTP-compatible app.
            </x-slot>
            <div class="flex flex-col md:flex-row gap-6">
                <div class="shrink-0">
                    {!! $qrSvg !!}
                </div>
                <div class="space-y-2 text-sm">
                    <p>Can't scan? Enter this secret manually:</p>
                    <code class="block p-2 bg-gray-100 dark:bg-gray-800 rounded break-all">{{ $pendingSecret }}</code>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Confirm enrollment</x-slot>
            <form wire:submit="confirm">
                {{ $this->form }}
                <div class="mt-4">
                    <x-filament::button type="submit">Enable 2FA</x-filament::button>
                </div>
            </form>
        </x-filament::section>
    @endif
</x-filament-panels::page>
