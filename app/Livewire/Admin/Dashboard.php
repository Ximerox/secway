<?php

namespace App\Livewire\Admin;

use App\Models\AuditEvent;
use App\Models\MessageRecipient;
use App\Models\SecureMessage;
use App\Models\SmimeCertificate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('admin.layout')]
#[Title('Übersicht')]
class Dashboard extends Component
{
    public function render()
    {
        return view('livewire.admin.dashboard', [
            'messageCount' => SecureMessage::count(),
            'messageCount7d' => SecureMessage::where('created_at', '>', now()->subDays(7))->count(),
            'unviewedCount' => MessageRecipient::whereNull('first_viewed_at')->count(),
            'certCount' => SmimeCertificate::usable()->where('type', 'partner')->count(),
            'certExpiring' => SmimeCertificate::where('active', true)
                ->whereBetween('valid_until', [now(), now()->addDays(30)])->count(),
            'events' => AuditEvent::orderByDesc('id')->limit(12)->get(),
        ]);
    }
}
