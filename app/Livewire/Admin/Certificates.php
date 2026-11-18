<?php

namespace App\Livewire\Admin;

use App\Models\AuditEvent;
use App\Models\CertificateOrder;
use App\Models\SmimeCertificate;
use App\Services\CertificateOrderService;
use App\Services\PswApi;
use App\Services\SmimeCertificateService;
use Illuminate\Support\Facades\Auth;
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

    public string $orderEmail = '';

    public string $orderProduct = '';

    public string $backupPassword = '';

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
        $cert->forceFill(['exported_at' => now()])->save();
        $this->exportPassword = '';

        $name = 'zertifikat-mit-schluessel-'.preg_replace('/[^a-z0-9.@-]+/i', '_', $cert->target).'.p12';

        return response()->streamDownload(function () use ($p12) {
            echo $p12;
        }, $name, ['Content-Type' => 'application/x-pkcs12']);
    }

    /**
     * Sicherung: alle eigenen Zertifikate mit Schlüssel als ZIP aus einzelnen,
     * mit demselben Passwort geschützten .p12-Dateien.
     */
    public function exportAll()
    {
        $this->validate(
            ['backupPassword' => 'required|string|min:12'],
            ['backupPassword.required' => 'Bitte ein Passwort für die Sicherung vergeben.',
             'backupPassword.min' => 'Für die Gesamtsicherung mindestens 12 Zeichen.'],
        );

        $certs = SmimeCertificate::where('type', 'own')->whereNotNull('key_pem')->orderBy('target')->get();
        if ($certs->isEmpty()) {
            $this->addError('backupPassword', 'Keine eigenen Zertifikate mit Schlüssel vorhanden.');

            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'mgwbak');
        $zip = new \ZipArchive;
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            $this->addError('backupPassword', 'ZIP konnte nicht angelegt werden.');

            return null;
        }
        $list = [];
        $done = [];
        foreach ($certs as $cert) {
            $blocks = $cert->pemBlocks();
            $p12 = '';
            if (! openssl_pkcs12_export($blocks[0] ?? $cert->cert_pem, $p12, $cert->privateKey(), $this->backupPassword,
                count($blocks) > 1 ? ['extracerts' => array_slice($blocks, 1)] : [])) {
                $list[] = 'FEHLER beim Export: '.$cert->target.' (#'.$cert->id.')';

                continue;
            }
            $name = preg_replace('/[^a-z0-9.@-]+/i', '_', $cert->target).'_'.($cert->valid_until?->format('Y-m-d') ?? 'x').'_'.$cert->id.'.p12';
            $zip->addFromString($name, $p12);
            $done[] = $cert->id;
            $list[] = $name.'  '.$cert->subject.'  gültig bis '.$cert->valid_until?->format('d.m.Y')
                .($cert->active ? '' : '  (deaktiviert)');
        }
        $zip->addFromString('LIESMICH.txt', 'SecWay-Sicherung eigener S/MIME-Zertifikate vom '.now()->format('d.m.Y H:i')."\r\n"
            ."Jede .p12-Datei enthält Zertifikat, CA-Kette und PRIVATEN SCHLÜSSEL und ist mit dem\r\n"
            ."beim Export vergebenen Passwort geschützt. Sicher aufbewahren, nicht weitergeben.\r\n\r\n"
            .implode("\r\n", $list)."\r\n");
        $zip->close();
        $data = file_get_contents($tmp);
        @unlink($tmp);

        SmimeCertificate::whereIn('id', $done)->update(['exported_at' => now()]);
        AuditEvent::log('cert_exported', ip: request()->ip(), details: ['mode' => 'backup_all', 'count' => count($done)]);
        $this->backupPassword = '';

        return response()->streamDownload(function () use ($data) {
            echo $data;
        }, 'secway-zertifikate-'.now()->format('Y-m-d').'.zip', ['Content-Type' => 'application/zip']);
    }

    /** Neues Zertifikat bei der PSW Group bestellen. */
    public function placeOrder(CertificateOrderService $service): void
    {
        $this->validate(
            ['orderEmail' => 'required|email', 'orderProduct' => 'required|string'],
            ['orderEmail.required' => 'Bitte die E-Mail-Adresse angeben.', 'orderEmail.email' => 'Ungültige E-Mail-Adresse.'],
        );
        $by = Auth::user()?->username ?: Auth::user()?->name;
        try {
            $order = $service->place($this->orderEmail, $this->orderProduct, $by);
        } catch (Throwable $e) {
            $this->addError('orderEmail', $e->getMessage());

            return;
        }
        $this->reset('orderEmail');
        session()->flash('ok', "Bestellung für „{$order->email}“ übermittelt (PSW-Bestellnr. {$order->psw_order_number}). Der Status wird alle 15 Minuten abgefragt.");
    }

    /** Bestellformular mit der Adresse eines bestehenden Zertifikats vorbelegen (Verlängerung). */
    public function prefillOrder(int $id): void
    {
        $this->orderEmail = SmimeCertificate::where('type', 'own')->findOrFail($id)->target;
    }

    public function refreshOrder(int $id, CertificateOrderService $service): void
    {
        $service->poll(CertificateOrder::findOrFail($id));
    }

    public function resendOrderMail(int $id, CertificateOrderService $service): void
    {
        try {
            $service->resendValidationEmail(CertificateOrder::findOrFail($id));
            session()->flash('ok', 'Validierungsmail wird erneut versendet.');
        } catch (Throwable $e) {
            session()->flash('err', $e->getMessage());
        }
    }

    public function cancelOrder(int $id, CertificateOrderService $service): void
    {
        $order = CertificateOrder::findOrFail($id);
        try {
            $service->cancel($order);
            session()->flash('ok', "Bestellung für {$order->email} storniert.");
        } catch (Throwable $e) {
            session()->flash('err', 'Stornierung fehlgeschlagen: '.$e->getMessage());
        }
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

        $products = config('mailgateway.psw.products', []);
        if ($this->orderProduct === '' || ! isset($products[$this->orderProduct])) {
            $this->orderProduct = (string) array_key_last($products);
        }

        return view('livewire.admin.certificates', [
            'own' => $ownQuery->get(),
            'partners' => $partnerQuery->get(),
            'psw' => PswApi::configured(),
            'pswTest' => PswApi::isTestSystem(),
            'pswMissing' => CertificateOrderService::missingConfig(),
            'pswProducts' => $products,
            'orders' => CertificateOrder::orderByDesc('id')->limit(30)->get(),
            'unsaved' => SmimeCertificate::where('type', 'own')->whereNotNull('key_pem')->whereNull('exported_at')->count(),
        ]);
    }
}
