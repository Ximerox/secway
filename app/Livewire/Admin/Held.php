<?php

namespace App\Livewire\Admin;

use App\Models\AuditEvent;
use App\Models\HeldMessage;
use App\Services\SmimeInboundService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('admin.layout')]
#[Title('Zurückgehalten')]
class Held extends Component
{
    public function retry(int $id): void
    {
        $held = HeldMessage::where('status', 'held')->findOrFail($id);

        if (app(SmimeInboundService::class)->retryHeld($held)) {
            session()->flash('ok', 'Die Mail wurde entschlüsselt und zugestellt.');
        } else {
            session()->flash('err', 'Weiterhin kein passendes Zertifikat — bitte erst unter Zertifikate ein eigenes Zertifikat mit privatem Schlüssel importieren (siehe Spalte „Benötigtes Zertifikat").');
        }
    }

    public function deliver(int $id): void
    {
        $held = HeldMessage::where('status', 'held')->findOrFail($id);
        app(SmimeInboundService::class)->retryHeld($held, deliverAnyway: true, actionIfUndeciphered: 'as_is');
        session()->flash('ok', 'Die Mail wurde unverändert (verschlüsselt) zugestellt.');
    }

    public function remove(int $id): void
    {
        $held = HeldMessage::where('status', 'held')->findOrFail($id);
        $held->release('deleted');
        AuditEvent::log('held_deleted', ip: request()->ip(), details: [
            'sender' => $held->sender,
            'recipients' => $held->recipients,
            'subject' => $held->subject,
        ]);
        session()->flash('ok', 'Die Mail wurde endgültig verworfen.');
    }

    public function render()
    {
        return view('livewire.admin.held', [
            'held' => HeldMessage::where('status', 'held')->orderByDesc('id')->get(),
            'released' => HeldMessage::where('status', 'released')->orderByDesc('released_at')->limit(15)->get(),
        ]);
    }
}
