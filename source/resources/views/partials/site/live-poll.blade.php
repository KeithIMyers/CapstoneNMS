{{-- One open live poll. Renders the question + radio choices when
     the visitor hasn't voted, falls through to a percentage bar
     readout after voting. State persists in the `usnt_voted` cookie
     so re-renders within the same browser stay in the results
     view. --}}
@php
    $poll      = $poll ?? null;
    if (! $poll) return;
    $totalVotes = (int) $poll->total_votes;
    $accepting  = $poll->isAcceptingVotes();
    $opts       = (array) ($poll->options ?? []);
    $pcts       = $poll->percentages();
@endphp

<div class="live-poll" data-poll-id="{{ $poll->id }}"
     style="background: var(--c-paper); border: 1px solid var(--c-border); border-radius: var(--radius-lg); padding: 1.25rem; margin-bottom: 1rem;">
    <div style="display:flex; justify-content:space-between; align-items:baseline; gap:1rem; flex-wrap:wrap; margin-bottom:0.6rem;">
        <strong style="font-size: 0.9rem; letter-spacing: 0.06em; text-transform: uppercase; color: var(--c-fg-soft);">📊 Live poll</strong>
        @if (! $accepting)
            <span class="text-muted" style="font-size: 0.8rem;">Voting closed</span>
        @endif
    </div>

    <h3 style="margin: 0 0 0.85rem; font-size: 1.1rem; line-height: 1.35;">{{ $poll->question }}</h3>

    {{-- Pre-vote choices. JS swaps these out for the results view
         after a successful vote (or if cookie says we already
         voted). --}}
    <div class="live-poll__choices" data-state="vote" @if (! $accepting) hidden @endif>
        @foreach ($opts as $opt)
            @php $cid = (int) ($opt['id'] ?? 0); @endphp
            <button type="button"
                    class="live-poll__choice"
                    data-choice-id="{{ $cid }}"
                    style="display:block; width:100%; text-align:left; padding:0.65rem 0.85rem; margin-bottom:0.4rem; border:1px solid var(--c-border); border-radius: var(--radius-md); background: var(--c-bg); cursor: pointer; font-size: 0.95rem;">
                {{ $opt['label'] ?? '(unnamed choice)' }}
            </button>
        @endforeach
    </div>

    {{-- Post-vote / closed-poll results bars. --}}
    <div class="live-poll__results" data-state="results" @if ($accepting) hidden @endif>
        @foreach ($opts as $opt)
            @php
                $cid = (int) ($opt['id'] ?? 0);
                $pct = (int) ($pcts[$cid] ?? 0);
            @endphp
            <div data-choice-id="{{ $cid }}" style="margin-bottom: 0.55rem;">
                <div style="display:flex; justify-content:space-between; font-size: 0.9rem; margin-bottom: 0.2rem;">
                    <span>{{ $opt['label'] ?? '(unnamed)' }}</span>
                    <span class="text-muted live-poll__pct">{{ $pct }}%</span>
                </div>
                <div style="height: 8px; background: var(--c-bg-soft, #eee); border-radius: 4px; overflow: hidden;">
                    <div class="live-poll__bar" style="height:100%; width:{{ $pct }}%; background: var(--c-brand-500, #0ea5e9); transition: width 0.4s;"></div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="text-muted" style="font-size: 0.78rem; margin-top: 0.65rem;">
        <span class="live-poll__total">{{ number_format($totalVotes) }}</span> {{ \Illuminate\Support\Str::plural('vote', $totalVotes) }}
        @if ($poll->closes_at) · closes {{ $poll->closes_at->diffForHumans() }} @endif
    </div>
</div>
