<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Anmeldung · Mailgateway · St. Raphael</title>
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
    <h1>Mailgateway-Verwaltung</h1>
    <p class="sub">St. Raphael · Sichere Nachrichtenübermittlung</p>
    @if ($errors->any())
        <div class="alert">{{ $errors->first() }}</div>
    @endif
    <form method="post" action="{{ route('login') }}">
        @csrf
        <label for="email">E-Mail-Adresse</label>
        <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus>
        <label for="password">Kennwort</label>
        <input type="password" id="password" name="password" required>
        <button type="submit">Anmelden</button>
    </form>
</div>
</body>
</html>
