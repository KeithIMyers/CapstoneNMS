<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install CapstoneNMS</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Inter, system-ui, sans-serif;
            color: #0f172a; background: #f8fafc; margin: 0; padding: 2rem 1rem;
            line-height: 1.5;
        }
        .wrap { max-width: 760px; margin: 0 auto; }
        h1 { margin: 0 0 0.5rem 0; font-size: 1.6rem; font-weight: 800; letter-spacing: -0.02em; }
        h2 { margin: 1.5rem 0 0.5rem 0; font-size: 1.1rem; font-weight: 700; }
        p.lede { margin: 0 0 1.5rem 0; color: #64748b; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 0.75rem; padding: 1.5rem; margin-bottom: 1.5rem; }
        .card.err { background: #fef2f2; border-color: #fecaca; }
        .card.warn { background: #fffbeb; border-color: #fde68a; }
        .card.ok { background: #f0fdf4; border-color: #bbf7d0; }
        table.checks { width: 100%; border-collapse: collapse; }
        table.checks td { padding: 0.4rem 0.5rem; border-bottom: 1px solid #f1f5f9; font-size: 0.92rem; }
        table.checks td:last-child { text-align: right; font-family: ui-monospace, monospace; color: #475569; }
        .badge { display: inline-block; min-width: 1.5rem; padding: 0.1rem 0.45rem; border-radius: 0.35rem; font-size: 0.75rem; font-weight: 700; text-align: center; }
        .badge.ok { background: #dcfce7; color: #166534; }
        .badge.fail { background: #fee2e2; color: #991b1b; }
        .badge.warn { background: #fef3c7; color: #92400e; }
        label { display: block; margin: 0.85rem 0 0.25rem 0; font-size: 0.85rem; font-weight: 600; color: #334155; }
        input[type=text], input[type=email], input[type=url], input[type=password], input[type=number], select, textarea, input[type=file] {
            width: 100%; padding: 0.55rem 0.75rem; border: 1px solid #cbd5e1; border-radius: 0.4rem;
            font-size: 0.95rem; font-family: inherit; background: #fff;
        }
        input:focus, select:focus, textarea:focus { outline: none; border-color: #0ea5e9; box-shadow: 0 0 0 3px rgba(14,165,233,0.18); }
        small { color: #64748b; font-size: 0.8rem; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
        button.primary {
            background: #0ea5e9; color: #fff; border: none; padding: 0.75rem 1.25rem;
            border-radius: 0.4rem; font-size: 1rem; font-weight: 700; cursor: pointer;
        }
        button.primary:hover { background: #0284c7; }
        button.primary:disabled { background: #94a3b8; cursor: not-allowed; }
        ul.errs { margin: 0 0 0 1.25rem; padding: 0; }
        ul.errs li { margin-bottom: 0.25rem; }
        details summary { cursor: pointer; font-weight: 600; }
    </style>
</head>
<body>
<div class="wrap">

    <h1>Install CapstoneNMS</h1>
    <p class="lede">Welcome — let's get your site running. This wizard checks your server, configures the database, creates an admin account, and activates your license. About 60 seconds.</p>

    @if (! empty($errors))
        <div class="card err">
            <strong>The previous attempt couldn't complete:</strong>
            <ul class="errs">
                @foreach ($errors as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card">
        <h2>Server requirements</h2>
        <p style="font-size:0.9rem;color:#64748b;margin-top:0;">Every line must be green before installation can proceed. Yellow rows are recommendations; install will still work but performance may suffer.</p>
        <table class="checks">
            <tbody>
                @foreach ($preflight as $c)
                    <tr>
                        <td>
                            <span class="badge {{ $c['ok'] ? 'ok' : ($c['severity'] === 'warn' ? 'warn' : 'fail') }}">
                                {{ $c['ok'] ? '✓' : ($c['severity'] === 'warn' ? '!' : '✕') }}
                            </span>
                            {{ $c['label'] }}
                        </td>
                        <td>{{ $c['value'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if (! empty($blockingFailures))
        <div class="card err">
            <strong>Cannot install yet.</strong> Resolve the failed requirements above, then refresh this page. Most can be fixed by your hosting provider; PHP extensions are typically a one-line php.ini edit or a checkbox in the hosting panel.
        </div>
    @else
        <form method="post" action="/install" enctype="multipart/form-data">
            @csrf

            <div class="card">
                <h2>1. Site basics</h2>
                <label for="site_name">Site name</label>
                <input id="site_name" name="site_name" type="text" required maxlength="120"
                       value="{{ $old['site_name'] ?? '' }}" placeholder="My News Site">
                <small>Shown in the header, the browser title bar, and email subject lines. Editable later in Site Settings.</small>

                <label for="site_url">Site URL</label>
                <input id="site_url" name="site_url" type="url" required maxlength="255"
                       value="{{ $old['site_url'] ?? (request()->getSchemeAndHttpHost()) }}" placeholder="https://example.com">
                <small>The fully-qualified URL readers will visit, including https://. The license verifier cross-checks this against your licensed domains.</small>
            </div>

            <div class="card">
                <h2>2. Database</h2>
                <label for="db_driver">Driver</label>
                <select id="db_driver" name="db_driver">
                    <option value="mysql" {{ ($old['db_driver'] ?? 'mysql') === 'mysql' ? 'selected' : '' }}>MySQL / MariaDB</option>
                    <option value="sqlite" {{ ($old['db_driver'] ?? '') === 'sqlite' ? 'selected' : '' }}>SQLite (single-server only)</option>
                </select>

                <div class="row">
                    <div>
                        <label for="db_host">Host</label>
                        <input id="db_host" name="db_host" type="text" maxlength="120" value="{{ $old['db_host'] ?? '127.0.0.1' }}">
                    </div>
                    <div>
                        <label for="db_port">Port</label>
                        <input id="db_port" name="db_port" type="number" min="1" max="65535" value="{{ $old['db_port'] ?? 3306 }}">
                    </div>
                </div>

                <label for="db_database">Database name</label>
                <input id="db_database" name="db_database" type="text" required maxlength="120" value="{{ $old['db_database'] ?? '' }}">

                <div class="row">
                    <div>
                        <label for="db_username">Username</label>
                        <input id="db_username" name="db_username" type="text" maxlength="120" value="{{ $old['db_username'] ?? '' }}">
                    </div>
                    <div>
                        <label for="db_password">Password</label>
                        <input id="db_password" name="db_password" type="password" maxlength="255" autocomplete="off">
                    </div>
                </div>
                <small>The installer connects to verify these before writing your <code>.env</code>. Failed connections roll back without changing anything.</small>
            </div>

            <div class="card">
                <h2>3. First admin user</h2>
                <p style="font-size:0.9rem;color:#64748b;margin-top:0;">This is who you'll sign in with at <code>/admin</code>. You can add more admins later (within your license tier's cap).</p>
                <label for="admin_name">Name</label>
                <input id="admin_name" name="admin_name" type="text" required maxlength="120" value="{{ $old['admin_name'] ?? '' }}">

                <label for="admin_email">Email</label>
                <input id="admin_email" name="admin_email" type="email" required maxlength="200" value="{{ $old['admin_email'] ?? '' }}">

                <label for="admin_password">Password (min 12 characters)</label>
                <input id="admin_password" name="admin_password" type="password" required minlength="12" autocomplete="new-password">
            </div>

            <div class="card">
                <h2>4. License</h2>
                <p style="font-size:0.9rem;color:#64748b;margin-top:0;">Drop the <code>.dat</code> file we emailed you, or paste the matching <code>.txt</code> blob below. Optional during install — the panel will lock and prompt for a license on first sign-in if you skip.</p>

                <label for="license_file">License file</label>
                <input id="license_file" name="license_file" type="file" accept=".dat,.txt,application/octet-stream,text/plain">

                <label for="license_paste">…or paste the license blob</label>
                <textarea id="license_paste" name="license_paste" rows="3" placeholder="eyJ...payload....sig">{{ $old['license_paste'] ?? '' }}</textarea>
            </div>

            <div class="card ok">
                <h2 style="margin-top:0;">Ready</h2>
                <p style="margin:0 0 1rem 0;font-size:0.9rem;color:#0f5132;">The installer will write your <code>.env</code>, run database migrations, create the admin user, and save your license. About ten seconds on a typical host.</p>
                <button class="primary" type="submit">Install CapstoneNMS</button>
            </div>
        </form>
    @endif

    <details style="margin-top:1rem;font-size:0.85rem;color:#64748b;">
        <summary>Air-gapped / CLI install</summary>
        <p>If you can't reach this page (locked-down hosts, command-line preference), use the artisan installer:</p>
        <pre style="background:#1e293b;color:#f1f5f9;padding:0.85rem;border-radius:0.4rem;overflow:auto;">php artisan capstone:install --interactive</pre>
        <p>The CLI takes the same inputs and runs the same preflight + apply steps.</p>
    </details>

</div>
</body>
</html>
