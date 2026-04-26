{{-- Revision history modal. Renders the Spatie activitylog entries for
     this article as a vertical timeline with per-property old → new
     diffs for the most-recently changed entries.

     Long-content fields (body, excerpt) get truncated to 600 chars
     each side so a single revision doesn't blow the modal.
     --}}
@php
    $truncate = function (?string $value, int $cap = 600) {
        $value = (string) $value;
        if (mb_strlen($value) <= $cap) return $value;
        return mb_substr($value, 0, $cap).'…';
    };
@endphp

<div style="display:flex;flex-direction:column;gap:0.85rem;">
    @forelse ($activities as $activity)
        @php
            $old = (array) data_get($activity, 'properties.old', []);
            $new = (array) data_get($activity, 'properties.attributes', []);
            $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
        @endphp
        <div style="border:1px solid #e5e7eb;border-radius:8px;padding:0.85rem 1rem;background:#ffffff;">
            <div style="display:flex;justify-content:space-between;align-items:baseline;font-size:0.82rem;color:#475569;margin-bottom:0.6rem;flex-wrap:wrap;gap:0.5rem;">
                <span>
                    <strong style="color:#0f172a;">{{ ucfirst($activity->description) }}</strong>
                    by {{ optional($activity->causer)->name ?? 'system' }}
                </span>
                <time datetime="{{ $activity->created_at->toAtomString() }}" title="{{ $activity->created_at->toDayDateTimeString() }}">
                    {{ $activity->created_at->diffForHumans() }}
                </time>
            </div>

            @if (empty($keys))
                <p style="margin:0;color:#94a3b8;font-size:0.85rem;">No tracked field changes on this entry.</p>
            @else
                <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
                    <thead>
                        <tr style="text-align:left;color:#64748b;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;">
                            <th style="padding:0.25rem 0.4rem;width:9rem;">Field</th>
                            <th style="padding:0.25rem 0.4rem;">Before</th>
                            <th style="padding:0.25rem 0.4rem;">After</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($keys as $k)
                            <tr style="vertical-align:top;border-top:1px solid #f1f5f9;">
                                <td style="padding:0.4rem;font-family:ui-monospace,Menlo,monospace;color:#0f172a;font-size:0.78rem;">{{ $k }}</td>
                                <td style="padding:0.4rem;background:#fef2f2;color:#7f1d1d;white-space:pre-wrap;font-size:0.82rem;line-height:1.45;">{{ $truncate($old[$k] ?? '') ?: '—' }}</td>
                                <td style="padding:0.4rem;background:#f0fdf4;color:#14532d;white-space:pre-wrap;font-size:0.82rem;line-height:1.45;">{{ $truncate($new[$k] ?? '') ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @empty
        <p style="color:#64748b;">No revision history yet — changes are recorded from the moment the article is first edited under activitylog.</p>
    @endforelse
</div>
