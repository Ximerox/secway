<?php

namespace App\Services;

use App\Models\SendRule;
use App\Models\SmimeCertificate;
use App\Support\InternalDomains;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Bewertet eine ausgehende Mail (Betreff, Text, Anhang-Dateinamen, Empfänger)
 * nach konfigurierbaren Regeln und liefert einen Score. Liegt er über dem
 * Schwellwert, soll der Nutzer im Outlook-Add-in gefragt werden, ob er die
 * Mail „sicher" versenden will.
 *
 * Es werden bewusst nur Metadaten/Score protokolliert, nie Mailinhalte.
 */
class SendClassifier
{
    /** Knappe, deutschsprachige System-Anweisung für den lokalen LLM. */
    private const LLM_SYSTEM =
        'Datenschutz-Filter Kinder-/Jugendhilfe. Enthält die Mail schutzbedürftige '
        .'personenbezogene Daten konkreter Betroffener (Gesundheit, Soziales, Familie, '
        .'Name+Geburtsdatum/Adresse, Diagnose, Hilfe-/Sorgerecht/Kindeswohl)? '
        .'Organisatorisch/technisch/allgemein = nein. Antworte NUR JSON: '
        .'{"sensibel":true|false,"score":0-100}';


    /**
     * @param  array{subject?:string, body?:string, attachments?:array<int,string>, recipients?:array<int,string>}  $input
     * @return array{ask:bool, score:int, hits:array<int,array{id:int,name:string,score:int}>, smimeCovered:bool, recipientCount:int, externalCount:int}
     */
    public function classify(array $input, int $threshold, bool $smimeException): array
    {
        $subject = (string) ($input['subject'] ?? '');
        $body = (string) ($input['body'] ?? '');
        $attachments = array_map('strval', $input['attachments'] ?? []);
        $recipients = array_values(array_filter(array_map('strval', $input['recipients'] ?? [])));

        $external = array_values(array_filter($recipients, fn ($r) => ! InternalDomains::isInternal($r)));

        $base = [
            'score' => 0, 'hits' => [], 'smimeCovered' => false, 'internalOnly' => false,
            'recipientCount' => count($recipients), 'externalCount' => count($external),
        ];

        // Nur-intern-Ausnahme: verlässt die Mail das Haus nicht, gibt es nichts
        // zu verschlüsseln — weder prüfen noch fragen.
        if ($recipients !== [] && $external === []) {
            return ['ask' => false, ...$base, 'internalOnly' => true];
        }

        // Positiv-Ausnahme: gehen alle (externen) Empfänger an zertifikatsgedeckte
        // Adressen, wird ohnehin verschlüsselt — keine Frage nötig.
        if ($smimeException && $external !== []
            && collect($external)->every(fn ($r) => SmimeCertificate::forRecipient($r) !== null)) {
            return ['ask' => false, ...$base, 'smimeCovered' => true];
        }

        $haystack = mb_strtolower($subject."\n".$body);
        $names = array_map('mb_strtolower', $attachments);

        $score = 0;
        $hits = [];
        foreach (SendRule::where('active', true)->get() as $rule) {
            $contribution = $this->evaluate($rule, $haystack, $names, $subject, $body);
            if ($contribution > 0) {
                $score += $contribution;
                $hits[] = ['id' => $rule->id, 'name' => $rule->name, 'score' => $contribution];
            }
        }

        return ['ask' => $score >= $threshold, 'score' => $score, 'hits' => $hits, ...array_slice($base, 2)];
    }

    /** Punktebeitrag einer einzelnen Regel (0 = kein Treffer). */
    private function evaluate(SendRule $rule, string $haystack, array $attachmentNames, string $subject, string $body): int
    {
        return match ($rule->type) {
            'attachment_name' => $this->matchAny($rule->termList(), $attachmentNames) ? $rule->score : 0,
            // Reagiert allein auf die Existenz eines echten Anhangs. Inline-Bilder
            // (z. B. Signatur-Logos) sendet das Add-in gar nicht erst mit — die
            // Liste enthält also nur nicht-inline Dateianhänge.
            'attachment_any' => $attachmentNames !== [] ? $rule->score : 0,
            'keyword' => $this->countTerms($rule->termList(), $haystack) >= max(1, $rule->threshold) ? $rule->score : 0,
            'birthdate' => $this->hasPastDate($haystack, max(0, $rule->threshold)) ? $rule->score : 0,
            'llm' => $this->evaluateLlm($subject, $body) ? $rule->score : 0,
            default => 0,
        };
    }

    /**
     * Lokaler LLM (llama.cpp): stuft die Mail als schutzbedürftig ein?
     * Fail-safe: Bei Nichterreichbarkeit/Timeout/Fehler → false (kein Beitrag),
     * damit die Klassifizierung nie am LLM hängen bleibt. Body gedeckelt, um
     * die Latenz im Zeitbudget des Add-ins zu halten.
     */
    private function evaluateLlm(string $subject, string $body): bool
    {
        $endpoint = (string) config('mailgateway.llm_endpoint');
        if ($endpoint === '') {
            return false;
        }
        try {
            $resp = Http::timeout((int) config('mailgateway.llm_timeout', 3))->post($endpoint, [
                'temperature' => 0,
                'max_tokens' => 24,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => self::LLM_SYSTEM],
                    ['role' => 'user', 'content' => 'Betreff: '.$subject."\n".mb_substr($body, 0, 6000)],
                ],
            ]);
            if (! $resp->successful()) {
                return false;
            }
            $verdict = json_decode((string) data_get($resp->json(), 'choices.0.message.content', ''), true);

            return is_array($verdict) && ! empty($verdict['sensibel']);
        } catch (\Throwable $e) {
            Log::warning('LLM-Klassifizierung nicht verfügbar', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function matchAny(array $terms, array $names): bool
    {
        foreach ($terms as $t) {
            foreach ($names as $n) {
                if ($t !== '' && str_contains($n, $t)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Anzahl verschiedener Begriffe aus der Liste, die im Text vorkommen. */
    private function countTerms(array $terms, string $haystack): int
    {
        $n = 0;
        foreach (array_unique($terms) as $t) {
            if ($t !== '' && str_contains($haystack, $t)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Erkennt ein Geburtsdatum: ein Datum mit vierstelligem Jahr (T.M.JJJJ),
     * das mindestens $minAgeYears in der Vergangenheit liegt — grenzt so von
     * künftigen Terminen ab. Datumsangaben ohne Jahr sind mehrdeutig
     * (Vergangenheit/Zukunft nicht bestimmbar) und werden bewusst ignoriert.
     */
    private function hasPastDate(string $haystack, int $minAgeYears): bool
    {
        if (! preg_match_all('/\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b/', $haystack, $m, PREG_SET_ORDER)) {
            return false;
        }
        $cutoff = Carbon::now()->subYears($minAgeYears)->endOfDay();
        foreach ($m as $set) {
            [$d, $mo, $y] = [(int) $set[1], (int) $set[2], (int) $set[3]];
            if ($d < 1 || $d > 31 || $mo < 1 || $mo > 12 || $y < 1900 || ! checkdate($mo, $d, $y)) {
                continue;
            }
            $date = Carbon::create($y, $mo, $d);
            if ($date !== null && $date->lte($cutoff)) {
                return true;
            }
        }

        return false;
    }
}
