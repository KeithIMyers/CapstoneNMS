{{-- Livewire 3 requires every component view to have a single
     root HTML element. Wrap the whole block in <x-filament-widgets
     ::widget> unconditionally; render an empty div inside when
     there are no findings so the section disappears visually
     without breaking Livewire's render contract. --}}
<x-filament-widgets::widget>
    @if (! empty($findings))
        <x-filament::section>
            <x-slot name="heading">
                AI lint findings
                @if ($criticalCount > 0)
                    <span style="background:#fee2e2;color:#991b1b;padding:0.15rem 0.5rem;border-radius:999px;font-size:0.75rem;font-weight:600;margin-left:0.5rem;">{{ $criticalCount }} critical</span>
                @endif
                @if ($warnCount > 0)
                    <span style="background:#fef3c7;color:#92400e;padding:0.15rem 0.5rem;border-radius:999px;font-size:0.75rem;font-weight:600;margin-left:0.5rem;">{{ $warnCount }} warning{{ $warnCount === 1 ? '' : 's' }}</span>
                @endif
            </x-slot>
            <x-slot name="headerEnd">
                <button type="button"
                        wire:click="dismiss"
                        class="fi-btn fi-btn-size-sm"
                        style="padding:0.4rem 0.8rem;border:1px solid #d1d5db;border-radius:6px;background:transparent;cursor:pointer;font-size:0.85rem;">
                    Dismiss
                </button>
            </x-slot>

            <ul style="list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:0.65rem;">
                @foreach ($findings as $f)
                    @php
                        $sev = $f['severity'] ?? 'info';
                        $colors = match ($sev) {
                            'critical' => ['bg' => '#fee2e2', 'fg' => '#991b1b', 'border' => '#fca5a5'],
                            'warn'     => ['bg' => '#fef3c7', 'fg' => '#92400e', 'border' => '#fcd34d'],
                            default    => ['bg' => '#e0f2fe', 'fg' => '#075985', 'border' => '#7dd3fc'],
                        };
                    @endphp
                    <li style="border-left:3px solid {{ $colors['border'] }};background:{{ $colors['bg'] }};padding:0.65rem 0.85rem;border-radius:6px;">
                        <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.25rem;">
                            <span style="text-transform:uppercase;letter-spacing:0.04em;font-size:0.7rem;font-weight:700;color:{{ $colors['fg'] }};">{{ $sev }}</span>
                            <span style="text-transform:uppercase;letter-spacing:0.04em;font-size:0.7rem;color:{{ $colors['fg'] }};opacity:0.8;">· {{ $f['category'] ?? 'general' }}</span>
                        </div>
                        <div style="color:{{ $colors['fg'] }};font-size:0.92rem;line-height:1.4;">{{ $f['message'] ?? '' }}</div>
                        @if (! empty($f['fix']))
                            <div style="margin-top:0.35rem;color:{{ $colors['fg'] }};font-size:0.85rem;opacity:0.85;">
                                <strong>Fix:</strong> {{ $f['fix'] }}
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @else
        {{-- No active findings; render an empty placeholder so
             Livewire's render contract is satisfied without showing
             an empty section card to editors. --}}
        <div style="display:none;" data-widget="article-lint-findings-empty"></div>
    @endif
</x-filament-widgets::widget>
