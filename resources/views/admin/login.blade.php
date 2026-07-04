<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Anmeldung · SecWay</title>
<link rel="icon" type="image/svg+xml" href="{{ url('/favicon.svg') }}">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background: #eef2f6; color: #1f2937;
           min-height: 100vh; display: flex; align-items: center; justify-content: center; }
    .card { background: #fff; border-radius: 12px; padding: 30px; width: 100%; max-width: 380px;
            box-shadow: 0 1px 3px rgba(16,24,40,.08), 0 8px 24px rgba(16,24,40,.06); }
    h1 { font-size: 19px; margin-bottom: 4px; color: #1d4e89; }
    p.sub { color: #6b7280; font-size: 13.5px; margin-bottom: 18px; }
    label { display: block; font-size: 13.5px; font-weight: 600; margin: 12px 0 4px; }
    input { width: 100%; padding: 10px 13px; font-size: 15px; border: 1px solid #d1d5db; border-radius: 8px; }
    input:focus { outline: 2px solid #1d4e89; border-color: #1d4e89; }
    button { width: 100%; margin-top: 18px; padding: 11px; font-size: 15px; font-weight: 600; color: #fff;
             background: #1d4e89; border: 0; border-radius: 8px; cursor: pointer; }
    button:hover { background: #163d6d; }
    .alert { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; border-radius: 8px;
             padding: 9px 13px; margin-bottom: 12px; font-size: 14px; }
</style>
</head>
<body>
<div class="card">
    <div style="display:flex;justify-content:center;margin-bottom:14px;">
        <svg viewBox="0 0 64 64" width="52" height="52" role="img" aria-label="SecWay-Logo">
            <path d="M32 4 L56 12 V30 C56 46 45 55 32 60 C19 55 8 46 8 30 V12 Z" fill="#1d4e89"/>
            <rect x="18" y="24" width="28" height="19" rx="2.5" fill="#ffffff"/>
            <path d="M19.5 26.5 L32 35.5 L44.5 26.5" stroke="#1d4e89" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </div>
    <h1>SecWay-Verwaltung</h1>
    <p class="sub">{{ \App\Models\Setting::operator() }} · Sichere Nachrichtenübermittlung</p>
    @if ($errors->any())
        <div class="alert">{{ $errors->first() }}</div>
    @endif
    <form method="post" action="{{ route('login') }}">
        @csrf
        <label for="username">Benutzername</label>
        <input type="text" id="username" name="username" value="{{ old('username') }}" required autofocus autocomplete="username">
        <label for="password">Kennwort</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
        <button type="submit">Anmelden</button>
    </form>
</div>
</body>
</html>
