<?php

namespace App\Livewire\Admin;

use App\Mail\SignatureTestMail;
use App\Models\AuditEvent;
use App\Models\EntraUser;
use App\Models\SignatureImage;
use App\Models\SignatureTemplate;
use App\Services\SignatureRenderer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

#[Layout('admin.layout')]
#[Title('Signaturen')]
class Signatures extends Component
{
    use WithFileUploads;

    /** Platzhaltername => Anzeigelabel (Quelle: EntraUser::placeholderData). */
    public const PLACEHOLDERS = [
        'vorname' => 'Vorname',
        'nachname' => 'Nachname',
        'name' => 'Anzeigename',
        'position' => 'Position',
        'abteilung' => 'Abteilung',
        'firma' => 'Firma',
        'buero' => 'Büro/Standort',
        'telefon' => 'Telefon',
        'mobil' => 'Mobil',
        'fax' => 'Fax',
        'strasse' => 'Straße',
        'plz' => 'PLZ',
        'ort' => 'Ort',
        'email' => 'E-Mail',
    ];

    public ?int $editId = null;

    public string $name = '';

    public string $html = '';

    public string $text_body = '';

    public string $existing_mode = 'replace';

    public bool $active = false;

    public $upload = null;

    public string $preview_user = '';

    public string $previewHtml = '';

    public string $test_to = '';

    public function mount(): void
    {
        $this->preview_user = (string) (EntraUser::orderBy('display_name')->value('mail') ?? '');
    }

    public function create(): void
    {
        $this->resetValidation();
        $this->editId = 0;
        $this->name = '';
        $this->html = self::defaultHtml();
        $this->text_body = '';
        $this->existing_mode = 'replace';
        $this->active = false;
        $this->previewHtml = '';
        $this->dispatch('sig-editor', html: $this->html);
    }

    public function edit(int $id): void
    {
        $t = SignatureTemplate::findOrFail($id);
        $this->resetValidation();
        $this->editId = $t->id;
        $this->name = $t->name;
        $this->html = $t->html;
        $this->text_body = (string) $t->text_body;
        $this->existing_mode = $t->existing_mode;
        $this->active = $t->active;
        $this->previewHtml = '';
        $this->dispatch('sig-editor', html: $this->html);
    }

    public function closeEditor(): void
    {
        $this->editId = null;
        $this->previewHtml = '';
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|min:2|max:100',
            'existing_mode' => 'in:skip,replace',
            'html' => 'required|string|max:300000',
            'text_body' => 'nullable|string|max:20000',
        ], [
            'name.required' => 'Bitte einen Namen für die Vorlage vergeben.',
            'html.required' => 'Die Vorlage ist leer.',
        ]);

        $t = $this->editId ? SignatureTemplate::find($this->editId) : null;
        $t ??= new SignatureTemplate;

        $t->fill([
            'name' => trim($this->name),
            'html' => $this->html,
            'text_body' => trim($this->text_body) !== '' ? $this->text_body : null,
            'existing_mode' => $this->existing_mode,
            'active' => $this->active,
        ])->save();

        $this->editId = $t->id;

        AuditEvent::log('signature_template_saved', ip: request()->ip(), details: [
            'id' => $t->id, 'name' => $t->name, 'active' => $t->active, 'existing_mode' => $t->existing_mode,
        ]);

        session()->flash('ok', 'Vorlage „'.$t->name.'" gespeichert.');
    }

    public function delete(int $id): void
    {
        $t = SignatureTemplate::findOrFail($id);
        $t->delete();

        AuditEvent::log('signature_template_deleted', ip: request()->ip(), details: ['id' => $id, 'name' => $t->name]);

        if ($this->editId === $id) {
            $this->closeEditor();
        }

        session()->flash('ok', 'Vorlage „'.$t->name.'" gelöscht.');
    }

    public function preview(SignatureRenderer $renderer): void
    {
        $user = EntraUser::where('mail', $this->preview_user)->first();
        if (! $user) {
            session()->flash('err', 'Vorschau-Benutzer nicht gefunden — bitte auswählen.');

            return;
        }

        $t = new SignatureTemplate(['html' => $this->html, 'text_body' => $this->text_body]);
        $this->previewHtml = $renderer->renderHtml($t, $user);
    }

    public function sendTest(SignatureRenderer $renderer): void
    {
        $this->validate(['test_to' => 'required|email'], [
            'test_to.required' => 'Bitte eine Empfängeradresse für die Testmail angeben.',
            'test_to.email' => 'Keine gültige E-Mail-Adresse.',
        ]);

        $user = EntraUser::where('mail', $this->preview_user)->first();
        if (! $user) {
            session()->flash('err', 'Vorschau-Benutzer nicht gefunden — bitte auswählen.');

            return;
        }

        try {
            $t = new SignatureTemplate(['html' => $this->html, 'text_body' => $this->text_body]);
            $parts = $renderer->forMail($t, $user);
            Mail::to($this->test_to)->send(new SignatureTestMail($parts['html'], $parts['images']));
            session()->flash('ok', 'Testmail an '.$this->test_to.' versendet (Signatur mit Daten von '.$user->display_name.').');
        } catch (Throwable $e) {
            session()->flash('err', 'Testmail fehlgeschlagen: '.$e->getMessage());
        }
    }

    public function updatedUpload(): void
    {
        $this->validate(['upload' => 'image|max:2048'], [
            'upload.image' => 'Nur Bilddateien (PNG, JPG, GIF, SVG).',
            'upload.max' => 'Maximal 2 MB — Signaturbilder sollten klein sein, sie hängen an jeder Mail.',
        ]);

        // Metadaten VOR store() lesen — danach ist die temporäre Datei verschoben
        $name = $this->upload->getClientOriginalName();
        $mime = $this->upload->getMimeType() ?: 'application/octet-stream';
        $size = (int) $this->upload->getSize();

        $path = $this->upload->store('signatures');
        SignatureImage::create([
            'original_name' => $name,
            'path' => $path,
            'mime' => $mime,
            'size' => $size,
        ]);

        $this->reset('upload');
        session()->flash('ok', 'Bild hochgeladen — über „Einfügen" in die Vorlage übernehmen.');
    }

    public function deleteImage(int $id): void
    {
        $img = SignatureImage::findOrFail($id);
        Storage::delete($img->path);
        $img->delete();
        session()->flash('ok', 'Bild gelöscht. Achtung: Vorlagen, die es noch referenzieren, zeigen es nicht mehr an.');
    }

    public function render()
    {
        return view('livewire.admin.signatures', [
            'templates' => SignatureTemplate::orderBy('name')->get(),
            'images' => SignatureImage::orderByDesc('id')->get(),
            'previewUsers' => EntraUser::orderBy('display_name')->get(['display_name', 'mail']),
        ]);
    }

    protected static function defaultHtml(): string
    {
        return <<<'HTML'
<table cellpadding="0" cellspacing="0" border="0" style="font-family: Arial, Helvetica, sans-serif; font-size: 10pt; color: #333333;">
<tbody>
<tr><td style="padding-bottom: 4px;"><strong>{{name}}</strong>{{#if position}} &middot; {{position}}{{/if}}</td></tr>
{{#if abteilung}}<tr><td>{{abteilung}}</td></tr>{{/if}}
<tr><td style="padding-top: 6px;">{{firma}}</td></tr>
{{#if telefon}}<tr><td>Tel: {{telefon}}</td></tr>{{/if}}
{{#if mobil}}<tr><td>Mobil: {{mobil}}</td></tr>{{/if}}
<tr><td>E-Mail: {{email}}</td></tr>
</tbody>
</table>
HTML;
    }
}
