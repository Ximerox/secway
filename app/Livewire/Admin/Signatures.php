<?php

namespace App\Livewire\Admin;

use App\Models\AuditEvent;
use App\Models\Setting;
use App\Models\SignatureTemplate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('admin.layout')]
#[Title('Signaturblöcke')]
class Signatures extends Component
{
    public bool $module_enabled = false;

    public bool $sent_items_update = false;

    public function mount(): void
    {
        $this->module_enabled = Setting::getBool('signature_enabled', false);
        $this->sent_items_update = Setting::getBool('sent_items_update', false);
    }

    public function updatedModuleEnabled(): void
    {
        Setting::set('signature_enabled', $this->module_enabled);
        AuditEvent::log('settings_changed', ip: request()->ip(), details: ['signature_enabled' => $this->module_enabled]);
        session()->flash('ok', $this->module_enabled
            ? 'Signaturblock-Modul EINGESCHALTET — aktive Vorlagen werden ab sofort auf passende Mails angewandt.'
            : 'Signaturblock-Modul ausgeschaltet — es werden keine Signaturblöcke mehr angehängt.');
    }

    public function updatedSentItemsUpdate(): void
    {
        Setting::set('sent_items_update', $this->sent_items_update);
        AuditEvent::log('settings_changed', ip: request()->ip(), details: ['sent_items_update' => $this->sent_items_update]);
        session()->flash('ok', $this->sent_items_update
            ? 'Postausgang-Aktualisierung eingeschaltet — gesendete Mails werden nachträglich durch die Fassung mit Signaturblock ersetzt (benötigt Graph-Berechtigung Mail.ReadWrite).'
            : 'Postausgang-Aktualisierung ausgeschaltet.');
    }

    public function delete(int $id): void
    {
        $t = SignatureTemplate::findOrFail($id);
        $t->delete();
        AuditEvent::log('signature_template_deleted', ip: request()->ip(), details: ['id' => $id, 'name' => $t->name]);
        session()->flash('ok', 'Signaturblock „'.$t->name.'" gelöscht.');
    }

    public function render()
    {
        return view('livewire.admin.signatures', [
            'templates' => SignatureTemplate::orderBy('priority')->orderBy('name')->get(),
        ]);
    }
}
