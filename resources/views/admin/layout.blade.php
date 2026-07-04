<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $title ?? 'Verwaltung' }} · SecWay · {{ \App\Models\Setting::operator() }}</title>
<link rel="icon" type="image/svg+xml" href="{{ url('/favicon.svg') }}">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background: #eef2f6; color: #1f2937; min-height: 100vh; }
    nav { background: #1d4e89; color: #fff; display: flex; align-items: center; gap: 4px; padding: 0 20px; height: 54px; }
    nav .brand { font-weight: 700; margin-right: 28px; font-size: 15.5px; }
    nav a { color: #dbe7f5; text-decoration: none; padding: 17px 14px; font-size: 14.5px; font-weight: 600; }
    nav a:hover, nav a.active { color: #fff; background: #163d6d; }
    nav form { margin-left: auto; }
    nav button { background: none; border: 0; color: #dbe7f5; font: inherit; font-weight: 600; cursor: pointer; padding: 10px 4px; }
    nav button:hover { color: #fff; }
    main { max-width: 1200px; margin: 0 auto; padding: 26px 16px 60px; }
    h1 { font-size: 21px; margin-bottom: 18px; }
    h2 { font-size: 16px; margin: 26px 0 10px; }
    .card { background: #fff; border-radius: 12px; padding: 22px; box-shadow: 0 1px 3px rgba(16,24,40,.08); margin-bottom: 18px; }
    .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 20px; }
    .stat { background: #fff; border-radius: 12px; padding: 16px 18px; box-shadow: 0 1px 3px rgba(16,24,40,.08); }
    .stat .num { font-size: 26px; font-weight: 700; color: #1d4e89; }
    .stat .lbl { font-size: 13px; color: #6b7280; margin-top: 2px; }
    table { width: 100%; border-collapse: collapse; font-size: 14px; }
    th { text-align: left; color: #6b7280; font-size: 12.5px; text-transform: uppercase; letter-spacing: .03em; padding: 8px 10px; border-bottom: 2px solid #e5e7eb; }
    td { padding: 9px 10px; border-bottom: 1px solid #f0f1f3; vertical-align: top; }
    tr:hover td { background: #f9fafb; }
    .badge { display: inline-block; padding: 2px 9px; border-radius: 99px; font-size: 12px; font-weight: 600; }
    .badge.ok { background: #ecfdf5; color: #047857; }
    .badge.warn { background: #fffbeb; color: #b45309; }
    .badge.off { background: #f3f4f6; color: #6b7280; }
    .badge.err { background: #fef2f2; color: #b91c1c; }
    .alert { border-radius: 8px; padding: 10px 14px; margin-bottom: 14px; font-size: 14.5px; }
    .alert.ok { background: #ecfdf5; border: 1px solid #a7f3d0; color: #047857; }
    .alert.err { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
    label { display: block; font-size: 13.5px; font-weight: 600; margin: 12px 0 4px; }
    input[type=text], input[type=password], input[type=email], input[type=date], input[type=number], select { width: 100%; padding: 9px 12px; font-size: 14.5px; border: 1px solid #d1d5db; border-radius: 8px; background: #fff; }
    input[type=file] { font-size: 14px; padding: 6px 0; }
    input:focus, select:focus { outline: 2px solid #1d4e89; border-color: #1d4e89; }
    .btn { padding: 9px 18px; font-size: 14.5px; font-weight: 600; color: #fff; background: #1d4e89; border: 0; border-radius: 8px; cursor: pointer; margin-top: 14px; }
    .btn:hover { background: #163d6d; }
    .btn.small { padding: 4px 10px; font-size: 12.5px; margin: 0; }
    .btn.ghost { background: #fff; color: #1d4e89; border: 1px solid #c7d4e6; }
    .btn.danger { background: #fff; color: #b91c1c; border: 1px solid #fecaca; }
    .muted { color: #6b7280; font-size: 13px; }
    .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 18px; }
    .error { color: #b91c1c; font-size: 13px; margin-top: 4px; }
    .mono { font-family: Consolas, Menlo, monospace; font-size: 12.5px; }
</style>
</head>
<body>
<nav>
    <span class="brand" style="display:flex;align-items:center;gap:9px;">
        <svg viewBox="0 0 64 64" width="26" height="26" aria-hidden="true">
            <path d="M32 4 L56 12 V30 C56 46 45 55 32 60 C19 55 8 46 8 30 V12 Z" fill="#ffffff"/>
            <rect x="18" y="24" width="28" height="19" rx="2.5" fill="#1d4e89"/>
            <path d="M19.5 26.5 L32 35.5 L44.5 26.5" stroke="#ffffff" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        SecWay
    </span>
    <a href="{{ route('admin.stats') }}" @class(['active' => request()->routeIs('admin.stats')])>Statistik</a>
    <a href="{{ route('admin.messages') }}" @class(['active' => request()->routeIs('admin.messages')])>Nachrichten</a>
    <a href="{{ route('admin.queue') }}" @class(['active' => request()->routeIs('admin.queue')])>Warteschlange</a>
    <a href="{{ route('admin.log') }}" @class(['active' => request()->routeIs('admin.log')])>Protokoll</a>
    <a href="{{ route('admin.certs') }}" @class(['active' => request()->routeIs('admin.certs')])>Zertifikate</a>
    <a href="{{ route('admin.signatures') }}" @class(['active' => request()->routeIs('admin.signatures')])>Signaturen</a>
    <a href="{{ route('admin.users') }}" @class(['active' => request()->routeIs('admin.users')])>Benutzer</a>
    <a href="{{ route('admin.settings') }}" @class(['active' => request()->routeIs('admin.settings')])>Einstellungen</a>
    <form method="post" action="{{ route('admin.logout') }}">
        @csrf
        <button type="submit">Abmelden ({{ auth()->user()?->name }})</button>
    </form>
</nav>
<main>
    {{ $slot }}
</main>
</body>
</html>
