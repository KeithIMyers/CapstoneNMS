@extends('layouts.site')

@section('head_title', 'Team subscription · '.getcong('site_name'))

@section('content')
<div class="container-narrow" style="margin-block: 2.5rem 4rem;">
    <h1>Team subscription</h1>
    <p class="text-muted" style="margin-block: 0.5rem 1.5rem;">
        Invite teammates by email. Each invitation fills one seat; recipients keep access for as long as the team subscription is active.
    </p>

    @if (session('flash_message'))
        <div class="flash flash--ok" role="status">{{ session('flash_message') }}</div>
    @endif
    @if (session('error_flash_message'))
        <div class="flash flash--err" role="alert">{{ session('error_flash_message') }}</div>
    @endif

    @if ($teams->isEmpty() && $memberTeams->isEmpty())
        <div class="card" style="padding:1.5rem;">
            <p>You're not on a team subscription yet. <a href="{{ url('/subscribe') }}">Browse plans</a> — pick one marked as a team plan to set one up.</p>
        </div>
    @endif

    @foreach ($teams as $team)
        <section class="card" style="padding:1.5rem; margin-bottom: 1.5rem;">
            <header style="display:flex; justify-content:space-between; align-items:baseline; gap:1rem; flex-wrap:wrap;">
                <div>
                    <h2 style="margin:0; font-size: var(--t-md);">
                        {{ $team->team_name ?: 'Team subscription' }}
                    </h2>
                    <p class="text-muted" style="margin:0.2rem 0 0; font-size:0.9rem;">
                        {{ optional($team->tier())->name ?: $team->tier_slug }}
                        · {{ $team->activeSeatsCount() }}/{{ $team->seat_count }} seats filled
                        @if ($team->pendingInvitesCount() > 0)
                            · {{ $team->pendingInvitesCount() }} pending invitation{{ $team->pendingInvitesCount() === 1 ? '' : 's' }}
                        @endif
                    </p>
                </div>
                @if (! $team->isActive())
                    <span class="text-muted" style="background:#fee2e2; color:#991b1b; padding:0.2rem 0.6rem; border-radius:999px; font-size:0.78rem; font-weight:600;">Inactive</span>
                @endif
            </header>

            <h3 style="font-size: 0.9rem; letter-spacing: 0.05em; text-transform: uppercase; color: var(--c-fg-soft); margin-block: 1.25rem 0.6rem;">Seats</h3>
            <ul style="list-style:none; padding:0; display:grid; gap:0.5rem;">
                @foreach ($team->seats as $seat)
                    <li style="display:flex; justify-content:space-between; align-items:center; gap:1rem; padding:0.65rem 0.85rem; border:1px solid var(--c-border); border-radius:6px;">
                        <span>
                            @if ($seat->member)
                                <strong>{{ $seat->member->name }}</strong>
                                <span class="text-muted" style="font-size:0.85rem;">· {{ $seat->member->email }}</span>
                                @if ((int) $seat->member_user_id === (int) $team->owner_user_id)
                                    <span style="background:#e0f2fe; color:#075985; padding:0.1rem 0.5rem; border-radius:999px; font-size:0.7rem; font-weight:600; margin-left:0.4rem;">Owner</span>
                                @endif
                            @elseif ($seat->status === 'invited')
                                <em>Invited:</em> {{ $seat->invited_email }}
                                <span class="text-muted" style="font-size:0.8rem;">· sent {{ optional($seat->invited_at)->diffForHumans() }}</span>
                            @else
                                <span class="text-muted">Open seat</span>
                            @endif
                        </span>
                        @if ((int) $seat->member_user_id !== (int) $team->owner_user_id)
                            <form method="post" action="{{ route('team.seat.remove', ['seat' => $seat->id]) }}"
                                  onsubmit="return confirm('Remove this seat? The member will lose access immediately.');">
                                @csrf
                                <button type="submit" class="btn btn--ghost" style="font-size:0.8rem;">Remove</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($team->openSeatsCount() > 0 || $team->pendingInvitesCount() > 0)
                <form method="post" action="{{ route('team.invite', ['team' => $team->id]) }}" style="margin-top: 1.25rem;">
                    @csrf
                    <div class="field">
                        <label for="invite-email-{{ $team->id }}">Invite by email</label>
                        <input id="invite-email-{{ $team->id }}" type="email" name="email" required
                               placeholder="colleague@example.com"
                               aria-describedby="invite-help-{{ $team->id }}">
                        <small id="invite-help-{{ $team->id }}" class="text-muted">
                            They'll get a single-use link valid for {{ \App\Services\Paywall\TeamSubscriptionService::TOKEN_TTL_DAYS }} days. Already have an account? They sign in. New here? They create one.
                        </small>
                    </div>
                    <button class="btn" type="submit">Send invitation</button>
                </form>
            @endif
        </section>
    @endforeach

    @if ($memberTeams->isNotEmpty())
        <section style="margin-top: 1.5rem;">
            <h2 style="font-size: var(--t-md);">Teams you're a member of</h2>
            @foreach ($memberTeams as $t)
                <div class="card" style="padding:1rem 1.25rem; margin-bottom:0.75rem;">
                    {{ $t->team_name ?: 'Team subscription' }}
                    <span class="text-muted" style="font-size:0.85rem;">· owner: {{ $t->owner?->name ?: '—' }}</span>
                </div>
            @endforeach
        </section>
    @endif
</div>
@endsection
