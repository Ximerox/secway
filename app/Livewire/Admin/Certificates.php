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

    public ?int $detailsId = null;

    public string $exportPassword = '';

    public string $tab = 'own';

    public string $ownSort = 'target';

    public string $ownDir = 'asc';

    public string $partnerSort = 'target';

    public string $partnerDir = 'asc';

    /** Erlaubte Sortierspalten je Liste (Whitelist gegen beliebige Spaltennamen). */
    private const OWN_SORTABLE = ['target', 'subject', 'valid_until', 'active'];

    private const PARTNER_SORTABLE = ['target', 'scope', 'subject', 'valid_until', 'source', 'active'];

    public function sortOwn(string $column): void
    {
        if (! in_array($column, self::OWN_SORTABLE, true)) {
            return;
        }
        $this->ownDir = $this->ownSort === $column && $this->ownDir === 'asc' ? 'desc' : 'asc';
        $this->ownSort = $column;
    }

    public function sortPartner(string $column): void
    {
        if (! in_array($column, self::PARTNER_SORTABLE, true)) {
            return;
        }
        $this->partnerDir = $this->partnerSort === $column && $this->partnerDir === 'asc' ? 'desc' : 'asc';
        $this->partnerSort = $column;
    }

    /** Detail-Panel für ein Zertifikat auf-/zuklappen. */
    public function showDetails(int $id): void
    {
        $this->detailsId = $this->detailsId === $id ? null : $id;
        $this->exportPassword = '';
        $this->resetErrorBag('exportPassword');
    }

    /**
     * Öffentliches Zertifikat (ohne Schlüssel) als PEM/.cer — zur Weitergabe
     * an Kommunikationspartner, damit diese an uns verschlüsseln können.
     */
    public function exportPublic(int $id)
    {
        $cert = SmimeCertificate::findOrFail($id);

        AuditEvent::log('cert_exported', ip: request()->ip(), details: [
            'id' => $cert->id, 'target' => $cert->target, 'mode' => 'public',
        ]);

        $pem = implode("\n", $cert->pemBlocks())."\n";
        $name = 'zertifikat-'.preg_replace('/[^a-z0-9.@-]+/i', '_', $cert->target).'.cer';

        return response()->streamDownload(function () use ($pem) {
            echo $pem;
        }, $name, ['Content-Type' => 'application/x-x509-ca-cert']);
    }

    /**
     * Eigenes Zertifikat INKLUSIVE privatem Schlüssel als passwortgeschütztes
     * PKCS#12 — nur für Sicherung/Umzug, niemals an Partner weitergeben.
     */
    public function exportWithKey(int $id)
    {
        $cert = SmimeCertificate::where('type', 'own')->findOrFail($id);
        if (! $cert->key_pem) {
            $this->addError('exportPassword', 'Zu diesem Zertifikat ist kein privater Schlüssel gespeichert.');

            return null;
        }

        $this->validate(
            ['exportPassword' => 'required|string|min:8'],
            ['exportPassword.required' => 'Bitte ein Export-Passwort vergeben (schützt die .p12-Datei).',
             'exportPassword.min' => 'Mindestens 8 Zeichen.'],
        );

        $blocks = $cert->pemBlocks();
        $p12 = '';
        $ok = openssl_pkcs12_export(
            $blocks[0] ?? $cert->cert_pem,
            $p12,
            $cert->privateKey(),
            $this->exportPassword,
            count($blocks) > 1 ? ['extracerts' => array_slice($blocks, 1)] : [],
        );
        if (! $ok) {
            $this->addError('exportPassword', 'PKCS#12-Export fehlgeschlagen: '.(openssl_error_string() ?: 'unbekannter Fehler'));

            return null;
        }

        AuditEvent::log('cert_exported', ip: request()->ip(), details: [
            'id' => $cert->id, 'target' => $cert->target, 'mode' => 'with_key',
        ]);
        $this->exportPassword = '';

        $name = 'zertifikat-mit-schluessel-'.preg_replace('/[^a-z0-9.@-]+/i', '_', $cert->target).'.p12';

        return response()->streamDownload(function () use ($p12) {
            echo $p12;
        }, $name, ['Content-Type' => 'application/x-pkcs12']);
    }

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
        $ownQuery = SmimeCertificate::where('type', 'own')->orderBy($this->ownSort, $this->ownDir);
        if ($this->ownSort !== 'valid_until') {
            $ownQuery->orderByDesc('valid_until');
        }

        $partnerQuery = SmimeCertificate::where('type', 'partner')->orderBy($this->partnerSort, $this->partnerDir);
        if ($this->partnerSort !== 'valid_until') {
            $partnerQuery->orderByDesc('valid_until');
        }

        return view('livewire.admin.certificates', [
            'own' => $ownQuery->get(),
            'partners' => $partnerQuery->get(),
        ]);
    }
}
