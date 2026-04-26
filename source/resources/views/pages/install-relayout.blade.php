<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CapstoneNMS — shared-hosting layout</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Inter, system-ui, sans-serif;
               color: #0f172a; background: #f8fafc; margin: 0; padding: 2rem 1rem; line-height: 1.55; }
        .wrap { max-width: 720px; margin: 0 auto; }
        h1 { margin: 0 0 0.5rem 0; font-size: 1.6rem; font-weight: 800; letter-spacing: -0.02em; }
        p.lede { margin: 0 0 1.5rem 0; color: #64748b; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 0.75rem; padding: 1.5rem; margin-bottom: 1.5rem; }
        .card.err { background: #fef2f2; border-color: #fecaca; color: #7f1d1d; }
        h2 { margin: 0 0 0.85rem 0; font-size: 1.05rem; font-weight: 700; }
        ul { margin: 0 0 0 1.25rem; padding: 0; }
        ul li { margin-bottom: 0.4rem; }
        code { background: #f1f5f9; padding: 0.1rem 0.4rem; border-radius: 0.25rem; font-size: 0.88em; }
        button.primary { background: #0ea5e9; color: #fff; border: none; padding: 0.75rem 1.25rem;
                         border-radius: 0.4rem; font-size: 1rem; font-weight: 700; cursor: pointer; }
        button.primary:hover { background: #0284c7; }
        details { margin-top: 1rem; font-size: 0.88rem; color: #475569; }
        details summary { cursor: pointer; font-weight: 600; color: #0f172a; }
    </style>
</head>
<body>
<div class="wrap">

    <h1>CapstoneNMS — shared-hosting layout</h1>
    <p class="lede">It looks like you extracted the dist directly into your web server's doc root. CapstoneNMS can rearrange itself to work safely from here.</p>

    @if (! empty($errors ?? []))
        <div class="card err">
            <strong>The relayout couldn't complete:</strong>
            <ul style="margin-top: 0.5rem;">
                @foreach ($errors as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
            <p style="margin-top: 1rem;">{{ ($moved ?? 0) }} entries were moved before the failure. Inspect <code>_app/</code> to see what landed; you can either finish the layout manually or remove <code>_app/</code> and try again.</p>
        </div>
    @endif

    <div class="card">
        <h2>What's about to happen</h2>
        <p>The whole CapstoneNMS application tree (<code>app/</code>, <code>bootstrap/</code>, <code>config/</code>, <code>vendor/</code>, <code>storage/</code>, etc.) will be moved into <code>_app/</code> in your current directory.</p>
        <p>The contents of <code>public/</code> (<code>index.php</code>, <code>.htaccess</code>, <code>site/</code>, etc.) will be flattened up one level so they sit at your doc root — which is what your web server already serves.</p>
        <p>A <code>.htaccess</code> in <code>_app/</code> will deny all direct web access so nobody can hit <code>/_app/.env</code> from outside.</p>
        <p>Future updates from <strong>Settings → Updates</strong> will respect this layout automatically.</p>
    </div>

    <form method="post" action="/install/relayout">
        @csrf
        <button class="primary" type="submit">Rearrange and continue</button>
    </form>

    <details>
        <summary>Skip this — I want to use the standard layout</summary>
        <p style="margin-top: 0.85rem;">Move the whole extracted directory <em>up</em> one level (so the Laravel app sits next to <code>public_html/</code>, not inside it), then point <code>public_html/</code> at the <code>public/</code> subdirectory of the moved tree. On hosts that support shell access:</p>
        <pre style="background:#1e293b;color:#f1f5f9;padding:0.85rem;border-radius:0.4rem;overflow:auto;font-size:0.85rem;">cd ~
mv public_html capstone-nms          # the dist tree you just extracted
mv capstone-nms.bak public_html      # if your host already has one
ln -s capstone-nms/public public_html</pre>
        <p>Then visit <code>/install</code> again. The standard-layout wizard will run.</p>
    </details>

</div>
</body>
</html>
