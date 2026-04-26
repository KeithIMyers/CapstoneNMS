<x-filament-panels::page>
    @php
        $days = $this->getCalendarData();
        $cursor = $this->getMonthCursor();
        $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $statusColors = [
            'draft'        => ['bg' => '#f3f4f6', 'fg' => '#374151'],
            'in_review'    => ['bg' => '#fef3c7', 'fg' => '#92400e'],
            'scheduled'    => ['bg' => '#dbeafe', 'fg' => '#1e3a8a'],
            'published'    => ['bg' => '#d1fae5', 'fg' => '#065f46'],
            'unpublished'  => ['bg' => '#fee2e2', 'fg' => '#991b1b'],
            'archived'     => ['bg' => '#e5e7eb', 'fg' => '#374151'],
        ];
    @endphp

    <x-filament::section>
        <x-slot name="heading">{{ $cursor->format('F Y') }}</x-slot>
        <x-slot name="headerEnd">
            <div style="display:flex; gap:0.5rem;">
                <a href="{{ $this->getPrevMonthUrl() }}" class="fi-btn fi-btn-size-sm fi-color-gray" style="padding:0.4rem 0.8rem; border:1px solid #d1d5db; border-radius:6px; text-decoration:none;">← Prev</a>
                <a href="{{ $this->getTodayUrl() }}" class="fi-btn fi-btn-size-sm" style="padding:0.4rem 0.8rem; border:1px solid #d1d5db; border-radius:6px; text-decoration:none;">Today</a>
                <a href="{{ $this->getNextMonthUrl() }}" class="fi-btn fi-btn-size-sm fi-color-gray" style="padding:0.4rem 0.8rem; border:1px solid #d1d5db; border-radius:6px; text-decoration:none;">Next →</a>
            </div>
        </x-slot>

        <div style="display:grid; grid-template-columns: repeat(7, 1fr); gap:2px; background:#e5e7eb; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden;">
            @foreach ($dayNames as $n)
                <div style="background:#f9fafb; padding:0.4rem; text-align:center; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.04em; color:#6b7280; font-weight:600;">{{ $n }}</div>
            @endforeach

            @foreach ($days as $key => $cell)
                @php
                    $isCurrentMonth = $cell['cursor']->month === $cursor->month;
                    $isToday = $cell['cursor']->isToday();
                @endphp
                <div style="background:{{ $isCurrentMonth ? '#ffffff' : '#fafafa' }}; min-height:120px; padding:0.4rem; font-size:0.8rem; position:relative;">
                    <div style="font-weight:{{ $isToday ? '700' : '500' }}; color:{{ $isCurrentMonth ? ($isToday ? '#2563eb' : '#111827') : '#9ca3af' }}; margin-bottom:0.35rem;">
                        {{ $cell['cursor']->format('j') }}
                    </div>
                    @foreach ($cell['articles'] as $a)
                        @php
                            $c = $statusColors[$a['status']] ?? ['bg' => '#f3f4f6', 'fg' => '#374151'];
                            $prefix = $a['kind'] === 'unpublish' ? '↓ ' : '↑ ';
                        @endphp
                        <a href="{{ \App\Filament\Resources\News\NewsResource::getUrl('edit', ['record' => $a['id']]) }}"
                           title="{{ $prefix.$a['title'].' · '.$a['time'].' ('.$a['status'].')' }}"
                           style="display:block; padding:0.2rem 0.35rem; margin-bottom:0.15rem; border-radius:4px; background:{{ $c['bg'] }}; color:{{ $c['fg'] }}; font-size:0.72rem; line-height:1.25; text-decoration:none; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                            <span style="opacity:0.75;">{{ $a['time'] }}</span>
                            {{ $prefix }}{{ \Illuminate\Support\Str::limit($a['title'], 28) }}
                        </a>
                    @endforeach
                </div>
            @endforeach
        </div>

        <div style="margin-top:1rem; display:flex; gap:1rem; flex-wrap:wrap; font-size:0.75rem; color:#6b7280;">
            @foreach ($statusColors as $status => $c)
                <span style="display:inline-flex; align-items:center; gap:0.4rem;">
                    <span style="display:inline-block; width:0.8rem; height:0.8rem; background:{{ $c['bg'] }}; border-radius:3px;"></span>
                    {{ str_replace('_', ' ', $status) }}
                </span>
            @endforeach
            <span style="margin-left:auto;">↑ publish · ↓ unpublish</span>
        </div>
    </x-filament::section>
</x-filament-panels::page>
