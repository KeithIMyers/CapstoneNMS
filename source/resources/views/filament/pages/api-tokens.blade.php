<x-filament-panels::page>
    @if ($newlyCreatedToken)
        <x-filament::section>
            <x-slot name="heading">Your new API token</x-slot>
            <x-slot name="description">
                Copy this token now. It will never be shown again — only its hash is stored.
            </x-slot>
            <div class="space-y-3">
                <pre class="text-xs bg-gray-100 dark:bg-gray-800 p-3 rounded break-all">{{ $newlyCreatedToken }}</pre>
                <div class="flex gap-2">
                    <x-filament::button
                        x-data
                        x-on:click="navigator.clipboard.writeText(@js($newlyCreatedToken)); $el.innerText='Copied!'"
                    >
                        Copy to clipboard
                    </x-filament::button>
                    <x-filament::button color="gray" wire:click="dismissToken">I've saved it</x-filament::button>
                </div>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">Create a new token</x-slot>
        <form wire:submit="create" class="space-y-4">
            {{ $this->form }}
            <x-filament::button type="submit">Generate token</x-filament::button>
        </form>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Your tokens</x-slot>
        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
