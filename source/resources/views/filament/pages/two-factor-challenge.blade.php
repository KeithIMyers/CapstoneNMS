<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Enter your verification code</x-slot>
        <x-slot name="description">
            Open your authenticator app and enter the current 6-digit code. If you've lost access, enter a recovery code.
        </x-slot>

        <form wire:submit="verify">
            {{ $this->form }}
            <div class="mt-4 flex gap-2">
                <x-filament::button type="submit">Verify</x-filament::button>
                <x-filament::link href="/admin/logout">Sign out</x-filament::link>
            </div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
