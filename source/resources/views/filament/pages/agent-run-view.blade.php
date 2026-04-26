<x-filament-panels::page>
    @php
        $run = $this->record;
        $transcript = is_array($run->transcript) ? $run->transcript : [];
        $statusColor = match ($run->status) {
            'done'    => '#065f46',
            'error'   => '#991b1b',
            'running' => '#92400e',
            default   => '#374151',
        };
        $statusBg = match ($run->status) {
            'done'    => '#d1fae5',
            'error'   => '#fee2e2',
            'running' => '#fef3c7',
            default   => '#e5e7eb',
        };
    @endphp

    <x-filament::section>
        <x-slot name="heading">
            Agent run #{{ $run->id }}
            <span style="background:{{ $statusBg }};color:{{ $statusColor }};padding:0.2rem 0.6rem;border-radius:999px;font-size:0.75rem;font-weight:700;margin-left:0.6rem;">
                {{ strtoupper($run->status) }}
            </span>
        </x-slot>
        <x-slot name="description">
            <strong>{{ $run->agent_key }}</strong>
            · started {{ $run->started_at?->format('M j, Y g:i:s a') }}
            @if ($run->user) · by {{ $run->user->name }} @endif
            · {{ $run->iterations }} step{{ $run->iterations === 1 ? '' : 's' }}
            @if ($run->duration_ms !== null)
                · {{ number_format($run->duration_ms) }} ms
            @endif
            · {{ number_format($run->tokens_in_total) }}/{{ number_format($run->tokens_out_total) }} tokens
            @if ($run->cost_microusd)
                · ${{ number_format($run->cost_microusd / 1_000_000, 4) }}
            @endif
        </x-slot>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Input</x-slot>
        <pre style="white-space:pre-wrap;background:#f9fafb;padding:0.75rem;border-radius:6px;font-family:ui-monospace,Menlo,Monaco,monospace;font-size:0.85rem;line-height:1.5;">{{ $run->input_message }}</pre>
    </x-filament::section>

    @if ($run->status === 'done' && $run->final_output)
        <x-filament::section>
            <x-slot name="heading">Final output</x-slot>
            <div style="white-space:pre-wrap;line-height:1.6;">{{ $run->final_output }}</div>
        </x-filament::section>
    @endif

    @if ($run->status === 'error' && $run->error_message)
        <x-filament::section>
            <x-slot name="heading">Error</x-slot>
            <div style="background:#fee2e2;color:#991b1b;padding:0.75rem;border-radius:6px;white-space:pre-wrap;">{{ $run->error_message }}</div>
        </x-filament::section>
    @endif

    @if (! empty($transcript))
        <x-filament::section>
            <x-slot name="heading">Transcript</x-slot>
            <div style="display:flex;flex-direction:column;gap:0.6rem;">
                @foreach ($transcript as $i => $msg)
                    @php
                        $role = $msg['role'] ?? 'unknown';
                        $content = $msg['content'] ?? '';
                        $isToolCall = ! empty($msg['tool_call']);
                        $colors = match ($role) {
                            'system'    => ['bg' => '#f3f4f6', 'fg' => '#374151', 'label' => 'SYSTEM'],
                            'user'      => $isToolCall
                                ? ['bg' => '#ecfeff', 'fg' => '#155e75', 'label' => 'OBSERVATION']
                                : ['bg' => '#eff6ff', 'fg' => '#1e3a8a', 'label' => 'USER'],
                            'assistant' => ['bg' => '#fef3c7', 'fg' => '#92400e', 'label' => 'ASSISTANT'],
                            default     => ['bg' => '#f9fafb', 'fg' => '#374151', 'label' => strtoupper($role)],
                        };
                    @endphp
                    <div style="border-left:3px solid {{ $colors['fg'] }};background:{{ $colors['bg'] }};padding:0.6rem 0.85rem;border-radius:6px;">
                        <div style="display:flex;justify-content:space-between;font-size:0.7rem;letter-spacing:0.06em;font-weight:700;color:{{ $colors['fg'] }};margin-bottom:0.4rem;">
                            <span>{{ $colors['label'] }}</span>
                            <span style="opacity:0.7;">step {{ $i + 1 }}</span>
                        </div>
                        @if ($isToolCall)
                            <div style="font-family:ui-monospace,Menlo,Monaco,monospace;font-size:0.78rem;color:{{ $colors['fg'] }};margin-bottom:0.5rem;">
                                <strong>tool:</strong> {{ $msg['tool_call']['name'] ?? '?' }}<br>
                                <strong>args:</strong> {{ json_encode($msg['tool_call']['arguments'] ?? [], JSON_UNESCAPED_SLASHES) }}
                            </div>
                        @endif
                        <pre style="white-space:pre-wrap;margin:0;font-family:inherit;font-size:0.85rem;line-height:1.5;color:#1f2937;">{{ $content }}</pre>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
