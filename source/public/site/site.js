/* =============================================================
   CapstoneNMS — frontend behaviors
   Hand-written vanilla JS, no framework, no build step.
   ============================================================= */
(function () {
    'use strict';

    /* ---------- Theme toggle (light / dark / system) -------- */
    const THEME_KEY = 'usnt_theme';

    function applyTheme(theme) {
        if (theme === 'light' || theme === 'dark') {
            document.documentElement.dataset.theme = theme;
        } else {
            delete document.documentElement.dataset.theme;
        }
    }

    function currentTheme() {
        const stored = localStorage.getItem(THEME_KEY);
        if (stored === 'light' || stored === 'dark') return stored;
        return 'system';
    }

    function cycleTheme() {
        const order = ['system', 'light', 'dark'];
        const next = order[(order.indexOf(currentTheme()) + 1) % order.length];
        if (next === 'system') localStorage.removeItem(THEME_KEY);
        else localStorage.setItem(THEME_KEY, next);
        applyTheme(next === 'system' ? null : next);
        updateThemeToggleLabel();
    }

    function updateThemeToggleLabel() {
        const btn = document.querySelector('[data-theme-toggle]');
        if (!btn) return;
        const t = currentTheme();
        // aria-pressed signals toggle state to assistive tech in a
        // way `aria-label` alone cannot. We expose the *next* state
        // in the label so screen readers say "Theme: dark, button"
        // and the user knows what they're switching to.
        btn.setAttribute('aria-label', `Theme: ${t} (click to change)`);
        btn.setAttribute('aria-pressed', t === 'dark' ? 'true' : 'false');
        btn.dataset.themeState = t;
    }

    // On boot, apply persisted choice (system pref handled by CSS).
    applyTheme(localStorage.getItem(THEME_KEY));

    /* ---------- Mobile menu --------------------------------- */
    document.addEventListener('click', (e) => {
        const trigger = e.target.closest('[data-menu-toggle]');
        if (trigger) {
            const nav = document.querySelector('[data-site-nav]');
            if (nav) {
                const open = nav.dataset.open === 'true';
                nav.dataset.open = open ? 'false' : 'true';
                trigger.setAttribute('aria-expanded', String(!open));
            }
            return;
        }

        // Close menu when clicking outside
        const nav = document.querySelector('[data-site-nav]');
        if (nav && nav.dataset.open === 'true' && !e.target.closest('[data-site-nav]')) {
            nav.dataset.open = 'false';
            const trig = document.querySelector('[data-menu-toggle]');
            if (trig) trig.setAttribute('aria-expanded', 'false');
        }

        // Theme toggle
        if (e.target.closest('[data-theme-toggle]')) {
            cycleTheme();
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            const nav = document.querySelector('[data-site-nav]');
            if (nav && nav.dataset.open === 'true') {
                nav.dataset.open = 'false';
                const trig = document.querySelector('[data-menu-toggle]');
                if (trig) trig.setAttribute('aria-expanded', 'false');
            }
        }
    });

    /* ---------- CSRF helper for AJAX ------------------------ */
    function csrf() {
        const m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }

    function postForm(url, body) {
        const fd = new FormData();
        for (const k in body) fd.append(k, body[k]);
        fd.append('_token', csrf());
        return fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: fd,
            credentials: 'same-origin',
        }).then((r) => r.json());
    }

    /* ---------- Favorite button (heart) --------------------- */
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-favorite]');
        if (!btn) return;
        e.preventDefault();
        const postId = btn.dataset.favorite;
        const url = btn.dataset.url || '/ajax_actions';
        postForm(url, { id: postId, action_for: 'news_favorite' }).then((res) => {
            if (res.status === 1) {
                const pressed = btn.getAttribute('aria-pressed') === 'true';
                btn.setAttribute('aria-pressed', String(!pressed));
                btn.title = res.set_title || (pressed ? 'Set Favorite' : 'Favorite');
            } else if (res.msg_text) {
                showFlash(res.msg_text, 'err');
            }
        });
    });

    /* ---------- Search autocomplete ------------------------- */
    // Light search-as-you-type. Attaches to any
    // <input data-search-autocomplete> + injects a suggestion
    // panel below it. Debounced 200 ms; cached server-side for
    // 60 s per query so refresh-spam doesn't hammer the DB.
    document.querySelectorAll('input[data-search-autocomplete]').forEach((input) => {
        const panel = document.createElement('div');
        panel.className = 'search-suggest';
        panel.setAttribute('role', 'listbox');
        panel.hidden = true;
        Object.assign(panel.style, {
            position: 'absolute',
            background: 'var(--c-bg)',
            border: '1px solid var(--c-border)',
            borderRadius: '6px',
            boxShadow: '0 6px 18px rgb(15 23 42 / 0.08)',
            zIndex: '20',
            maxHeight: '24rem',
            overflowY: 'auto',
            minWidth: '20rem',
            marginTop: '0.4rem',
        });
        input.parentElement.style.position = input.parentElement.style.position || 'relative';
        input.parentElement.appendChild(panel);

        let timer = 0;
        let activeIndex = -1;

        function close() {
            panel.hidden = true;
            panel.innerHTML = '';
            activeIndex = -1;
        }

        function render(data) {
            panel.innerHTML = '';
            const sections = [
                { key: 'articles',   label: 'Articles',   urlOf: (i) => `/news/${i.slug}` },
                { key: 'tags',       label: 'Tags',       urlOf: (i) => `/tags/${i.slug}` },
                { key: 'categories', label: 'Categories', urlOf: (i) => `/category/${i.slug}` },
            ];
            let any = false;
            sections.forEach((section) => {
                const items = (data[section.key] || []).slice(0, 5);
                if (! items.length) return;
                any = true;
                const head = document.createElement('div');
                head.textContent = section.label;
                Object.assign(head.style, {
                    padding: '0.45rem 0.75rem',
                    fontSize: '0.7rem',
                    letterSpacing: '0.06em',
                    textTransform: 'uppercase',
                    color: 'var(--c-fg-soft)',
                    borderTop: '1px solid var(--c-border)',
                });
                panel.appendChild(head);
                items.forEach((it) => {
                    const a = document.createElement('a');
                    a.href = section.urlOf(it);
                    a.role = 'option';
                    a.textContent = it.title || it.name || it.slug;
                    Object.assign(a.style, {
                        display: 'block',
                        padding: '0.55rem 0.75rem',
                        textDecoration: 'none',
                        color: 'inherit',
                    });
                    a.addEventListener('mouseenter', () => {
                        Array.from(panel.querySelectorAll('a')).forEach((el) => el.style.background = '');
                        a.style.background = 'var(--c-bg-soft, #f3f4f6)';
                    });
                    panel.appendChild(a);
                });
            });
            panel.hidden = ! any;
            if (any && typeof window.usntAnnounce === 'function') {
                window.usntAnnounce('Suggestions ready. Use arrow keys to browse.');
            }
        }

        input.addEventListener('input', () => {
            const q = input.value.trim();
            if (timer) { clearTimeout(timer); timer = 0; }
            if (q.length < 2) { close(); return; }
            timer = setTimeout(() => {
                fetch('/search/autocomplete?q=' + encodeURIComponent(q), {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                }).then((r) => r.ok ? r.json() : null).then((data) => {
                    if (! data) return close();
                    render(data);
                });
            }, 200);
        });

        input.addEventListener('keydown', (e) => {
            const items = Array.from(panel.querySelectorAll('a'));
            if (! items.length) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); activeIndex = (activeIndex + 1) % items.length; }
            else if (e.key === 'ArrowUp') { e.preventDefault(); activeIndex = (activeIndex - 1 + items.length) % items.length; }
            else if (e.key === 'Enter' && activeIndex >= 0) { e.preventDefault(); window.location.href = items[activeIndex].href; return; }
            else if (e.key === 'Escape') { close(); return; }
            else return;
            items.forEach((el, i) => el.style.background = i === activeIndex ? 'var(--c-bg-soft, #f3f4f6)' : '');
        });

        input.addEventListener('blur', () => setTimeout(close, 150));
    });

    /* ---------- Live polls ---------------------------------- */
    const VOTED_KEY = 'usnt_voted_polls';
    function votedSet() {
        try { return new Set(JSON.parse(localStorage.getItem(VOTED_KEY) || '[]')); }
        catch (e) { return new Set(); }
    }
    function rememberVoted(pollId) {
        const s = votedSet(); s.add(String(pollId));
        try { localStorage.setItem(VOTED_KEY, JSON.stringify([...s])); } catch (e) {}
    }
    // On page load, swap any poll we've already voted on into the
    // results view. The server enforces single-vote per (cookie,
    // poll); this is just so the UI doesn't ask the visitor to
    // re-pick.
    document.addEventListener('DOMContentLoaded', () => {
        const voted = votedSet();
        document.querySelectorAll('.live-poll').forEach((el) => {
            if (voted.has(el.dataset.pollId)) {
                const v = el.querySelector('[data-state="vote"]');
                const r = el.querySelector('[data-state="results"]');
                if (v) v.hidden = true;
                if (r) r.hidden = false;
            }
        });
    });

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.live-poll__choice');
        if (!btn) return;
        e.preventDefault();
        const wrap = btn.closest('.live-poll');
        if (!wrap) return;
        const pollId = wrap.dataset.pollId;
        const choiceId = parseInt(btn.dataset.choiceId, 10);

        fetch(`/live/polls/${pollId}/vote`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ choice_id: choiceId }),
        }).then((r) => r.json()).then((res) => {
            if (!res || !res.ok) {
                if (res && res.reason === 'closed') showFlash('This poll is closed.', 'err');
                return;
            }
            rememberVoted(pollId);
            const v = wrap.querySelector('[data-state="vote"]');
            const r = wrap.querySelector('[data-state="results"]');
            if (v) v.hidden = true;
            if (r) r.hidden = false;
            const pcts = res.percentages || {};
            r.querySelectorAll('[data-choice-id]').forEach((row) => {
                const cid = row.dataset.choiceId;
                const pct = parseInt(pcts[cid] || 0, 10);
                const bar = row.querySelector('.live-poll__bar');
                const lbl = row.querySelector('.live-poll__pct');
                if (bar) bar.style.width = `${pct}%`;
                if (lbl) lbl.textContent = `${pct}%`;
            });
            const total = wrap.querySelector('.live-poll__total');
            if (total) total.textContent = String(res.total_votes ?? 0);
        });
    });

    /* ---------- Comment likes ------------------------------- */
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-comment-like]');
        if (!btn) return;
        e.preventDefault();
        const commentId = btn.dataset.commentLike;

        fetch(`/comments/${commentId}/like`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
            credentials: 'same-origin',
        }).then((r) => r.status === 401 ? null : r.json()).then((res) => {
            if (!res || !res.ok) {
                if (res === null) showFlash('Sign in to like comments.', 'err');
                return;
            }
            btn.setAttribute('aria-pressed', String(res.liking));
            // Keep aria-label in sync so screen readers hear the
            // updated state + count after the toggle, not the stale
            // server-side text.
            const word = res.liking ? 'Unlike' : 'Like';
            const plural = res.count === 1 ? 'like' : 'likes';
            btn.setAttribute('aria-label', `${word} this comment (${res.count} ${plural})`);
            const heart = btn.querySelector('span:first-child');
            const count = btn.querySelector('.comment__like-count');
            if (heart) heart.textContent = res.liking ? '♥' : '♡';
            if (count) count.textContent = String(res.count);
            announce(res.liking ? `Liked. ${res.count} ${plural} total.` : 'Like removed.');
        });
    });

    /* ---------- Reactions ----------------------------------- */
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-reaction]');
        if (!btn) return;
        e.preventDefault();
        const articleId = btn.dataset.articleId;
        const type = btn.dataset.reaction;
        if (!articleId || !type) return;

        fetch(`/articles/${articleId}/reactions`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf(),
                'Accept': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ type }),
        }).then((r) => {
            if (r.status === 401) { showFlash('Sign in to react.', 'err'); return null; }
            return r.json();
        }).then((res) => {
            if (!res) return;
            // Update counts + active state.
            const wrap = btn.closest('[data-reactions]');
            if (!wrap) return;
            wrap.querySelectorAll('[data-reaction]').forEach((el) => {
                const t = el.dataset.reaction;
                el.dataset.active = res.userReaction === t ? 'true' : 'false';
                const countEl = el.querySelector('.reaction__count');
                if (countEl) countEl.textContent = res.reactions[t] || 0;
            });
        });
    });

    /* ---------- Ad slot loader ----------------------------- */
    // Fetches the active creative for each placeholder on the page. Done
    // client-side so the surrounding HTML stays cacheable by the response-
    // cache middleware — different visitors get different rotated creatives
    // even on a cached page.
    function loadAdSlots() {
        const slots = document.querySelectorAll('.ad-slot[data-ad-placement]');
        slots.forEach((el) => {
            const placement = el.getAttribute('data-ad-placement');
            if (!placement) return;
            fetch(`/ad/serve/${encodeURIComponent(placement)}`, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            })
                .then((r) => r.ok ? r.json() : null)
                .then((res) => {
                    if (!res || res.empty || !res.html) {
                        el.remove();
                        return;
                    }
                    el.setAttribute('data-ad-slot', String(res.id));
                    if (res.kind) el.setAttribute('data-ad-kind', res.kind);
                    injectAdHtml(el, res.html);
                })
                .catch(() => { el.remove(); });
        });
    }

    /* Inject HTML into an ad-slot wrapper, then re-create any <script>
     * tags so they actually execute. innerHTML alone never runs scripts —
     * needed for AdSense, header-bidding, and any network ad code. */
    function injectAdHtml(target, html) {
        target.innerHTML = html;
        target.querySelectorAll('script').forEach((oldScript) => {
            const newScript = document.createElement('script');
            for (const attr of oldScript.attributes) {
                newScript.setAttribute(attr.name, attr.value);
            }
            // Inline body for inline <script>; external src already on
            // the new tag's attributes will fetch + run.
            if (oldScript.text) newScript.text = oldScript.text;
            oldScript.parentNode.replaceChild(newScript, oldScript);
        });
    }

    // Attribute clicks inside a loaded slot to the slot id. Delegated so it
    // works no matter when the creative HTML is injected.
    document.addEventListener('click', (e) => {
        const wrap = e.target.closest('.ad-slot[data-ad-slot]');
        if (!wrap) return;
        // Only attribute if the click landed on an anchor or a button inside.
        if (!e.target.closest('a, button')) return;
        const sid = wrap.getAttribute('data-ad-slot');
        if (!sid) return;
        const url = `/track/ad-click/${encodeURIComponent(sid)}`;
        try {
            if (navigator.sendBeacon) {
                navigator.sendBeacon(url, new Blob([''], { type: 'application/json' }));
            } else {
                fetch(url, { method: 'POST', keepalive: true });
            }
        } catch (err) { /* fire-and-forget */ }
    }, { capture: true });

    /* ---------- A/B headline click tracking ---------------- */
    // Fire-and-forget: when a card link with data-headline is clicked,
    // beacon the variant id so the server can increment the click counter.
    document.addEventListener('click', (e) => {
        const link = e.target.closest('a[data-headline]');
        if (!link) return;
        const hid = link.getAttribute('data-headline');
        if (!hid) return;
        const url = `/track/headline-click/${encodeURIComponent(hid)}`;
        try {
            if (navigator.sendBeacon) {
                navigator.sendBeacon(url, new Blob([''], { type: 'application/json' }));
            } else {
                fetch(url, { method: 'POST', keepalive: true });
            }
        } catch (err) { /* fire-and-forget */ }
    }, { capture: true });

    /* ---------- Scroll-depth beacon ------------------------ */
    (function () {
        const root = document.querySelector('[data-track-scroll]');
        if (!root) return;
        const articleId = root.dataset.trackScroll;
        if (!articleId) return;

        let maxPct = 0;
        const update = () => {
            const rect = root.getBoundingClientRect();
            const total = root.scrollHeight;
            if (total <= 0) return;
            const seenBottom = window.scrollY + window.innerHeight - (rect.top + window.scrollY);
            const pct = Math.min(100, Math.max(0, Math.round((seenBottom / total) * 100)));
            if (pct > maxPct) maxPct = pct;
        };

        window.addEventListener('scroll', update, { passive: true });
        window.addEventListener('resize', update, { passive: true });
        update();

        function send() {
            if (maxPct < 5) return;
            const url = `/track/scroll/${encodeURIComponent(articleId)}`;
            const body = new Blob(
                [JSON.stringify({ depth_pct: maxPct })],
                { type: 'application/json' }
            );
            try {
                if (navigator.sendBeacon) {
                    navigator.sendBeacon(url, body);
                } else {
                    fetch(url, {
                        method: 'POST',
                        keepalive: true,
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ depth_pct: maxPct }),
                    });
                }
            } catch (e) { /* fire-and-forget */ }
        }

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') send();
        });
        window.addEventListener('pagehide', send);
    })();

    /* ---------- Screen-reader announcements --------------- */
    // Pushes copy into the #sr-announce live region in the layout.
    // Toggling textContent is enough — aria-live="polite" already
    // tells assistive tech to announce on update. We clear after a
    // beat so the same message can be re-announced if it fires
    // again later (otherwise repeated identical messages get
    // suppressed).
    function announce(message, urgent) {
        const zone = document.getElementById('sr-announce');
        if (!zone) return;
        if (urgent) zone.setAttribute('role', 'alert');
        else zone.removeAttribute('role');
        zone.textContent = '';
        // Force a tick so identical messages re-announce; some
        // screen readers require the textContent to actually change.
        setTimeout(() => { zone.textContent = String(message); }, 30);
        setTimeout(() => { zone.textContent = ''; }, 6000);
    }
    // Expose globally so per-page inline scripts (live blog poll,
    // dynamically-rendered partials) can announce too without
    // re-implementing the contract.
    window.usntAnnounce = announce;

    /* ---------- Flash messages ----------------------------- */
    function showFlash(message, kind) {
        let zone = document.getElementById('flash-zone');
        if (!zone) {
            zone = document.createElement('div');
            zone.id = 'flash-zone';
            zone.setAttribute('role', kind === 'err' ? 'alert' : 'status');
            zone.setAttribute('aria-live', kind === 'err' ? 'assertive' : 'polite');
            zone.setAttribute('aria-atomic', 'true');
            zone.style.position = 'fixed';
            zone.style.top = '1rem';
            zone.style.right = '1rem';
            zone.style.zIndex = '999';
            document.body.appendChild(zone);
        }
        const el = document.createElement('div');
        el.className = 'flash flash--' + (kind === 'err' ? 'err' : 'ok');
        el.textContent = message;
        el.style.boxShadow = '0 4px 12px rgb(15 23 42 / 0.18)';
        el.style.minWidth = '14rem';
        zone.appendChild(el);
        // Mirror to the always-present sr-announce region so even
        // screen readers that ignored the dynamically-inserted toast
        // still hear the message.
        announce(message, kind === 'err');
        setTimeout(() => el.remove(), 4500);
    }

    /* ---------- Reading progress bar ----------------------- */
    // Updates a top-of-viewport bar from 0-100% as the user scrolls
    // through the article body. Reuses the data-track-scroll element
    // already attached for scroll-depth analytics so we don't keep two
    // observers in sync.
    (function () {
        const bar = document.querySelector('[data-reading-progress]');
        const root = document.querySelector('[data-track-scroll]');
        if (!bar || !root) return;

        // Announce the bar's role + range to assistive tech up
        // front. The value updates as the reader scrolls; setting
        // aria-valuenow on each update lets a screen reader query
        // "how far am I?" and get a meaningful answer.
        bar.setAttribute('role', 'progressbar');
        bar.setAttribute('aria-label', 'Reading progress');
        bar.setAttribute('aria-valuemin', '0');
        bar.setAttribute('aria-valuemax', '100');
        bar.setAttribute('aria-valuenow', '0');

        let raf = 0;
        const update = () => {
            raf = 0;
            const rect = root.getBoundingClientRect();
            const total = Math.max(1, root.scrollHeight - window.innerHeight);
            const scrolled = Math.min(total, Math.max(0, -rect.top));
            const pct = Math.round((scrolled / total) * 100);
            bar.style.width = pct + '%';
            bar.setAttribute('aria-valuenow', String(pct));
        };
        const onScroll = () => {
            if (raf) return;
            raf = requestAnimationFrame(update);
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll, { passive: true });
        update();
    })();

    /* ---------- Share row (Mastodon prompt + copy link) ---- */
    document.addEventListener('click', (e) => {
        const m = e.target.closest('[data-share-mastodon]');
        if (m) {
            const u = m.getAttribute('data-share-url') || location.href;
            const t = m.getAttribute('data-share-title') || document.title;
            const instance = (window.prompt(
                'Your Mastodon instance (e.g., mastodon.social):',
                'mastodon.social',
            ) || '').trim();
            if (!instance) return;
            const cleanInstance = instance.replace(/^https?:\/\//, '').replace(/\/+$/, '');
            const shareUrl = 'https://' + cleanInstance + '/share?text=' + encodeURIComponent(t + ' ' + u);
            window.open(shareUrl, '_blank', 'noopener,noreferrer');
            return;
        }

        const c = e.target.closest('[data-share-copy]');
        if (c) {
            e.preventDefault();
            const u = c.getAttribute('data-share-url') || location.href;
            const finish = (ok) => {
                showFlash(ok ? 'Link copied to clipboard.' : 'Could not copy — long-press the address bar instead.', ok ? 'ok' : 'err');
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(u).then(() => finish(true)).catch(() => finish(false));
            } else {
                // Fallback: temporary input + execCommand for old browsers.
                try {
                    const input = document.createElement('input');
                    input.value = u;
                    document.body.appendChild(input);
                    input.select();
                    document.execCommand('copy');
                    document.body.removeChild(input);
                    finish(true);
                } catch (err) {
                    finish(false);
                }
            }
        }
    });

    /* ---------- Service worker registration ---------------- */
    // Browsers without service-worker support skip silently. Updated
    // SWs install in the background; the next page load picks them up.
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js').catch(() => {});
        });
    }

    /* ---------- Language nudge (one-shot per session) ------ */
    (function () {
        const nudge = document.querySelector('[data-lang-nudge]');
        if (!nudge) return;
        const key = 'usnt_lang_nudge_' + nudge.getAttribute('data-lang-nudge');
        try {
            if (sessionStorage.getItem(key) === '1') {
                nudge.style.display = 'none';
                return;
            }
        } catch (e) { /* private mode — show every time */ }
        nudge.querySelector('.lang-nudge__close')?.addEventListener('click', () => {
            try { sessionStorage.setItem(key, '1'); } catch (e) {}
            nudge.style.display = 'none';
        });
    })();

    /* ---------- Web Push subscribe button ------------------ */
    // Visitors click any [data-push-subscribe] element to opt in. We
    // fetch the VAPID public key only on click so the page doesn't
    // expose it to non-interested visitors.
    function urlBase64ToUint8(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const raw = window.atob(base64);
        const arr = new Uint8Array(raw.length);
        for (let i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
        return arr;
    }
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-push-subscribe]');
        if (!btn) return;
        e.preventDefault();
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            showFlash('Push notifications aren\u2019t supported in this browser.', 'err');
            return;
        }
        try {
            const keyResp = await fetch('/push/key', { credentials: 'same-origin' }).then((r) => r.json());
            if (!keyResp.enabled) {
                showFlash('Push notifications aren\u2019t configured on this site yet.', 'err');
                return;
            }
            const reg = await navigator.serviceWorker.ready;
            const sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8(keyResp.key),
            });
            const json = sub.toJSON();
            await fetch('/push/subscribe', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ endpoint: json.endpoint, keys: json.keys }),
            });
            showFlash('You\u2019re subscribed to breaking-news alerts.', 'ok');
            btn.setAttribute('disabled', 'disabled');
            btn.textContent = 'Subscribed';
        } catch (err) {
            const msg = String(err && err.message || err || 'unknown');
            if (msg.includes('denied') || msg.includes('NotAllowedError')) {
                showFlash('Notification permission was denied.', 'err');
            } else {
                showFlash('Couldn\u2019t enable push: ' + msg, 'err');
            }
        }
    });

    // Boot
    document.addEventListener('DOMContentLoaded', () => {
        updateThemeToggleLabel();
        loadAdSlots();
    });
})();
