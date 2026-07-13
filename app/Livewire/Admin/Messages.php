<?php

namespace App\Livewire\Admin;

use App\Console\Commands\SendReminders;
use App\Mail\PasswordMail;
use App\Models\AuditEvent;
use App\Models\MessageRecipient;
use App\Models\SecureMessage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

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

    /**
     * Sendet EINEM Empfänger ein NEUES Kennwort (gezielt pro Empfänger — die
     * übrigen Empfänger der Nachricht behalten ihres). Das ursprüngliche liegt
     * nur als Hash vor und ist nicht wiederherstellbar, daher wird ein frisches
     * erzeugt, der Hash aktualisiert und per Mail zugestellt.
     */
    public function resendPassword(int $recipientId): void
    {
        $r = MessageRecipient::with('message')->findOrFail($recipientId);
        $password = self::generatePassword();
        $r->password_hash = Hash::make($password);
        $r->pending_password = null;
        $r->password_due_at = now();
        $r->password_sent_at = now();
        $r->save();
        try {
            Mail::to($r->email)->send(new PasswordMail($r->message, $password));
            AuditEvent::log('password_sent', $r->message, $r, details: ['resent' => true]);
            session()->flash('ok', "Neues Kennwort an {$r->email} versendet.");
        } catch (Throwable $e) {
            Log::error("Kennwort-Neuversand für Empfänger {$r->id} fehlgeschlagen: ".$e->getMessage());
            session()->flash('ok', 'Kennwortversand fehlgeschlagen – siehe Log.');
        }
    }

    /** Kennwort ohne leicht verwechselbare Zeichen, Format xxxx-xxxx-xxxx. */
    private static function generatePassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $blocks = [];
        for ($b = 0; $b < 3; $b++) {
            $s = '';
            for ($i = 0; $i < 4; $i++) {
                $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $blocks[] = $s;
        }

        return implode('-', $blocks);
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
