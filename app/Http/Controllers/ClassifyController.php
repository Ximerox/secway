<?php

namespace App\Http\Controllers;

use App\Models\SendClassifyLog;
use App\Models\Setting;
use App\Services\SendClassifier;
use App\Support\SubjectTag;
use Illuminate\Http\Request;

/**
 * Stateless-API für das Outlook-Add-in „Sicher versenden?".
 * Token-geschützt (Bearer = MGW_CLASSIFY_TOKEN), erreichbar über die
 * öffentliche Domain (LAN/VPN/Internet). Es werden nur Betreff, Text und
 * Anhang-DATEINAMEN übertragen — keine Bilder, keine Anhangsinhalte.
 */
class ClassifyController extends Controller
{
    public function classify(Request $request, SendClassifier $classifier)
    {
        $this->authorizeToken($request);

        if (! Setting::getBool('classify_enabled', false)) {
            return response()->json(['ask' => false, 'reason' => 'disabled']);
        }

        $data = $request->validate([
            'subject' => 'nullable|string|max:2000',
            'body' => 'nullable|string|max:200000',
            'attachments' => 'nullable|array',
            'attachments.*' => 'string|max:400',
            'recipients' => 'nullable|array',
            'recipients.*' => 'string|max:320',
        ]);

        $tag = (string) Setting::get('subject_tag', config('mailgateway.subject_tag'));

        // Hat der Absender den Tag schon gesetzt, ist die Entscheidung getroffen.
        if (SubjectTag::contains((string) ($data['subject'] ?? ''))) {
            return response()->json(['ask' => false, 'reason' => 'already_tagged', 'tag' => $tag]);
        }

        $threshold = (int) Setting::get('classify_threshold', 60);
        $smimeException = Setting::getBool('classify_smime_exception', true);

        $r = $classifier->classify($data, $threshold, $smimeException);

        // Nur-intern-Mails: nicht fragen und bewusst NICHT protokollieren —
        // sie sind die große Masse und würden die Auswertung fluten.
        if ($r['internalOnly'] ?? false) {
            return response()->json(['ask' => false, 'reason' => 'internal_only']);
        }

        $entry = [
            'score' => $r['score'],
            'asked' => $r['ask'],
            'rule_hits' => $r['hits'],
            'recipient_count' => $r['recipientCount'],
            'external_count' => $r['externalCount'],
            'smime_covered' => $r['smimeCovered'],
        ];

        // Diagnose-/Lernmodus (bewusst aktivierbar): kompletter Text, Anhang-
        // namen und die Einzelwertung ALLER Regeln mitschreiben, um zu sehen,
        // warum eine Mail (nicht) über der Schwelle lag. Enthält echten
        // Mailinhalt — nur im Admin sichtbar, jederzeit löschbar.
        if (Setting::getBool('classify_debug', false)) {
            $entry['debug_subject'] = mb_substr((string) ($data['subject'] ?? ''), 0, 2000);
            $entry['debug_body'] = mb_substr((string) ($data['body'] ?? ''), 0, 20000);
            $entry['debug_attachments'] = array_values($data['attachments'] ?? []);
            $entry['debug_rules'] = $r['breakdown'];
        }

        $log = SendClassifyLog::create($entry);

        return response()->json([
            'ask' => $r['ask'],
            'score' => $r['score'],
            'logId' => $log->id,
            'tag' => $tag,
        ]);
    }

    /** Aktuelles Betreff-Tag für den Ribbon-Button „Sicher senden". */
    public function subjectTag(Request $request)
    {
        $this->authorizeToken($request);

        return response()->json([
            'tag' => (string) Setting::get('subject_tag', config('mailgateway.subject_tag')),
        ]);
    }

    /** Feedback des Add-ins: was der Nutzer nach der Frage gewählt hat. */
    public function choice(Request $request, SendClassifyLog $log)
    {
        $this->authorizeToken($request);
        $data = $request->validate(['choice' => 'required|in:secure,normal']);
        $log->update(['user_choice' => $data['choice']]);

        return response()->json(['ok' => true]);
    }

    private function authorizeToken(Request $request): void
    {
        $token = (string) config('mailgateway.classify_token');
        abort_if($token === '' || ! hash_equals($token, (string) $request->bearerToken()), 401, 'Ungültiges Token.');
    }
}
