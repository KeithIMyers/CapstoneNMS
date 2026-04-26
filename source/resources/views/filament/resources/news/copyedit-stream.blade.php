{{-- Streaming copyedit modal. Opens an EventSource against the
     copyedit SSE route, paints chunks into <pre id="copyedit-output">
     as they arrive, and lets the editor Apply the result to the
     article body via a CSRF-signed form post. --}}

@php
    $streamId = 'cestream-'.\Illuminate\Support\Str::random(8);
@endphp

<div class="fi-section-content" data-stream-root="{{ $streamId }}">
    <p style="margin:0 0 0.75rem 0;color:#64748b;font-size:0.88rem;line-height:1.5;">
        Click <strong>Start</strong> to stream a copyedit. Watch text appear, then click
        <strong>Apply</strong> to overwrite the article body with the result.
        The original is preserved in the activity log.
    </p>

    <div style="display:flex;gap:0.5rem;align-items:center;margin-bottom:0.75rem;">
        <button type="button"
                id="{{ $streamId }}-start"
                class="fi-btn fi-btn-color-primary"
                style="padding:0.4rem 0.85rem;border-radius:6px;background:#0ea5e9;color:#fff;border:0;cursor:pointer;font-size:0.85rem;font-weight:600;">
            Start
        </button>
        <button type="button"
                id="{{ $streamId }}-stop"
                disabled
                class="fi-btn"
                style="padding:0.4rem 0.85rem;border-radius:6px;background:transparent;color:#475569;border:1px solid #cbd5e1;cursor:pointer;font-size:0.85rem;">
            Stop
        </button>
        <span id="{{ $streamId }}-status" style="margin-left:auto;color:#64748b;font-size:0.78rem;">Idle</span>
    </div>

    <pre id="{{ $streamId }}-output"
         style="background:#0f172a;color:#e2e8f0;padding:1rem;border-radius:6px;font-family:ui-monospace,Menlo,Monaco,monospace;font-size:0.85rem;line-height:1.5;max-height:60vh;overflow:auto;white-space:pre-wrap;margin:0;"></pre>

    <form method="POST"
          action="{{ $applyUrl }}"
          id="{{ $streamId }}-apply-form"
          style="margin-top:0.75rem;display:flex;justify-content:flex-end;gap:0.5rem;">
        @csrf
        <input type="hidden" name="body" id="{{ $streamId }}-body">
        <button type="submit"
                id="{{ $streamId }}-apply"
                disabled
                class="fi-btn"
                style="padding:0.4rem 0.85rem;border-radius:6px;background:#16a34a;color:#fff;border:0;cursor:pointer;font-size:0.85rem;font-weight:600;opacity:0.5;">
            Apply to article body
        </button>
    </form>
</div>

<script>
(() => {
    const id = @json($streamId);
    const streamUrl = @json($streamUrl);
    const startBtn  = document.getElementById(id + '-start');
    const stopBtn   = document.getElementById(id + '-stop');
    const applyBtn  = document.getElementById(id + '-apply');
    const bodyInput = document.getElementById(id + '-body');
    const out       = document.getElementById(id + '-output');
    const status    = document.getElementById(id + '-status');

    let es = null;
    let acc = '';

    const setRunning = (running) => {
        startBtn.disabled = running;
        stopBtn.disabled  = !running;
        startBtn.style.opacity = running ? '0.5' : '1';
        stopBtn.style.opacity  = running ? '1' : '0.5';
    };

    const enableApply = () => {
        applyBtn.disabled = false;
        applyBtn.style.opacity = '1';
        bodyInput.value = acc;
    };

    startBtn.addEventListener('click', () => {
        acc = '';
        out.textContent = '';
        applyBtn.disabled = true;
        applyBtn.style.opacity = '0.5';
        status.textContent = 'Connecting…';
        setRunning(true);

        // EventSource sends cookies, so the controller's auth check works
        // off the existing admin session.
        es = new EventSource(streamUrl, { withCredentials: true });

        es.onopen = () => { status.textContent = 'Streaming…'; };

        es.onmessage = (e) => {
            try {
                const chunk = JSON.parse(e.data);
                acc += chunk;
                out.textContent = acc;
                out.scrollTop = out.scrollHeight;
            } catch (err) {
                acc += e.data;
                out.textContent = acc;
            }
        };

        es.addEventListener('end', () => {
            es.close();
            es = null;
            setRunning(false);
            status.textContent = 'Done · ' + acc.length.toLocaleString() + ' chars';
            if (acc.length > 0) enableApply();
        });

        es.addEventListener('error', (e) => {
            // Server sent a structured error event.
            let msg = 'Stream error';
            try { msg = JSON.parse(e.data); } catch {}
            status.textContent = '✗ ' + msg;
            if (es) { es.close(); es = null; }
            setRunning(false);
        });

        es.onerror = () => {
            // Network-level failure (CORS, 5xx, dropped connection).
            if (es && es.readyState === EventSource.CLOSED) {
                status.textContent = 'Connection closed';
            } else {
                status.textContent = 'Connection error';
            }
            if (es) { es.close(); es = null; }
            setRunning(false);
        };
    });

    stopBtn.addEventListener('click', () => {
        if (es) { es.close(); es = null; }
        setRunning(false);
        status.textContent = 'Stopped at ' + acc.length.toLocaleString() + ' chars';
        if (acc.length > 0) enableApply();
    });
})();
</script>
