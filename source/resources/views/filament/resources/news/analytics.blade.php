<x-filament-panels::page>
    @php
        $a       = $this->stats;
        $article = $a['article'];

        // SVG line chart helper for the daily-views series.
        // Dimensions are intentionally generous so the chart reads
        // even on retina without a JS lib.
        $w = 760; $h = 200; $pad = 28;
        $values = array_values($a['daily_views']);
        $labels = array_keys($a['daily_views']);
        $max = max(1, ($values ? max($values) : 1));
        $n = max(1, count($values));
        $stepX = $n > 1 ? (($w - 2 * $pad) / ($n - 1)) : 0;

        $points = [];
        foreach ($values as $i => $v) {
            $x = round($pad + $i * $stepX, 1);
            $y = round($h - $pad - (($v / $max) * ($h - 2 * $pad)), 1);
            $points[] = "$x,$y";
        }
        $path = $points ? ('M '.implode(' L ', $points)) : '';

        $countryNames = function ($code) {
            if (class_exists(\Locale::class)) {
                return \Locale::getDisplayRegion('-'.$code, 'en') ?: $code;
            }
            return $code;
        };
    @endphp

    {{-- Top stats strip --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(11rem,1fr));gap:1rem;">
        @foreach ([
            ['Total views (all time)', number_format($a['total_views']),               'success'],
            ['Views (last '.$a['days_window'].'d)', number_format($a['views_window']), 'gray'],
            ['Avg scroll depth', $a['avg_depth'] !== null ? $a['avg_depth'].'%' : '—', $a['avg_depth'] >= 60 ? 'success' : 'warning'],
            ['Reading time', $a['reading_time'] ? $a['reading_time'].' min' : '—',     'gray'],
            ['Approved comments', number_format($a['comments']['approved'] ?? 0),      'success'],
            ['Pending comments', number_format($a['comments']['pending']  ?? 0),       ($a['comments']['pending'] ?? 0) > 0 ? 'warning' : 'gray'],
        ] as [$lbl, $val, $color])
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:0.85rem 1rem;">
                <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:#64748b;font-weight:600;">{{ $lbl }}</div>
                <div style="font-size:1.5rem;font-weight:700;color:#0f172a;margin-top:0.15rem;">{{ $val }}</div>
            </div>
        @endforeach
    </div>

    {{-- Daily-views chart --}}
    <x-filament::section>
        <x-slot name="heading">Daily views (last {{ $a['days_window'] }} days)</x-slot>
        @if (array_sum($values) === 0)
            <p style="color:#64748b;">No tracked views in this window yet.</p>
        @else
            <svg viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="none"
                 style="width:100%;height:auto;background:#f8fafc;border-radius:6px;">
                {{-- Y-axis grid --}}
                @for ($i = 0; $i <= 4; $i++)
                    @php $y = round($pad + $i * (($h - 2*$pad) / 4)); @endphp
                    <line x1="{{ $pad }}" y1="{{ $y }}" x2="{{ $w - $pad }}" y2="{{ $y }}"
                          stroke="#e2e8f0" stroke-width="1"/>
                @endfor
                {{-- Area fill --}}
                @if ($path)
                    <path d="{{ $path }} L {{ round($pad + ($n - 1) * $stepX, 1) }},{{ $h - $pad }} L {{ $pad }},{{ $h - $pad }} Z"
                          fill="rgba(14,165,233,0.18)" stroke="none"/>
                    <path d="{{ $path }}" fill="none" stroke="#0ea5e9" stroke-width="2"/>
                @endif
                {{-- Endpoint dots --}}
                @foreach ($values as $i => $v)
                    @if ($v > 0)
                        @php
                            $x = round($pad + $i * $stepX, 1);
                            $y = round($h - $pad - (($v / $max) * ($h - 2 * $pad)), 1);
                        @endphp
                        <circle cx="{{ $x }}" cy="{{ $y }}" r="2" fill="#0ea5e9">
                            <title>{{ $labels[$i] }}: {{ $v }} views</title>
                        </circle>
                    @endif
                @endforeach
                {{-- X-axis labels: first / middle / last --}}
                <text x="{{ $pad }}" y="{{ $h - 6 }}" font-size="10" fill="#94a3b8" text-anchor="start">{{ $labels[0] }}</text>
                <text x="{{ ($w - $pad) }}" y="{{ $h - 6 }}" font-size="10" fill="#94a3b8" text-anchor="end">{{ $labels[$n - 1] }}</text>
                <text x="{{ $pad }}" y="{{ $pad - 6 }}" font-size="10" fill="#94a3b8">peak {{ $max }}</text>
            </svg>
        @endif
    </x-filament::section>

    {{-- Two-column row: countries + reactions --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(20rem,1fr));gap:1rem;">

        <x-filament::section>
            <x-slot name="heading">Top countries (last {{ $a['days_window'] }} days)</x-slot>
            @if (empty($a['by_country']))
                <p style="color:#64748b;">No geo-resolved reads yet — install GeoLite2 to fill this in.</p>
            @else
                @php $maxCountry = max($a['by_country']); @endphp
                <div style="display:flex;flex-direction:column;gap:0.5rem;">
                    @foreach (array_slice($a['by_country'], 0, 10, true) as $code => $count)
                        <div>
                            <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:0.2rem;">
                                <span>{{ $countryNames($code) }} <span style="color:#94a3b8;">({{ $code }})</span></span>
                                <span style="color:#475569;">{{ number_format($count) }}</span>
                            </div>
                            <div style="background:#e2e8f0;height:6px;border-radius:3px;overflow:hidden;">
                                <div style="background:#0ea5e9;height:100%;width:{{ round($count * 100 / max(1, $maxCountry), 1) }}%;"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Reactions</x-slot>
            @if (empty($a['reactions']))
                <p style="color:#64748b;">No reactions yet.</p>
            @else
                @php $totalReactions = array_sum($a['reactions']); @endphp
                <div style="display:flex;flex-direction:column;gap:0.5rem;">
                    @foreach ($a['reactions'] as $type => $n)
                        <div style="display:flex;justify-content:space-between;align-items:center;font-size:0.95rem;padding:0.35rem 0;border-bottom:1px solid #f1f5f9;">
                            <span style="text-transform:capitalize;">{{ $type }}</span>
                            <span><strong>{{ number_format($n) }}</strong> <span style="color:#94a3b8;font-size:0.85em;">({{ round($n * 100 / max(1, $totalReactions)) }}%)</span></span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    </div>

    {{-- A/B headline performance --}}
    @if (! $a['headlines']->isEmpty())
        <x-filament::section>
            <x-slot name="heading">Headline variants (Phase F)</x-slot>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.9rem;">
                    <thead>
                        <tr style="text-align:left;color:#475569;font-size:0.75rem;letter-spacing:0.04em;text-transform:uppercase;">
                            <th style="padding:0.4rem 0.5rem;">Variant</th>
                            <th style="padding:0.4rem 0.5rem;width:6rem;text-align:right;">Imp.</th>
                            <th style="padding:0.4rem 0.5rem;width:6rem;text-align:right;">Clicks</th>
                            <th style="padding:0.4rem 0.5rem;width:5rem;text-align:right;">CTR</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($a['headlines'] as $h)
                            @php
                                $ctr = $h->impressions > 0 ? round(($h->clicks / $h->impressions) * 100, 2) : null;
                            @endphp
                            <tr style="border-top:1px solid #e2e8f0;">
                                <td style="padding:0.55rem 0.5rem;">
                                    {{ $h->variant }}
                                    @if ($h->is_default)
                                        <span style="background:#fef3c7;color:#92400e;font-size:0.7rem;padding:0.1rem 0.4rem;border-radius:999px;font-weight:700;margin-left:0.4rem;">Default</span>
                                    @endif
                                </td>
                                <td style="padding:0.55rem 0.5rem;text-align:right;font-variant-numeric:tabular-nums;">{{ number_format($h->impressions) }}</td>
                                <td style="padding:0.55rem 0.5rem;text-align:right;font-variant-numeric:tabular-nums;">{{ number_format($h->clicks) }}</td>
                                <td style="padding:0.55rem 0.5rem;text-align:right;font-variant-numeric:tabular-nums;">
                                    {{ $ctr !== null ? $ctr.'%' : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
