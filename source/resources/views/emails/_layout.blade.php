<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>@yield('title', getcong('site_name') ?: config('app.name'))</title>
<style>
    body { margin: 0; padding: 0; background: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #0f172a; }
    .wrap { width: 100%; max-width: 560px; margin: 0 auto; }
    .card { background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(15,23,42,0.08); }
    .brand { padding: 24px; text-align: center; background: #0ea5e9; color: #ffffff; }
    .brand a { color: #ffffff; text-decoration: none; font-weight: 700; font-size: 18px; letter-spacing: .02em; }
    .brand img { max-height: 32px; }
    .body { padding: 28px 24px; }
    .body h1 { font-size: 20px; margin: 0 0 12px; font-weight: 600; }
    .body p { font-size: 15px; line-height: 1.55; margin: 0 0 14px; color: #1e293b; }
    .btn { display: inline-block; background: #0ea5e9; color: #ffffff !important; text-decoration: none; padding: 12px 22px; border-radius: 6px; font-weight: 600; font-size: 15px; margin: 8px 0; }
    .muted { color: #64748b; font-size: 13px; }
    .foot { padding: 18px 24px; text-align: center; color: #64748b; font-size: 12px; }
    .foot a { color: #64748b; text-decoration: underline; }
    code, pre { background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; }
</style>
</head>
<body>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f1f5f9; padding: 32px 12px;">
        <tr>
            <td align="center">
                <div class="wrap">
                    <div class="card">
                        <div class="brand">
                            @if (getcong('site_logo'))
                                <a href="{{ url('/') }}">
                                    <img src="{{ Storage::disk(getcong('site_storage'))->url(getcong('site_logo')) }}" alt="{{ getcong('site_name') }}">
                                </a>
                            @else
                                <a href="{{ url('/') }}">{{ getcong('site_name') ?: config('app.name') }}</a>
                            @endif
                        </div>
                        <div class="body">
                            @yield('content')
                        </div>
                    </div>
                    <div class="foot">
                        © {{ date('Y') }} {{ getcong('site_name') ?: config('app.name') }}.
                        <br>
                        <a href="{{ url('/') }}">{{ parse_url(config('app.url'), PHP_URL_HOST) }}</a>
                    </div>
                </div>
            </td>
        </tr>
    </table>
</body>
</html>
