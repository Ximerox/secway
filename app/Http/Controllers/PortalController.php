<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\MessageRecipient;
use App\Support\Crypto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PortalController extends Controller
{
    public function show(Request $request, string $token)
    {
        $recipient = $this->findRecipient($token);
        if (! $recipient) {
            return $this->notAvailable();
        }

        $msg = $recipient->message;
        if ($msg->isExpired()) {
            return $this->expired();
        }

        if (! $request->session()->get($this->sessionKey($recipient))) {
            return view('portal.unlock', ['msg' => $msg, 'recipient' => $recipient]);
        }

        $key = $msg->dataKey();
        $bodyHtml = $msg->body_html ? Crypto::decrypt($msg->body_html, $key) : null;

        // cid:-Referenzen (Inline-Bilder) durch eingebettete data-URIs ersetzen
        if ($bodyHtml !== null) {
            foreach ($msg->attachments as $att) {
                if ($att->content_id && str_contains($bodyHtml, 'cid:'.$att->content_id)) {
                    $data = 'data:'.($att->mime ?: 'application/octet-stream').';base64,'
                        .base64_encode(Crypto::decrypt(file_get_contents($att->disk_path), $key));
                    $bodyHtml = str_replace('cid:'.$att->content_id, $data, $bodyHtml);
                }
            }
        }

        return view('portal.show', [
            'msg' => $msg,
            'recipient' => $recipient,
            'attachments' => $msg->attachments->where('is_inline', false)->values(),
            'bodyText' => $msg->body_text ? Crypto::decrypt($msg->body_text, $key) : null,
            'bodyHtml' => $bodyHtml,
        ]);
    }

    public function unlock(Request $request, string $token)
    {
        $recipient = $this->findRecipient($token);
        if (! $recipient) {
            return $this->notAvailable();
        }
        if ($recipient->message->isExpired()) {
            return $this->expired();
        }

        if ($recipient->isLocked()) {
            return redirect('/m/'.$token)->with('error',
                'Zu viele Fehlversuche. Bitte versuchen Sie es in '.config('mailgateway.lockout_minutes').' Minuten erneut.');
        }

        $password = trim((string) $request->input('password'));
        if ($password !== '' && Hash::check($password, $recipient->password_hash)) {
            $request->session()->regenerate();
            $request->session()->put($this->sessionKey($recipient), true);
            $recipient->failed_attempts = 0;
            $recipient->locked_until = null;
            $recipient->first_viewed_at ??= now();
            $recipient->last_viewed_at = now();
            $recipient->save();
            AuditEvent::log('unlocked', $recipient->message, $recipient, $request->ip());

            return redirect('/m/'.$token);
        }

        $recipient->failed_attempts++;
        if ($recipient->failed_attempts >= (int) config('mailgateway.max_attempts')) {
            $recipient->locked_until = now()->addMinutes((int) config('mailgateway.lockout_minutes'));
            $recipient->failed_attempts = 0;
        }
        $recipient->save();
        AuditEvent::log('unlock_failed', $recipient->message, $recipient, $request->ip());

        return redirect('/m/'.$token)->with('error', 'Das Kennwort ist nicht korrekt.');
    }

    public function download(Request $request, string $token, Attachment $attachment)
    {
        $recipient = $this->findRecipient($token);
        if (! $recipient
            || $recipient->message->isExpired()
            || $attachment->secure_message_id !== $recipient->secure_message_id
            || ! $request->session()->get($this->sessionKey($recipient))) {
            return $this->notAvailable();
        }

        $key = $recipient->message->dataKey();
        $content = Crypto::decrypt(file_get_contents($attachment->disk_path), $key);

        $recipient->increment('download_count');
        AuditEvent::log('downloaded', $recipient->message, $recipient, $request->ip(), [
            'attachment' => $attachment->filename,
        ]);

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $attachment->filename, [
            'Content-Type' => $attachment->mime ?: 'application/octet-stream',
            'Content-Length' => strlen($content),
        ]);
    }

    private function findRecipient(string $token): ?MessageRecipient
    {
        if (strlen($token) !== 64 || ! ctype_xdigit($token)) {
            return null;
        }

        return MessageRecipient::with('message')->where('token', $token)->first();
    }

    private function sessionKey(MessageRecipient $recipient): string
    {
        return 'mgw_unlocked_'.$recipient->id;
    }

    private function notAvailable()
    {
        return response()->view('portal.error', [
            'title' => 'Nachricht nicht verfügbar',
            'text' => 'Dieser Link ist ungültig oder die Nachricht wurde bereits gelöscht.',
        ], 404);
    }

    private function expired()
    {
        return response()->view('portal.error', [
            'title' => 'Nachricht abgelaufen',
            'text' => 'Die Aufbewahrungsfrist dieser Nachricht ist abgelaufen. Bitte wenden Sie sich an den Absender, wenn Sie die Inhalte weiterhin benötigen.',
        ], 410);
    }
}
