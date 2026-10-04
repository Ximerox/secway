<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AdminAuthController extends Controller
{
    /** Entra-Anmeldung konfiguriert? Dann ist die lokale Kennwort-Anmeldung aus. */
    public static function entraEnabled(): bool
    {
        $e = config('mailgateway.entra_login');

        return filled($e['tenant_id'] ?? null) && filled($e['client_id'] ?? null) && filled($e['client_secret'] ?? null);
    }

    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login', ['entra' => self::entraEnabled()]);
    }

    public function login(Request $request)
    {
        abort_if(self::entraEnabled(), 404);

        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials, remember: true)) {
            $request->session()->regenerate();
            AuditEvent::log('admin_login', ip: $request->ip(), details: ['username' => $credentials['username']]);

            return redirect()->intended(route('admin.dashboard'));
        }

        AuditEvent::log('admin_login_failed', ip: $request->ip(), details: ['username' => $credentials['username']]);

        return back()->withErrors(['username' => 'Anmeldung fehlgeschlagen.'])->onlyInput('username');
    }

    /** Weiterleitung zu Entra (Authorization Code + PKCE). */
    public function entraRedirect(Request $request)
    {
        abort_unless(self::entraEnabled(), 404);
        $e = config('mailgateway.entra_login');

        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(64);
        $request->session()->put('entra_login', [
            'state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'expires' => time() + 600,
        ]);

        $query = http_build_query([
            'client_id' => $e['client_id'],
            'response_type' => 'code',
            'response_mode' => 'query',
            'redirect_uri' => route('admin.entra.callback'),
            'scope' => 'openid profile email',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);

        return redirect()->away("https://login.microsoftonline.com/{$e['tenant_id']}/oauth2/v2.0/authorize?{$query}");
    }

    public function entraCallback(Request $request)
    {
        abort_unless(self::entraEnabled(), 404);

        try {
            $claims = $this->entraClaims($request);
        } catch (Throwable $ex) {
            AuditEvent::log('admin_sso_denied', ip: $request->ip(), details: ['reason' => Str::limit($ex->getMessage(), 200)]);

            return redirect()->route('login')->withErrors(['entra' => 'Anmeldung fehlgeschlagen: '.$ex->getMessage()]);
        }

        $upn = Str::lower($claims['preferred_username'] ?? $claims['email'] ?? '');
        $user = User::firstWhere('email', $upn) ?? User::create([
            'name' => $claims['name'] ?? $upn,
            'username' => $this->freeUsername(Str::before($upn, '@')),
            'email' => $upn,
            'password' => Str::random(64), // wird nie benutzt, Spalte ist NOT NULL
        ]);

        Auth::login($user);
        $request->session()->regenerate();
        AuditEvent::log('admin_login', ip: $request->ip(), details: ['username' => $user->username, 'method' => 'entra', 'upn' => $upn]);

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Code gegen Tokens tauschen und das ID-Token prüfen. Das ID-Token kommt per
     * TLS direkt vom Token-Endpunkt; nach OIDC Core 3.1.3.7 ersetzt das die
     * Signaturprüfung. Aussteller, Audience, Nonce, Ablauf und Rolle werden geprüft.
     */
    private function entraClaims(Request $request): array
    {
        $e = config('mailgateway.entra_login');
        $pending = $request->session()->pull('entra_login');

        if ($request->filled('error')) {
            throw new RuntimeException((string) ($request->input('error_description') ?: $request->input('error')));
        }
        if (! $pending || $pending['expires'] < time() || ! hash_equals($pending['state'], (string) $request->input('state'))) {
            throw new RuntimeException('Anmeldung abgelaufen oder ungültig, bitte neu starten.');
        }

        $resp = Http::asForm()->timeout(15)->post("https://login.microsoftonline.com/{$e['tenant_id']}/oauth2/v2.0/token", [
            'client_id' => $e['client_id'],
            'client_secret' => $e['client_secret'],
            'grant_type' => 'authorization_code',
            'code' => (string) $request->input('code'),
            'redirect_uri' => route('admin.entra.callback'),
            'code_verifier' => $pending['verifier'],
        ]);
        if (! $resp->ok() || ! $resp->json('id_token')) {
            throw new RuntimeException('Token-Abruf fehlgeschlagen ('.($resp->json('error') ?? $resp->status()).').');
        }

        $parts = explode('.', (string) $resp->json('id_token'));
        $claims = json_decode((string) base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true);
        if (! is_array($claims)) {
            throw new RuntimeException('ID-Token unlesbar.');
        }
        if (($claims['iss'] ?? null) !== "https://login.microsoftonline.com/{$e['tenant_id']}/v2.0"
            || ($claims['tid'] ?? null) !== $e['tenant_id']) {
            throw new RuntimeException('Falscher Aussteller.');
        }
        if (($claims['aud'] ?? null) !== $e['client_id'] || ($claims['nonce'] ?? null) !== $pending['nonce']
            || ($claims['exp'] ?? 0) < time()) {
            throw new RuntimeException('Token ungültig.');
        }
        if (! array_intersect($claims['roles'] ?? [], $e['allowed_roles'])) {
            throw new RuntimeException('Keine Berechtigung für '.($claims['preferred_username'] ?? '?').' (App-Rolle fehlt).');
        }
        if (blank($claims['preferred_username'] ?? $claims['email'] ?? null)) {
            throw new RuntimeException('Kein Benutzername im Token.');
        }

        return $claims;
    }

    private function freeUsername(string $base): string
    {
        $base = preg_replace('/[^A-Za-z0-9._-]/', '', $base) ?: 'entra';
        $name = $base;
        for ($i = 2; User::where('username', $name)->exists(); $i++) {
            $name = $base.$i;
        }

        return $name;
    }
}
