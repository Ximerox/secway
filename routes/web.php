<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\SignatureImageController;
use App\Http\Controllers\SignatureQrController;
use App\Livewire\Admin\Certificates;
use App\Livewire\Admin\EntraUsers;
use App\Livewire\Admin\SignatureEdit;
use App\Livewire\Admin\Signatures;
use App\Livewire\Admin\Log;
use App\Livewire\Admin\Messages;
use App\Livewire\Admin\Queue as AdminQueue;
use App\Livewire\Admin\Settings;
use App\Livewire\Admin\Stats;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('portal.error', [
        'title' => 'Sichere Nachrichtenübermittlung',
        'text' => 'Über dieses Portal stellt '.\App\Models\Setting::operator().' vertrauliche Nachrichten zu. Den Zugriffslink und das zugehörige Kennwort erhalten Empfänger per E-Mail.',
    ]);
});

Route::view('/impressum', 'portal.impressum')->name('impressum');
Route::view('/datenschutz', 'portal.datenschutz')->name('datenschutz');

Route::get('/m/{token}', [PortalController::class, 'show']);
Route::post('/m/{token}', [PortalController::class, 'unlock'])->middleware('throttle:10,1');
Route::get('/m/{token}/download/{attachment}', [PortalController::class, 'download'])->middleware('throttle:60,1');

Route::prefix('admin')->group(function () {
    Route::get('login', [AdminAuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AdminAuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

    Route::middleware('auth')->group(function () {
        // Übersicht ist in Statistik + Protokoll aufgegangen; Routenname bleibt
        // erhalten, damit bestehende Redirects (Login) weiter funktionieren.
        Route::get('/', fn () => redirect()->route('admin.stats'))->name('admin.dashboard');
        Route::get('/nachrichten', Messages::class)->name('admin.messages');
        Route::get('/warteschlange', AdminQueue::class)->name('admin.queue');
        Route::get('/protokoll', Log::class)->name('admin.log');
        Route::get('/statistik', Stats::class)->name('admin.stats');
        Route::get('/zertifikate', Certificates::class)->name('admin.certs');
        Route::get('/signaturen', Signatures::class)->name('admin.signatures');
        Route::get('/signaturen/neu', SignatureEdit::class)->name('admin.signatures.new');
        Route::get('/signaturen/{template}/bearbeiten', SignatureEdit::class)->name('admin.signatures.edit');
        Route::get('/sig-img/{image}', [SignatureImageController::class, 'show'])->name('admin.sigimg');
        Route::get('/sig-qr/{qr}', [SignatureQrController::class, 'show'])->name('admin.sigqr');
        Route::get('/benutzer', EntraUsers::class)->name('admin.users');
        Route::get('/einstellungen', Settings::class)->name('admin.settings');
    });
});
