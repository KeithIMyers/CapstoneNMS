<x-filament-panels::page>
    @php $s = $this->getStatus(); @endphp

    {{-- Live progress card. Hidden until either a poll detects an
         in-flight apply (status=running) OR the page receives the
         `updater-started` Livewire event from applyManifest()/
         applyUpload(). Hits {{ route('updater.progress') }} every
         1.2s while running; stops + redirects on complete/failed. --}}
    <div
        x-data="{
            visible: @json(in_array(($s['progress']['status'] ?? null), ['running', 'complete', 'failed'], true)),
            state: @json($s['progress'] ?? null),
            poll: null,
            start() {
                this.visible = true;
                if (this.poll) return;
                this.tick();
                this.poll = setInterval(() => this.tick(), 1200);
            },
            stop() {
                if (this.poll) { clearInterval(this.poll); this.poll = null; }
            },
            async tick() {
                try {
                    const r = await fetch('{{ route('updater.progress') }}', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
                    if (! r.ok) return;
                    const j = await r.json();
                    this.state = j;
                    if (j.status === 'complete' || j.status === 'failed') {
                        this.stop();
                        if (j.status === 'complete') {
                            // Reload to show the new version + clear caches
                            setTimeout(() => window.location.reload(), 1500);
                        }
                    }
                } catch (e) { /* swallow transient network errors */ }
            },
            init() {
                if (this.visible && (this.state && this.state.status === 'running')) this.start();
                window.addEventListener('updater-started', () => this.start());
            },
        }"
        x-show="visible"
        x-transition
        style="margin-bottom:1.5rem;padding:1.25rem;background:#0f172a;color:#f1f5f9;border-radius:0.6rem;"
    >
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.85rem;">
            <div style="font-weight:700;font-size:1.05rem;" x-text="state ? (state.message || 'Updating…') : 'Starting…'"></div>
            <div style="font-size:0.85rem;color:#94a3b8;" x-text="state && state.percent !== undefined ? state.percent + '%' : ''"></div>
        </div>

        <div style="height:0.65rem;background:#1e293b;border-radius:9999px;overflow:hidden;">
            <div
                style="height:100%;background:linear-gradient(90deg,#0ea5e9,#22d3ee);transition:width 0.4s ease;"
                :style="'width: ' + (state && state.percent !== undefined ? state.percent : 0) + '%;'"
            ></div>
        </div>

        <div style="margin-top:0.85rem;font-size:0.8rem;color:#94a3b8;">
            <span x-text="state && state.step ? 'Step: ' + state.step : ''"></span>
            <template x-if="state && state.status === 'complete'">
                <span style="color:#22c55e;font-weight:700;">  ✓ Done — reloading…</span>
            </template>
            <template x-if="state && state.status === 'failed'">
                <span>
                    <span style="color:#ef4444;font-weight:700;">  ✕ Failed.</span>
                    <template x-for="err in (state.errors || [])">
                        <div style="margin-top:0.35rem;color:#fecaca;" x-text="'• ' + err"></div>
                    </template>
                </span>
            </template>
            <template x-if="!state || state.status === 'running' || state.status === 'idle'">
                <span style="color:#94a3b8;">Don't close this tab. The apply runs in the background; this page will refresh when it finishes.</span>
            </template>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-bottom:2rem;">
        <div style="padding:1rem;border:1px solid var(--gray-200, #e5e7eb);border-radius:0.5rem;">
            <div style="font-size:0.8rem;color:var(--gray-500, #6b7280);">Installed version</div>
            <div style="margin-top:0.35rem;font-weight:700;font-size:1.15rem;">{{ $s['current'] }}</div>
        </div>

        <div style="padding:1rem;border:1px solid var(--gray-200, #e5e7eb);border-radius:0.5rem;">
            <div style="font-size:0.8rem;color:var(--gray-500, #6b7280);">Latest available</div>
            <div style="margin-top:0.35rem;font-weight:700;font-size:1.15rem;">
                @if ($s['manifest'])
                    {{ $s['manifest']['version'] ?? '?' }}
                @else
                    <span style="font-weight:400;color:var(--gray-500, #6b7280);">click "Check for updates"</span>
                @endif
            </div>
        </div>

        <div style="padding:1rem;border:1px solid var(--gray-200, #e5e7eb);border-radius:0.5rem;">
            <div style="font-size:0.8rem;color:var(--gray-500, #6b7280);">Update permission</div>
            <div style="margin-top:0.35rem;">
                @if ($s['allowed'])
                    <x-filament::badge color="success">Active license</x-filament::badge>
                @else
                    <x-filament::badge color="danger">Renew to enable</x-filament::badge>
                @endif
            </div>
        </div>
    </div>

    @if (! $s['allowed'])
        <div style="margin-bottom:1.5rem;padding:0.85rem 1rem;background:#fef3c7;border:1px solid #fde68a;border-radius:0.5rem;color:#78350f;">
            <strong>Updates require an active license.</strong> Editorial keeps working through the 14-day grace window after expiry, but new releases are gated on a renewed subscription. Visit <a href="https://capstonenms.com/account">capstonenms.com/account</a> for a fresh license, then upload it on the License page.
        </div>
    @endif

    @if ($s['manifest'])
        <div style="margin-bottom:1.5rem;padding:1rem;background:#f0f9ff;border:1px solid #bae6fd;border-radius:0.5rem;">
            <div style="font-weight:700;margin-bottom:0.35rem;">CapstoneNMS {{ $s['manifest']['version'] ?? '' }} is available</div>
            @if (! empty($s['manifest']['released_at']))
                <div style="font-size:0.85rem;color:var(--gray-500, #6b7280);">Released {{ \Illuminate\Support\Carbon::parse($s['manifest']['released_at'])->toDateString() }}</div>
            @endif
            @if (! empty($s['manifest']['notes']))
                <div style="margin-top:0.85rem;font-size:0.9rem;line-height:1.5;">{{ $s['manifest']['notes'] }}</div>
            @endif
        </div>
    @endif

    <h3 style="margin-bottom:0.5rem;font-size:1rem;font-weight:700;">Air-gapped install</h3>
    <p style="font-size:0.9rem;color:var(--gray-500, #6b7280);margin-bottom:1rem;">
        If your server can't reach the update CDN, download the release and signature files from your CapstoneNMS account and upload them here.
    </p>
    <form wire:submit="applyUpload">
        {{ $this->form }}
    </form>

    <p style="margin-top:2rem;font-size:0.85rem;color:var(--gray-500, #6b7280);">
        Every update is verified against the embedded product key before any files are touched. A pre-update snapshot is written to <code>storage/app/private/updates/backups/</code> automatically.
    </p>
</x-filament-panels::page>
