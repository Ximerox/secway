<?php

namespace App\Livewire\Admin;

use App\Mail\SignatureTestMail;
use App\Models\AuditEvent;
use App\Models\EntraUser;
use App\Models\SignatureImage;
use App\Models\SignatureTemplate;
use App\Services\GraphClient;
use App\Services\SignatureRenderer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
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

    // Anwendungsregeln
    public int $priority = 10;

    public string $direction = 'both';

    public string $sender_mode = 'all';

    public string $sender_users = '';

    public string $sender_group_id = '';

    public string $valid_from = '';

    public string $valid_until = '';

    public bool $continue_processing = false;

    public $upload = null;

    public string $preview_user = '';

    public string $previewHtml = '';

    public string $test_to = '';

    public function mount(): void
    {
        $this->preview_user = (string) (EntraUser::orderBy('display_name')->value('mail') ?? '');
    }

    /** Gruppen des Tenants für das Regel-Dropdown (10 Min gecacht). */
    #[Computed]
    public function groupOptions(): array
    {
        try {
            return Cache::remember('graph_groups_list', 600, function () {
                $map = [];
                foreach (app(GraphClient::class)->groups() as $g) {
                    $map[$g['id']] = $g['displayName'] ?? $g['id'];
                }
                natcasesort($map);

                return $map;
            });
        } catch (Throwable $e) {
            return [];
        }
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
        $this->priority = 10;
        $this->direction = 'both';
        $this->sender_mode = 'all';
        $this->sender_users = '';
        $this->sender_group_id = '';
        $this->valid_from = '';
        $this->valid_until = '';
        $this->continue_processing = false;
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
        $this->priority = (int) $t->priority;
        $this->direction = $t->direction;
        $this->sender_mode = $t->sender_mode;
        $this->sender_users = (string) $t->sender_users;
        $this->sender_group_id = (string) $t->sender_group_id;
        $this->valid_from = $t->valid_from?->format('Y-m-d') ?? '';
        $this->valid_until = $t->valid_until?->format('Y-m-d') ?? '';
        $this->continue_processing = $t->continue_processing;
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
            'priority' => 'required|integer|min:1|max:999',
            'direction' => 'in:both,external,internal',
            'sender_mode' => 'in:all,users,group',
            'sender_users' => 'required_if:sender_mode,users|nullable|string|max:5000',
            'sender_group_id' => 'required_if:sender_mode,group|nullable|uuid',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
        ], [
            'name.required' => 'Bitte einen Namen für die Vorlage vergeben.',
            'html.required' => 'Die Vorlage ist leer.',
            'sender_users.required_if' => 'Bitte mindestens eine Absenderadresse angeben.',
            'sender_group_id.required_if' => 'Bitte eine Gruppe auswählen.',
            'valid_until.after_or_equal' => '„Gültig bis" darf nicht vor „Gültig von" liegen.',
        ]);

        $t = $this->editId ? SignatureTemplate::find($this->editId) : null;
        $t ??= new SignatureTemplate;

        $groupName = $this->sender_mode === 'group'
            ? ($this->groupOptions()[$this->sender_group_id] ?? $t->sender_group_name)
            : null;

        $t->fill([
            'name' => trim($this->name),
            'html' => $this->html,
            'text_body' => trim($this->text_body) !== '' ? $this->text_body : null,
            'existing_mode' => $this->existing_mode,
            'active' => $this->active,
            'priority' => $this->priority,
            'direction' => $this->direction,
            'sender_mode' => $this->sender_mode,
            'sender_users' => $this->sender_mode === 'users' ? trim($this->sender_users) : null,
            'sender_group_id' => $this->sender_mode === 'group' ? $this->sender_group_id : null,
            'sender_group_name' => $groupName,
            'valid_from' => $this->valid_from ?: null,
            'valid_until' => $this->valid_until ?: null,
            'continue_processing' => $this->continue_processing,
        ])->save();

        $this->editId = $t->id;

        AuditEvent::log('signature_template_saved', ip: request()->ip(), details: [
            'id' => $t->id, 'name' => $t->name, 'active' => $t->active,
            'priority' => $t->priority, 'direction' => $t->direction, 'sender_mode' => $t->sender_mode,
        ]);

        $hint = $this->sender_mode === 'group'
            ? ' Hinweis: Gruppen-Mitgliedschaften werden beim nächsten Entra-Sync aufgelöst (oder jetzt manuell auf der Benutzer-Seite synchronisieren).'
            : '';
        session()->flash('ok', 'Vorlage „'.$t->name.'" gespeichert.'.$hint);
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
            'templates' => SignatureTemplate::orderBy('priority')->orderBy('name')->get(),
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
