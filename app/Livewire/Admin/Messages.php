<?php

namespace App\Livewire\Admin;

use App\Console\Commands\SendReminders;
use App\Models\AuditEvent;
use App\Models\SecureMessage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('admin.layout')]
#[Title('Nachrichten')]
class Messages extends Component
{
    use WithPagination;

    #[Url]
    public string $filter = 'open'; // open = noch nicht (vollständig) abgerufen, all = alle

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    public function remind(int $id): void
    {
        $msg = SecureMessage::with('recipients')->findOrFail($id);
        $reminder = app(SendReminders::class);
        $count = 0;
        foreach ($msg->recipients as $r) {
            if ($r->first_viewed_at === null) {
                $r->setRelation('message', $msg);
                $count += $reminder->remind($r) ? 1 : 0;
            }
        }
        session()->flash('ok', $count > 0
            ? "{$count} Erinnerung(en) versendet."
            : 'Kein offener Empfänger für eine Erinnerung.');
    }

    public function purge(int $id): void
    {
        $msg = SecureMessage::with('attachments')->findOrFail($id);
        foreach ($msg->attachments as $att) {
            @unlink($att->disk_path);
        }
        @rmdir($msg->storageDir());
        AuditEvent::log('purged', details: [
            'message_id' => $msg->id, 'sender' => $msg->sender_email, 'manual' => true,
        ]);
        $msg->delete();
        session()->flash('ok', 'Nachricht gelöscht.');
    }

    public function render()
    {
        $query = SecureMessage::with(['recipients', 'attachments'])
            ->withCount('attachments')
            ->orderByDesc('id');

        if ($this->filter === 'open') {
            $query->whereHas('recipients', fn ($w) => $w->whereNull('first_viewed_at'));
        }

        return view('livewire.admin.messages', ['messages' => $query->paginate(25)]);
    }
}
