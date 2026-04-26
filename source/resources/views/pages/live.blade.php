@extends('layouts.site')

@section('head_title', $live->title.' · '.getcong('site_name'))
@section('head_description', $live->summary ? \Illuminate\Support\Str::limit(strip_tags($live->summary), 160) : 'Live coverage of '.$live->title)

@push('scripts')
@if ($live->status === 'active')
    {{-- Real-time-ish reader updates: page-side fetch every 20s
         pulls entries newer than the latest one currently rendered
         and prepends them to the timeline. Page still works without
         JS; the bot-friendly fallback below renders the static
         server-side list. --}}
    <script nonce="{{ csp_nonce() }}">
        (() => {
            const slug = @json($live->slug);
            const list = document.getElementById('live-entries');
            if (!list) return;
            let lastTs = parseInt(list.dataset.latestTs || '0', 10) || 0;

            async function poll() {
                try {
                    const r = await fetch(`/live/${encodeURIComponent(slug)}/entries.json?since=${lastTs}`,
                        { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                    if (!r.ok) return;
                    const data = await r.json();
                    if (!data.entries || !data.entries.length) return;
                    // API returns newest-first; reverse so prepend
                    // order results in newest at the top.
                    let newCount = 0;
                    let firstHeadline = '';
                    data.entries.slice().reverse().forEach((e) => {
                        if (!e.posted_ts || e.posted_ts <= lastTs) return;
                        const html = `
                            <article class="live-new-entry" tabindex="-1" style="border-left: 2px solid var(--c-border); padding: 1rem 0 1rem 1.25rem; margin-bottom: 0.5rem; position: relative; animation: fadeInUp 0.4s ease-out;">
                                <span aria-hidden="true" style="position:absolute; left:-7px; top:1.4rem; width:12px; height:12px; border-radius:50%; background: var(--c-brand-500); border: 2px solid var(--c-bg);"></span>
                                <div style="display:flex; gap:0.75rem; align-items:baseline; margin-bottom: 0.5rem; flex-wrap: wrap;">
                                    <strong style="font-family: var(--font-sans); font-size: var(--t-sm);">
                                        <time>${e.pretty_at || ''}</time>
                                    </strong>
                                </div>
                                ${e.headline ? `<h3 style="margin-bottom: 0.5rem;">${escapeHtml(e.headline)}</h3>` : ''}
                                <div class="prose" style="font-size: var(--t-base); line-height: 1.65; max-width: none;">${e.body}</div>
                            </article>`;
                        list.insertAdjacentHTML('afterbegin', html);
                        if (e.posted_ts > lastTs) lastTs = e.posted_ts;
                        newCount++;
                        if (! firstHeadline) firstHeadline = e.headline || '';
                    });
                    list.dataset.latestTs = String(lastTs);

                    // Mirror the new-entry arrival into the global
                    // sr-announce region so a screen reader gets a
                    // single concise summary even though the timeline
                    // section's own aria-live=polite already
                    // announces the inserted DOM.
                    if (newCount > 0 && window.usntAnnounce) {
                        window.usntAnnounce(newCount === 1
                            ? `New live update${firstHeadline ? ': ' + firstHeadline : ''}`
                            : `${newCount} new live updates`);
                    }
                } catch (_e) { /* swallow; next tick will try again */ }
            }
            function escapeHtml(s) {
                return String(s).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            }
            setInterval(poll, 20_000);
        })();
    </script>
@endif
@endpush

@section('content')
<div class="container-narrow article" style="margin-block: 2rem 4rem;">
    <header class="article__header">
        <span class="article__kicker">
            @if ($live->status === 'active') 🔴 Live now @else Coverage closed @endif
        </span>
        <h1 class="article__title" style="font-size: var(--t-5xl);">{{ $live->title }}</h1>
        @if ($live->summary)
            <p class="article__subtitle">{{ $live->summary }}</p>
        @endif
        <div class="article__meta">
            @if ($live->started_at)
                <time datetime="{{ $live->started_at->toAtomString() }}">Started {{ $live->started_at->format('F j, Y · g:i a') }}</time>
            @endif
            @if ($live->status === 'closed' && $live->ended_at)
                <span>Closed {{ $live->ended_at->diffForHumans() }}</span>
            @endif
        </div>
    </header>

    @if ($live->hero_image)
        <div class="article__hero">
            <img src="{{ image_src($live->hero_image) }}" alt="{{ $live->title }}">
        </div>
    @endif

    {{-- Open polls: render before the timeline so readers see them
         immediately. Voted state is tracked client-side via a
         lightweight cookie; results-after-vote come from the server
         response. --}}
    @if (! empty($polls) && $polls->count())
        <section class="live-polls" style="margin-bottom: 2rem;">
            @foreach ($polls as $poll)
                @include('partials.site.live-poll', ['poll' => $poll])
            @endforeach
        </section>
    @endif

    @if ($pinned->isNotEmpty())
        <section style="margin-bottom: 2rem;">
            @foreach ($pinned as $entry)
                <article class="aside-block" style="border-color: var(--c-brand-500); border-width: 2px;">
                    <div style="display:flex; gap:0.75rem; align-items:center; margin-bottom: 0.5rem;">
                        <span class="badge badge--brand">Pinned</span>
                        <time class="text-faint" style="font-size: var(--t-xs);" datetime="{{ $entry->posted_at->toAtomString() }}">
                            {{ $entry->posted_at->format('M j · g:i a') }}
                        </time>
                    </div>
                    @if ($entry->headline)<h3>{{ $entry->headline }}</h3>@endif
                    <div class="prose" style="font-size: var(--t-base); line-height: 1.6;">
                        {!! sanitize_rich_html($entry->body) !!}
                    </div>
                </article>
            @endforeach
        </section>
    @endif

    @if ($entries->count())
        @php $latestTs = optional($entries->first()->posted_at)->timestamp ?? 0; @endphp
        {{-- aria-live="polite" announces newly-inserted entries to
             screen readers without interrupting current speech;
             aria-relevant="additions" tells AT we only care about
             added children, not edits or deletions. --}}
        <section id="live-entries" data-latest-ts="{{ $latestTs }}"
                 aria-live="polite" aria-atomic="false" aria-relevant="additions"
                 aria-label="Live blog updates">
            @foreach ($entries as $entry)
                <article style="border-left: 2px solid var(--c-border); padding: 1rem 0 1rem 1.25rem; margin-bottom: 0.5rem; position: relative;">
                    <span aria-hidden="true" style="position:absolute; left:-7px; top:1.4rem; width:12px; height:12px; border-radius:50%; background: var(--c-brand-500); border: 2px solid var(--c-bg);"></span>
                    <div style="display:flex; gap:0.75rem; align-items:baseline; margin-bottom: 0.5rem; flex-wrap: wrap;">
                        <strong style="font-family: var(--font-sans); font-size: var(--t-sm);">
                            <time datetime="{{ $entry->posted_at->toAtomString() }}">
                                {{ $entry->posted_at->format('g:i a') }}
                            </time>
                            <span class="text-faint" style="font-weight: 400;">·</span>
                            <span class="text-muted">{{ $entry->posted_at->format('M j') }}</span>
                        </strong>
                        @if ($entry->postedBy)
                            <span class="text-muted" style="font-size: var(--t-xs);">{{ $entry->postedBy->name }}</span>
                        @endif
                    </div>
                    @if ($entry->headline)<h3 style="margin-bottom: 0.5rem;">{{ $entry->headline }}</h3>@endif
                    <div class="prose" style="font-size: var(--t-base); line-height: 1.65; max-width: none;">
                        {!! sanitize_rich_html($entry->body) !!}
                    </div>
                </article>
            @endforeach
        </section>

        <div>{{ $entries->links('partials.site.pagination') }}</div>
    @else
        <p class="text-muted">No entries yet — check back soon.</p>
    @endif
</div>
@endsection
