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
    /**
     * Knappe, deutschsprachige System-Anweisung für den lokalen LLM.
     * Zweck ist KLIENTENSCHUTZ: rein private Mails der Mitarbeiter (eigene
     * Gesundheit/Familie/Termine in Ich-Form) sollen NIEDRIG bewertet werden,
     * damit sie nicht fälschlich abgesichert/markiert werden — Fallmails auch
     * in Ich-Form („Ich habe heute mit Lena gesprochen …") bleiben sensibel.
     * Gemessen 13.07.2026 (eval_prompt.py, 12 Fälle inkl. privat/Ich-Form):
     * 7B 12/12 (privat 20–25, Fall 75–95), 3B 10/12. Text EXAKT wie getestet
     * lassen (bewusst ohne Umlaute); Änderungen erst nach neuer Messung.
     */
    private const LLM_SYSTEM =
        'Datenschutz-Filter einer Kinder-/Jugendhilfe-Einrichtung. Zweck: NUR Klientendaten schuetzen. '
        .'sensibel=true nur, wenn die Mail schutzbeduerftige Daten von KLIENTEN/betreuten Personen '
        .'im Fall-/Fachkontext enthaelt (Gesundheit, Diagnose, Familie, Sorgerecht, Kindeswohl, '
        .'Hilfeplan, Name+Geburtsdatum). Private Angelegenheiten des Absenders und seiner EIGENEN '
        .'Familie (eigene Termine, eigene Gesundheit, eigene Kinder, eigene Rechnungen) sowie '
        .'Organisatorisches/Technisches = nein, score niedrig. Antworte NUR JSON: '
        .'{"sensibel":true|false,"score":0-100}';


    /**
     * @param  array{subject?:string, body?:string, attachments?:array<int,string>, recipients?:array<int,string>}  $input
     * @return array{ask:bool, score:int, hits:array<int,array{id:int,name:string,score:int}>, smimeCovered:bool, recipientCount:int, externalCount:int}
     */
    public function classify(array $input, int $threshold, bool $smimeException, bool $review = false): array
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
        $breakdown = [];
        foreach (SendRule::where('active', true)->get() as $rule) {
            // Zwei Score-Werte je Regel: im Plugin-Modus zählt `score`, in der
            // nachgelagerten Prüfung `review_score`. So lässt sich das Netz
            // strenger/lockerer gewichten als die Live-Rückfrage.
            $ruleScore = $review ? (int) $rule->review_score : (int) $rule->score;
            $extra = [];
            if ($rule->type === 'llm') {
                // Beitrag = feste Punkte bei „sensibel = ja" PLUS Faktor (%) auf
                // den eigenen 0–100-Score des Modells. Beides 0 → die KI zählt
                // nicht mit. Nachgelagert nutzt das „gute" (größere) Modell
                // (reviewSensitive) und den eigenen Faktor review_threshold —
                // das kleine Plugin-Modell und das große lassen sich so
                // unterschiedlich gewichten.
                $verdict = $review ? $this->reviewSensitive($subject, $body) : $this->evaluateLlm($subject, $body);
                $factor = max(0, (int) ($review ? $rule->review_threshold : $rule->threshold));
                $contribution = 0;
                if ($verdict !== null) {
                    if ($verdict['sensibel']) {
                        $contribution += $ruleScore;
                    }
                    $contribution += (int) round($factor / 100 * $verdict['score']);
                }
                $extra = [
                    'llm_available' => $verdict !== null,
                    'llm_sensibel' => $verdict['sensibel'] ?? null,
                    'llm_score' => $verdict['score'] ?? null,
                    'llm_factor' => $factor,
                ];
            } else {
                $contribution = $this->evaluate($rule, $haystack, $names, $subject, $body, $ruleScore);
            }

            // Vollständige Einzelwertung (auch 0) — für den Diagnose-Modus.
            $breakdown[] = array_merge([
                'id' => $rule->id, 'name' => $rule->name, 'type' => $rule->type,
                'max' => $ruleScore, 'contribution' => $contribution,
            ], $extra);

            if ($contribution > 0) {
                $score += $contribution;
                $hits[] = ['id' => $rule->id, 'name' => $rule->name, 'score' => $contribution];
            }
        }

        return ['ask' => $score >= $threshold, 'score' => $score, 'hits' => $hits, 'breakdown' => $breakdown, ...array_slice($base, 2)];
    }

    /** Punktebeitrag einer einzelnen Regel (0 = kein Treffer). */
    private function evaluate(SendRule $rule, string $haystack, array $attachmentNames, string $subject, string $body, int $ruleScore): int
    {
        return match ($rule->type) {
            'attachment_name' => $this->matchAny($rule->termList(), $attachmentNames) ? $ruleScore : 0,
            // Reagiert allein auf die Existenz eines echten Anhangs. Inline-Bilder
            // (z. B. Signatur-Logos) sendet das Add-in gar nicht erst mit — die
            // Liste enthält also nur nicht-inline Dateianhänge.
            'attachment_any' => $attachmentNames !== [] ? $ruleScore : 0,
            'keyword' => $this->countTerms($rule->termList(), $haystack) >= max(1, $rule->threshold) ? $ruleScore : 0,
            'birthdate' => $this->hasPastDate($haystack, max(0, $rule->threshold)) ? $ruleScore : 0,
            default => 0, // 'llm' wird in classify() gesondert behandelt
        };
    }

    /**
     * Lokaler LLM (llama.cpp): liefert das Urteil des Modells als
     * ['sensibel' => bool, 'score' => 0-100] — oder null, wenn der Dienst
     * nicht erreichbar/fehlerhaft ist (Fail-safe: der Aufrufer wertet null
     * als „kein Beitrag", die Klassifizierung hängt nie am LLM). Body
     * gedeckelt, um die Latenz im Zeitbudget des Add-ins zu halten.
     *
     * @return array{sensibel: bool, score: int}|null
     */
    private function evaluateLlm(string $subject, string $body): ?array
    {
        return $this->callLlm(
            (string) config('mailgateway.llm_endpoint'),
            (int) config('mailgateway.llm_timeout', 3),
            $subject,
            $body,
        );
    }

    /**
     * Nachgelagerte Zweitmeinung durch das „gute" (größere) Modell auf einem
     * eigenen Endpunkt. Wird vom Gateway (MailIngest) auf Mails angewandt, die
     * sonst unverschlüsselt rausgingen — bei hohem Score sichert das Gateway
     * die Mail nachträglich ab. Gleicher Fail-safe wie evaluateLlm: null =
     * Dienst weg → keine Aktion, die Zustellung hängt nie am LLM.
     *
     * @return array{sensibel: bool, score: int}|null
     */
    public function reviewSensitive(string $subject, string $body): ?array
    {
        return $this->callLlm(
            (string) config('mailgateway.llm_review_endpoint'),
            (int) config('mailgateway.llm_review_timeout', 6),
            $subject,
            $body,
        );
    }

    /** Gemeinsamer LLM-Aufruf für Add-in-Prüfung und nachgelagerte Zweitmeinung. */
    private function callLlm(string $endpoint, int $timeout, string $subject, string $body): ?array
    {
        if ($endpoint === '') {
            return null;
        }
        try {
            $resp = Http::timeout(max(1, $timeout))->post($endpoint, [
                'temperature' => 0,
                'max_tokens' => 24,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => self::LLM_SYSTEM],
                    ['role' => 'user', 'content' => 'Betreff: '.$subject."\n".mb_substr($body, 0, 6000)],
                ],
            ]);
            if (! $resp->successful()) {
                return null;
            }
            $verdict = json_decode((string) data_get($resp->json(), 'choices.0.message.content', ''), true);
            if (! is_array($verdict)) {
                return null;
            }

            return [
                'sensibel' => ! empty($verdict['sensibel']),
                'score' => max(0, min(100, (int) ($verdict['score'] ?? 0))),
            ];
        } catch (\Throwable $e) {
            Log::warning('LLM-Klassifizierung nicht verfügbar', ['error' => $e->getMessage()]);

            return null;
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
