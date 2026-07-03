<?php

namespace App\Livewire\Admin;

use App\Models\AuditEvent;
use App\Models\MessageRecipient;
use App\Services\PostfixQueueService;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('admin.layout')]
#[Title('Warteschlange')]
class Queue extends Component
{
    public function flush(string $id): void
    {
        app(PostfixQueueService::class)->flush($id);
        AuditEvent::log('queue_flush', ip: request()->ip(), details: ['queue_id' => $id]);
        session()->flash('ok', "Zustellversuch für {$id} angestoßen.");
    }

    public function flushAll(): void
    {
        app(PostfixQueueService::class)->flushAll();
        AuditEvent::log('queue_flush_all', ip: request()->ip());
        session()->flash('ok', 'Zustellversuch für alle wartenden Mails angestoßen.');
    }

    public function remove(string $id): void
    {
        app(PostfixQueueService::class)->requestDelete($id);
        AuditEvent::log('queue_delete', ip: request()->ip(), details: ['queue_id' => $id]);
        session()->flash('ok', "Löschung von {$id} angefordert – wird innerhalb einer Minute ausgeführt.");
    }

    public function sendPasswordNow(int $recipientId): void
    {
        $r = MessageRecipient::whereNotNull('pending_password')->findOrFail($recipientId);
        $r->password_due_at = now()->subMinute();
        $r->save();
        Artisan::call('mail:send-passwords');
        session()->flash('ok', "Kennwort-Mail an {$r->email} versendet.");
    }

    public function render()
    {
        $svc = app(PostfixQueueService::class);

        return view('livewire.admin.queue', [
            'items' => $svc->list(),
            'pendingDeletes' => $svc->pendingDeletes(),
            'pendingPasswords' => MessageRecipient::whereNotNull('pending_password')
                ->whereNull('password_sent_at')
                ->with('message')
                ->orderBy('password_due_at')
                ->get(),
        ]);
    }
}
