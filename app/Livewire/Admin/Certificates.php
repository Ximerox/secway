<?php

namespace App\Livewire\Admin;

use App\Models\AuditEvent;
use App\Models\SmimeCertificate;
use App\Services\SmimeCertificateService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

#[Layout('admin.layout')]
#[Title('Zertifikate')]
class Certificates extends Component
{
    use WithFileUploads;

    public $file;

    public string $type = 'partner';

    public string $target = '';

    public string $password = '';

    public function save(SmimeCertificateService $service): void
    {
        $this->validate([
            'file' => 'required|file|max:512',
            'type' => 'required|in:partner,own',
            'target' => 'required|string|max:255',
        ], [
            'file.required' => 'Bitte eine Zertifikatsdatei auswählen.',
            'file.max' => 'Die Datei ist zu groß (max. 512 KB).',
            'target.required' => 'Bitte Domain oder E-Mail-Adresse angeben.',
        ]);

        try {
            $cert = $service->import(
                file_get_contents($this->file->getRealPath()),
                $this->type,
                $this->target,
                $this->password !== '' ? $this->password : null,
            );
        } catch (Throwable $e) {
            $this->addError('file', $e->getMessage());

            return;
        }

        AuditEvent::log('cert_imported', ip: request()->ip(), details: [
            'id' => $cert->id, 'type' => $cert->type, 'target' => $cert->target,
        ]);
        $this->reset('file', 'target', 'password');
        session()->flash('ok', "Zertifikat für „{$cert->target}“ importiert (gültig bis {$cert->valid_until?->format('d.m.Y')}).");
    }

    public function toggleActive(int $id): void
    {
        $cert = SmimeCertificate::findOrFail($id);
        $cert->update(['active' => ! $cert->active]);
        AuditEvent::log($cert->active ? 'cert_activated' : 'cert_deactivated', ip: request()->ip(), details: [
            'id' => $cert->id, 'target' => $cert->target,
        ]);
    }

    public function delete(int $id): void
    {
        $cert = SmimeCertificate::findOrFail($id);
        AuditEvent::log('cert_deleted', ip: request()->ip(), details: [
            'id' => $cert->id, 'type' => $cert->type, 'target' => $cert->target, 'subject' => $cert->subject,
        ]);
        $cert->delete();
    }

    public function render()
    {
        return view('livewire.admin.certificates', [
            'own' => SmimeCertificate::where('type', 'own')->orderBy('target')->orderByDesc('valid_until')->get(),
            'partners' => SmimeCertificate::where('type', 'partner')->orderBy('target')->orderByDesc('valid_until')->get(),
        ]);
    }
}
