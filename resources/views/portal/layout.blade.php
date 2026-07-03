<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>@yield('title') · Sichere Nachricht · {{ \App\Models\Setting::operator() }}</title>
<link rel="icon" type="image/svg+xml" href="{{ url('/favicon.svg') }}">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
        background: #eef2f6; color: #1f2937; min-height: 100vh;
        display: flex; flex-direction: column; align-items: center;
    }
    .wrap { width: 100%; max-width: 1200px; padding: 24px 16px 48px; }
    header { padding: 8px 4px 20px; display: flex; align-items: center; gap: 10px; }
    .brand-badge { width: 38px; height: 38px; display: flex; align-items: center; }
    .brand { font-weight: 600; color: #1d4e89; font-size: 17px; }
    .brand small { display: block; font-weight: 400; color: #6b7280; font-size: 12.5px; }
    .card {
        background: #fff; border-radius: 12px; padding: 28px;
        box-shadow: 0 1px 3px rgba(16,24,40,.08), 0 8px 24px rgba(16,24,40,.06);
    }
    h1 { font-size: 21px; margin-bottom: 14px; color: #111827; }
    h2 { font-size: 15px; margin: 22px 0 8px; color: #374151; }
    p { line-height: 1.55; margin-bottom: 10px; }
    .muted { color: #6b7280; font-size: 13.5px; }
    .meta { color: #6b7280; font-size: 14px; margin-bottom: 4px; }
    .alert {
        background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c;
        border-radius: 8px; padding: 10px 14px; margin: 14px 0; font-size: 14.5px;
    }
    form { margin-top: 18px; display: flex; gap: 10px; flex-wrap: wrap; }
    input[type=password] {
        flex: 1 1 220px; padding: 11px 14px; font-size: 16px;
        border: 1px solid #d1d5db; border-radius: 8px;
    }
    input[type=password]:focus { outline: 2px solid #1d4e89; border-color: #1d4e89; }
    button {
        padding: 11px 22px; font-size: 15.5px; font-weight: 600; color: #fff;
        background: #1d4e89; border: 0; border-radius: 8px; cursor: pointer;
    }
    button:hover { background: #163d6d; }
    ul.attachments { list-style: none; margin: 6px 0 4px; }
    ul.attachments li {
        padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 8px;
        margin-bottom: 6px; display: flex; justify-content: space-between; gap: 12px; align-items: center;
        background: #f9fafb;
    }
    ul.attachments a { color: #1d4e89; font-weight: 600; text-decoration: none; word-break: break-all; }
    ul.attachments a:hover { text-decoration: underline; }
    iframe.mailbody { width: 100%; height: 65vh; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; }
    pre.mailbody {
        white-space: pre-wrap; word-wrap: break-word; font-family: inherit; font-size: 15px;
        border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; background: #fff;
    }
    footer { padding-top: 18px; text-align: center; color: #9ca3af; font-size: 12.5px; }
</style>
</head>
<body>
<div class="wrap">
    <header>
        <div class="brand-badge">
            <svg viewBox="0 0 64 64" width="38" height="38" role="img" aria-label="Logo">
                <path d="M32 4 L56 12 V30 C56 46 45 55 32 60 C19 55 8 46 8 30 V12 Z" fill="#1d4e89"/>
                <rect x="18" y="24" width="28" height="19" rx="2.5" fill="#ffffff"/>
                <path d="M19.5 26.5 L32 35.5 L44.5 26.5" stroke="#1d4e89" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
        <div class="brand">{{ \App\Models\Setting::operator() }}<small>Sichere Nachrichtenübermittlung</small></div>
    </header>
    <main class="card">
        @yield('content')
    </main>
    <footer>
        Vertrauliche Zustellung über {{ request()->getHost() }} · Verschlüsselt gespeichert, automatische Löschung nach Ablauf<br>
        <a href="{{ url('/impressum') }}" style="color:#6b7280;">Impressum</a> ·
        <a href="{{ url('/datenschutz') }}" style="color:#6b7280;">Datenschutzerklärung</a>
    </footer>
</div>
</body>
</html>
