<x-filament-panels::page>
    @php
        $status = $this->getStatus();
        $counts = $this->getCounts();
        $reason = session('license_block_reason');

        $stateBadge = match ($status['state']) {
            'active' => ['color' => 'success', 'label' => 'Active'],
            'grace'  => ['color' => 'warning', 'label' => 'In grace window'],
            'expired'=> ['color' => 'danger',  'label' => 'Expired'],
            'invalid'=> ['color' => 'danger',  'label' => 'Invalid signature'],
            default  => ['color' => 'danger',  'label' => 'Unlicensed'],
        };

        $kindBadge = match ($status['kind'] ?? null) {
            'production'  => ['color' => 'success', 'label' => 'Production'],
            'development' => ['color' => 'warning', 'label' => 'Development'],
            'trial'       => ['color' => 'info',    'label' => 'Trial'],
            default       => null,
        };

        $expiresAt = $status['expires_at']
            ? \Illuminate\Support\Carbon::parse($status['expires_at'])
            : null;
        $daysLeft = $expiresAt ? (int) round($expiresAt->diffInDays(now(), false) * -1) : null;
    @endphp

    @if ($reason)
        <div style="margin-bottom:1.5rem;padding:0.85rem 1rem;background:#fef2f2;border:1px solid #fca5a5;border-radius:0.5rem;color:#7f1d1d;">
            <strong>Admin panel locked:</strong> {{ $reason }}
        </div>
    @endif

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-bottom:2rem;">
        <div style="padding:1rem;border:1px solid var(--gray-200, #e5e7eb);border-radius:0.5rem;">
            <div style="font-size:0.8rem;color:var(--gray-500, #6b7280);">Status</div>
            <div style="margin-top:0.35rem;">
                <x-filament::badge :color="$stateBadge['color']">{{ $stateBadge['label'] }}</x-filament::badge>
                @if ($kindBadge)
                    <x-filament::badge :color="$kindBadge['color']">{{ $kindBadge['label'] }}</x-filament::badge>
                @endif
            </div>
        </div>

        <div style="padding:1rem;border:1px solid var(--gray-200, #e5e7eb);border-radius:0.5rem;">
            <div style="font-size:0.8rem;color:var(--gray-500, #6b7280);">Tier</div>
            <div style="margin-top:0.35rem;font-weight:700;">{{ $status['tier_label'] ?? '—' }}</div>
        </div>

        <div style="padding:1rem;border:1px solid var(--gray-200, #e5e7eb);border-radius:0.5rem;">
            <div style="font-size:0.8rem;color:var(--gray-500, #6b7280);">Customer</div>
            <div style="margin-top:0.35rem;font-weight:700;">{{ $status['customer'] ?: '—' }}</div>
        </div>

        <div style="padding:1rem;border:1px solid var(--gray-200, #e5e7eb);border-radius:0.5rem;">
            <div style="font-size:0.8rem;color:var(--gray-500, #6b7280);">Expires</div>
            <div style="margin-top:0.35rem;font-weight:700;">
                @if ($expiresAt)
                    {{ $expiresAt->toDateString() }}
                    <span style="font-weight:400;color:var(--gray-500, #6b7280);">
                        @if ($daysLeft !== null)
                            ({{ $daysLeft >= 0 ? $daysLeft.' day(s) left' : abs($daysLeft).' day(s) ago' }})
                        @endif
                    </span>
                @else
                    —
                @endif
            </div>
        </div>
    </div>

    @if (! empty($status['domains']))
        <div style="margin-bottom:1.5rem;">
            <div style="font-size:0.85rem;color:var(--gray-500, #6b7280);margin-bottom:0.35rem;">Licensed domains</div>
            <div>
                @foreach ($status['domains'] as $d)
                    <code style="background:var(--gray-100, #f3f4f6);padding:0.15rem 0.45rem;border-radius:0.25rem;margin-right:0.35rem;font-size:0.85rem;">{{ $d }}</code>
                @endforeach
            </div>
        </div>
    @endif

    <h3 style="margin-top:2rem;margin-bottom:0.5rem;font-size:1rem;font-weight:700;">Headcount usage</h3>
    <table style="width:100%;border-collapse:collapse;margin-bottom:2rem;">
        <thead>
            <tr style="border-bottom:1px solid var(--gray-200, #e5e7eb);text-align:left;">
                <th style="padding:0.5rem;font-size:0.85rem;color:var(--gray-500, #6b7280);">Role</th>
                <th style="padding:0.5rem;font-size:0.85rem;color:var(--gray-500, #6b7280);">In use</th>
                <th style="padding:0.5rem;font-size:0.85rem;color:var(--gray-500, #6b7280);">License cap</th>
            </tr>
        </thead>
        <tbody>
            @foreach (['admins' => 'Admins', 'editors' => 'Editors', 'authors' => 'Authors', 'agents' => 'Ghost agents'] as $key => $label)
                @php
                    $cap = $status['limits'][$key] ?? 0;
                    $used = $counts[$key] ?? 0;
                    $over = $cap >= 0 && $used > $cap;
                @endphp
                <tr style="border-bottom:1px solid var(--gray-100, #f3f4f6);">
                    <td style="padding:0.5rem;">{{ $label }}</td>
                    <td style="padding:0.5rem;{{ $over ? 'color:#b91c1c;font-weight:700;' : '' }}">{{ $used }}</td>
                    <td style="padding:0.5rem;">{{ $cap === -1 ? 'unlimited' : $cap }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h3 style="margin-bottom:0.5rem;font-size:1rem;font-weight:700;">Upload a license</h3>
    <p style="font-size:0.9rem;color:var(--gray-500, #6b7280);margin-bottom:1rem;">
        Drop the <code>.dat</code> file we emailed you, or paste the <code>.txt</code> blob into the field below. Both forms carry the same signed envelope.
    </p>

    <form wire:submit="upload">
        {{ $this->form }}

        <div style="margin-top:1rem;display:flex;gap:0.75rem;">
            <x-filament::button type="submit" color="primary">Save license</x-filament::button>
        </div>
    </form>

    <p style="margin-top:2rem;font-size:0.85rem;color:var(--gray-500, #6b7280);">
        Need a fresh license? Contact <a href="mailto:licensing@capstonenms.com">licensing@capstonenms.com</a>.
    </p>
</x-filament-panels::page>
