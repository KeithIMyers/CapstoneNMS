<x-filament-panels::page>
    @php
        $s = $this->getStatus();
        // The card is visible only while an apply is in flight, OR
        // briefly after it completes/fails so the user sees the
        // outcome before the page reloads. After a fresh page render
        // with no in-flight progress, the card stays hidden.
        $progressInitial = $s['progress'] ?? null;
        $progressVisibleInitial = ($progressInitial['status'] ?? null) === 'running'
            || (
                in_array(($progressInitial['status'] ?? null), ['complete', 'failed'], true)
                && isset($progressInitial['finished_at'])
                && (time() - (int) $progressInitial['finished_at']) < 30
            );
        $progressEndpoint = route('updater.progress');
    @endphp

    {{-- Live progress card. Seed values live in a separate JSON
         script so the inner double-quotes don't close the x-data
         HTML attribute prematurely. --}}
    <script type="application/json" id="updater-card-seed">
        {!! json_encode([
            'visible'  => $progressVisibleInitial,
            'state'    => $progressInitial,
            'endpoint' => $progressEndpoint,
        ], JSON_UNESCAPED_SLASHES) !!}
    </script>

    <div
        x-data="updaterCard()"
        x-show="visible"
        x-transition.opacity
        x-cloak
        class="fi-section fi-section-has-content"
        style="margin-bottom:1.5rem;"
    >
        <div class="fi-section-content-ctn">
            <div class="fi-section-content p-6">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1rem;">
                    <div>
                        <div style="font-weight:600;font-size:1rem;color:var(--gray-900);" x-text="state ? (state.message || 'Updating…') : 'Starting…'"></div>
                        <div style="font-size:0.825rem;color:var(--gray-500);margin-top:0.15rem;">
                            <span x-text="state && state.step ? 'Step: ' + state.step.replace(/_/g, ' ') : ''"></span>
                        </div>
                    </div>
                    <div style="font-weight:700;font-size:1.1rem;color:var(--primary-600);font-variant-numeric:tabular-nums;" x-text="(state && state.percent !== undefined ? state.percent : 0) + '%'"></div>
                </div>

                {{-- Bar with both a fill (driven by percent) AND an
                     overlaid moving stripe (driven by CSS animation),
                     so even between step updates there's visible
                     motion. --}}
                <div style="position:relative;height:0.625rem;background:var(--gray-100);border-radius:9999px;overflow:hidden;">
                    <div
                        :style="{ width: ((state && state.percent !== undefined ? state.percent : 0)) + '%' }"
                        style="height:100%;background:var(--primary-500);transition:width 600ms cubic-bezier(0.22, 1, 0.36, 1);position:relative;"
                    ></div>
                    {{-- Animated diagonal stripe overlay — visible only
                         while running so it doesn't keep moving on
                         success/failure. --}}
                    <div
                        x-show="state && state.status === 'running'"
                        style="position:absolute;inset:0;background-image:linear-gradient(135deg,rgba(255,255,255,0.18) 25%,transparent 25%,transparent 50%,rgba(255,255,255,0.18) 50%,rgba(255,255,255,0.18) 75%,transparent 75%,transparent);background-size:1.25rem 1.25rem;animation:capnms-progress-stripe 0.9s linear infinite;mix-blend-mode:overlay;pointer-events:none;"
                    ></div>
                </div>

                <div style="margin-top:0.85rem;font-size:0.825rem;color:var(--gray-500);">
                    <template x-if="state && state.status === 'complete'">
                        <span style="color:var(--success-600);font-weight:600;">  ✓ Done — reloading…</span>
                    </template>
                    <template x-if="state && state.status === 'failed'">
                        <div>
                            <div style="color:var(--danger-600);font-weight:600;margin-bottom:0.25rem;">  ✕ Update failed</div>
                            <template x-for="err in (state.errors || [])">
                                <div style="color:var(--danger-700);" x-text="'• ' + err"></div>
                            </template>
                        </div>
                    </template>
                    <template x-if="!state || state.status === 'running'">
                        <span>Don't close this tab. The apply runs in the background; this page will refresh when it finishes.</span>
                    </template>
                </div>
            </div>
        </div>
    </div>

    <style>
        @keyframes capnms-progress-stripe {
            from { background-position: 0 0; }
            to   { background-position: 1.25rem 0; }
        }
    </style>

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

        <div style="margin-top:1rem;">
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="applyUpload" color="primary">
                Apply uploaded files
            </x-filament::button>
            <span wire:loading wire:target="applyUpload" style="margin-left:0.75rem;font-size:0.85rem;color:var(--gray-500);">
                Starting…
            </span>
        </div>
    </form>

    <p style="margin-top:2rem;font-size:0.85rem;color:var(--gray-500, #6b7280);">
        Every update is verified against the embedded product key before any files are touched. A pre-update snapshot is written to <code>storage/app/private/updates/backups/</code> automatically.
    </p>

    <script>
        // Defined as a global so Alpine's x-data="updaterCard()" can resolve it.
        window.updaterCard = function () {
            const seed = JSON.parse(document.getElementById('updater-card-seed').textContent);
            return {
                visible: !! seed.visible,
                state: seed.state,
                endpoint: seed.endpoint,
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
                        const r = await fetch(this.endpoint, {
                            credentials: 'same-origin',
                            headers: { 'Accept': 'application/json' },
                        });
                        if (! r.ok) return;
                        const j = await r.json();
                        this.state = j;
                        if (j.status === 'complete' || j.status === 'failed') {
                            this.stop();
                            if (j.status === 'complete') {
                                setTimeout(() => window.location.reload(), 1500);
                            }
                        }
                    } catch (e) { /* swallow transient network errors */ }
                },

                init() {
                    if (this.state && this.state.status === 'running') this.start();
                    window.addEventListener('updater-started', () => this.start());
                },
            };
        };
    </script>
</x-filament-panels::page>
