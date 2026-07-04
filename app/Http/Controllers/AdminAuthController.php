<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminAuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login');
    }

    public function login(Request $request)
    {
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

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
