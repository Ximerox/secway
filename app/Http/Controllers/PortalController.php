<?php

namespace App\Http\Controllers;

use App\Mail\PortalReplyMail;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\MessageRecipient;
use App\Models\Setting;
use App\Support\ClamScanner;
use App\Support\Crypto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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

        $replyEnabled = Setting::getBool('reply_enabled', (bool) config('mailgateway.reply_enabled'));

        return view('portal.show', [
            'msg' => $msg,
            'recipient' => $recipient,
            'attachments' => $msg->attachments->where('is_inline', false)->values(),
            'bodyText' => $msg->body_text ? Crypto::decrypt($msg->body_text, $key) : null,
            'bodyHtml' => $bodyHtml,
            'replyEnabled' => $replyEnabled,
            'repliesLeft' => $replyEnabled ? max(0, $this->maxReplies() - $this->repliesUsed($recipient)) : 0,
            'replyMaxMb' => (int) Setting::get('reply_max_size_mb', config('mailgateway.reply_max_size_mb')),
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

    /**
     * Antwort eines externen Empfängers an den internen Ursprungs-Absender.
     * Nur in entsperrter Sitzung, innerhalb der Abruffrist, mit Antwort- und
     * Größenlimit; Anhänge werden vor der Zustellung mit ClamAV geprüft
     * (Scanner nicht erreichbar = Ablehnung, niemals ungeprüft zustellen).
     */
    public function reply(Request $request, string $token)
    {
        $recipient = $this->findRecipient($token);
        if (! $recipient || ! Setting::getBool('reply_enabled', (bool) config('mailgateway.reply_enabled'))) {
            return $this->notAvailable();
        }

        $msg = $recipient->message;
        if ($msg->isExpired()) {
            return $this->expired();
        }
        if (! $request->session()->get($this->sessionKey($recipient))) {
            return redirect('/m/'.$token);
        }

        if ($this->repliesUsed($recipient) >= $this->maxReplies()) {
            return redirect('/m/'.$token)->with('reply_error',
                'Die maximale Anzahl an Antworten für diese Nachricht ist erreicht.');
        }

        $maxMb = (int) Setting::get('reply_max_size_mb', config('mailgateway.reply_max_size_mb'));

        $request->validate([
            'reply_text' => 'required|string|min:2|max:50000',
            'files' => 'nullable|array|max:10',
            'files.*' => 'file|max:'.($maxMb * 1024),
        ], [
            'reply_text.required' => 'Bitte geben Sie einen Antworttext ein.',
            'reply_text.min' => 'Bitte geben Sie einen Antworttext ein.',
            'reply_text.max' => 'Der Antworttext ist zu lang.',
            'files.max' => 'Höchstens 10 Dateien pro Antwort.',
            'files.*.max' => 'Eine Datei überschreitet das Limit von '.$maxMb.' MB.',
            'files.*.file' => 'Eine Datei konnte nicht übernommen werden. Bitte versuchen Sie es erneut.',
        ]);

        /** @var \Illuminate\Http\UploadedFile[] $files */
        $files = $request->file('files') ?? [];

        $total = array_sum(array_map(fn ($f) => (int) $f->getSize(), $files));
        if ($total > $maxMb * 1024 * 1024) {
            return redirect('/m/'.$token)->with('reply_error',
                'Die Anhänge überschreiten zusammen das Limit von '.$maxMb.' MB.');
        }

        foreach ($files as $file) {
            try {
                $virus = ClamScanner::scan($file->getRealPath());
            } catch (\RuntimeException $e) {
                Log::error('Portal-Antwort: Virenscanner nicht verfügbar', ['error' => $e->getMessage()]);
                AuditEvent::log('reply_rejected', $msg, $recipient, $request->ip(), ['reason' => 'scanner_unavailable']);

                return redirect('/m/'.$token)->with('reply_error',
                    'Ihre Antwort konnte nicht geprüft werden. Bitte versuchen Sie es später erneut.');
            }
            if ($virus !== null) {
                AuditEvent::log('reply_rejected', $msg, $recipient, $request->ip(), [
                    'file' => $file->getClientOriginalName(),
                    'virus' => $virus,
                ]);

                return redirect('/m/'.$token)->with('reply_error',
                    'Der Anhang „'.$file->getClientOriginalName().'" wurde vom Virenscanner beanstandet. Die Antwort wurde nicht übermittelt.');
            }
        }

        $fileMeta = array_map(fn ($f) => [
            'path' => $f->getRealPath(),
            'name' => $f->getClientOriginalName() ?: 'anhang.bin',
            'mime' => $f->getClientMimeType() ?: 'application/octet-stream',
        ], $files);

        try {
            Mail::to($msg->sender_email)->send(
                new PortalReplyMail($msg, $recipient, (string) $request->input('reply_text'), $fileMeta)
            );
        } catch (\Throwable $e) {
            Log::error('Portal-Antwort: Versand fehlgeschlagen', ['error' => $e->getMessage()]);

            return redirect('/m/'.$token)->with('reply_error',
                'Die Antwort konnte nicht übermittelt werden. Bitte versuchen Sie es später erneut.');
        }

        AuditEvent::log('reply_sent', $msg, $recipient, $request->ip(), [
            'files' => array_column($fileMeta, 'name'),
            'size_bytes' => $total,
        ]);

        return redirect('/m/'.$token)->with('reply_ok',
            'Ihre Antwort wurde sicher an '.$msg->sender_email.' übermittelt.');
    }

    private function repliesUsed(MessageRecipient $recipient): int
    {
        return AuditEvent::where('event', 'reply_sent')
            ->where('message_recipient_id', $recipient->id)
            ->count();
    }

    private function maxReplies(): int
    {
        return (int) Setting::get('reply_max_per_message', config('mailgateway.reply_max_per_message'));
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
