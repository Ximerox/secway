<?php

namespace App\Livewire\Admin;

use App\Models\AuditEvent;
use App\Models\EntraUser;
use App\Models\Setting;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('admin.layout')]
#[Title('Benutzer')]
class EntraUsers extends Component
{
    use WithPagination;

    #[Url]
    public string $q = '';

    public string $sync_groups = '';

    public bool $sync_enabled_only = true;

    public string $sync_exclude = '';

    public function mount(): void
    {
        $this->sync_groups = (string) Setting::get('entra_sync_groups', '');
        $this->sync_enabled_only = Setting::getBool('entra_sync_enabled_only', true);
        $this->sync_exclude = (string) Setting::get('entra_sync_exclude', 'HealthMailbox*, DiscoverySearchMailbox*');
    }

    public function updating($name): void
    {
        if ($name === 'q') {
            $this->resetPage();
        }
    }

    public function saveFilter(): void
    {
        $this->validate([
            'sync_groups' => ['nullable', 'regex:/^\s*([0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}\s*(,\s*[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}\s*)*)?$/i'],
            'sync_exclude' => 'nullable|string|max:500',
        ], [
            'sync_groups.regex' => 'Bitte Gruppen als Objekt-IDs (GUID) angeben, kommagetrennt — zu finden in Entra unter Gruppen → Übersicht.',
        ]);

        Setting::set('entra_sync_groups', trim($this->sync_groups));
        Setting::set('entra_sync_enabled_only', $this->sync_enabled_only);
        Setting::set('entra_sync_exclude', trim($this->sync_exclude));

        AuditEvent::log('settings_changed', ip: request()->ip(), details: [
            'entra_sync_groups' => trim($this->sync_groups),
            'entra_sync_enabled_only' => $this->sync_enabled_only,
            'entra_sync_exclude' => trim($this->sync_exclude),
        ]);

        // Filter direkt anwenden
        $this->sync();
    }

    public function sync(): void
    {
        $code = Artisan::call('entra:sync');
        $output = trim(Artisan::output());

        if ($code === 0) {
            session()->flash('ok', $output !== '' ? $output : 'Synchronisation abgeschlossen.');
        } else {
            session()->flash('err', $output !== '' ? $output : 'Synchronisation fehlgeschlagen — Details im Laravel-Log.');
        }
    }

    /** Pro-Benutzer-Schalter für die „Sicher versenden?"-Rückfrage des Add-ins. */
    public function toggleClassify(int $id): void
    {
        $u = EntraUser::findOrFail($id);
        $u->classify_enabled = ! $u->classify_enabled;
        $u->save();

        AuditEvent::log('settings_changed', ip: request()->ip(), details: [
            'user_classify_enabled' => $u->classify_enabled,
            'user' => $u->mail ?: $u->upn,
        ]);
        session()->flash('ok', 'Sende-Rückfrage für '.($u->display_name ?: $u->mail).' '.($u->classify_enabled ? 'aktiviert' : 'deaktiviert').'.');
    }

    /** Pro-Benutzer-Schalter: Signaturblock im Add-in (an) oder erst im Gateway (aus). */
    public function toggleSignatureClient(int $id): void
    {
        $u = EntraUser::findOrFail($id);
        $u->signature_client_enabled = ! $u->signature_client_enabled;
        $u->save();

        AuditEvent::log('settings_changed', ip: request()->ip(), details: [
            'user_signature_client_enabled' => $u->signature_client_enabled,
            'user' => $u->mail ?: $u->upn,
        ]);
        session()->flash('ok', 'Signatur für '.($u->display_name ?: $u->mail).': '
            .($u->signature_client_enabled ? 'im Client (Add-in, beim Schreiben sichtbar)' : 'erst im Gateway (Server)').'.');
    }

    public function render()
    {
        $query = EntraUser::orderBy('display_name');

        if ($this->q !== '') {
            $like = '%'.$this->q.'%';
            $query->where(fn ($w) => $w
                ->where('display_name', 'like', $like)
                ->orWhere('mail', 'like', $like)
                ->orWhere('department', 'like', $like)
                ->orWhere('job_title', 'like', $like));
        }

        return view('livewire.admin.entra-users', [
            'users' => $query->paginate(50),
            'total' => EntraUser::count(),
            'lastSync' => Setting::get('entra_last_sync'),
            'missingTitle' => EntraUser::whereNull('job_title')->count(),
            'missingPhone' => EntraUser::whereNull('business_phone')->count(),
        ]);
    }
}
