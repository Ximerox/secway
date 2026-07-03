<?php

namespace App\Livewire\Admin;

use App\Models\AuditEvent;
use App\Models\MessageRecipient;
use App\Models\SecureMessage;
use App\Models\SmimeCertificate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('admin.layout')]
#[Title('Statistik')]
class Stats extends Component
{
    #[Url]
    public int $days = 30;

    /** Kategorien für Diagramm und Kennzahlen: Ereignis => [Label, Farbe] */
    public const CATEGORIES = [
        'smime_sent' => ['S/MIME verschlüsselt', '#1d4e89'],
        'ingest_stored' => ['Portal', '#d97706'],
        'passed_through' => ['Durchleitung', '#94a3b8'],
        'inbound_processed' => ['Eingehend entschlüsselt', '#059669'],
    ];

    public function render()
    {
        $days = in_array($this->days, [7, 30, 90], true) ? $this->days : 30;
        $from = now()->subDays($days - 1)->startOfDay();

        // Tageswerte für das gestapelte Balkendiagramm
        $raw = AuditEvent::selectRaw('DATE(created_at) AS d, event, COUNT(*) AS c')
            ->whereIn('event', array_keys(self::CATEGORIES))
            ->where('created_at', '>=', $from)
            ->groupByRaw('DATE(created_at), event')
            ->get();

        $chart = [];
        for ($i = 0; $i < $days; $i++) {
            $chart[now()->subDays($days - 1 - $i)->format('Y-m-d')] = array_fill_keys(array_keys(self::CATEGORIES), 0);
        }
        foreach ($raw as $r) {
            if (isset($chart[$r->d])) {
                $chart[$r->d][$r->event] = (int) $r->c;
            }
        }
        $chartMax = max([1, ...array_values(array_map('array_sum', $chart))]);

        $totals = [];
        foreach (array_keys(self::CATEGORIES) as $ev) {
            $totals[$ev] = array_sum(array_column($chart, $ev));
        }
        $outTotal = $totals['smime_sent'] + $totals['ingest_stored'] + $totals['passed_through'];

        $countEvent = fn (array $events) => AuditEvent::whereIn('event', $events)->where('created_at', '>=', $from)->count();

        // Portal-Abrufverhalten (Empfänger, die im Zeitraum benachrichtigt wurden)
        $rTotal = MessageRecipient::where('created_at', '>=', $from)->count();
        $rViewed = MessageRecipient::where('created_at', '>=', $from)->whereNotNull('first_viewed_at')->count();
        $avgMinutes = MessageRecipient::where('created_at', '>=', $from)
            ->whereNotNull('first_viewed_at')->whereNotNull('notified_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, notified_at, first_viewed_at)) AS m')
            ->value('m');

        // Top-Listen aus Audit-Details (Volumen klein genug für PHP-Aggregation)
        $topRecipientDomains = [];
        $topSenders = [];
        AuditEvent::whereIn('event', ['smime_sent', 'passed_through'])
            ->where('created_at', '>=', $from)
            ->get()
            ->each(function ($e) use (&$topRecipientDomains, &$topSenders) {
                foreach ($e->details['recipients'] ?? [] as $addr) {
                    $this->bump($topRecipientDomains, strtolower(substr((string) strrchr($addr, '@'), 1)));
                }
                if (! empty($e->details['sender'])) {
                    $this->bump($topSenders, strtolower($e->details['sender']));
                }
            });
        MessageRecipient::where('created_at', '>=', $from)->pluck('email')
            ->each(fn ($a) => $this->bump($topRecipientDomains, strtolower(substr((string) strrchr($a, '@'), 1))));
        SecureMessage::where('created_at', '>=', $from)->pluck('sender_email')
            ->each(fn ($a) => $this->bump($topSenders, strtolower($a)));
        arsort($topRecipientDomains);
        arsort($topSenders);

        return view('livewire.admin.stats', [
            'daysShown' => $days,
            'chart' => $chart,
            'chartMax' => $chartMax,
            'totals' => $totals,
            'outTotal' => $outTotal,
            'rejected' => $countEvent(['ingest_rejected', 'ingest_loop_dropped', 'ingest_dropped_bounce']),
            'harvested' => $countEvent(['cert_harvested']),
            'reminders' => $countEvent(['reminder_sent']),
            'unlockFails' => $countEvent(['unlock_failed']),
            'downloads' => $countEvent(['downloaded']),
            'rTotal' => $rTotal,
            'rViewed' => $rViewed,
            'avgMinutes' => $avgMinutes !== null ? (int) $avgMinutes : null,
            'topRecipientDomains' => array_slice($topRecipientDomains, 0, 8, true),
            'topSenders' => array_slice($topSenders, 0, 8, true),
            'storeCount' => SecureMessage::count(),
            'storeBytes' => (int) SecureMessage::sum('size_bytes'),
            'certsPartner' => SmimeCertificate::where('type', 'partner')->where('active', true)->count(),
            'certsHarvested' => SmimeCertificate::where('source', 'harvested')->where('active', true)->count(),
            'expiring' => SmimeCertificate::where('active', true)
                ->whereBetween('valid_until', [now(), now()->addDays(60)])
                ->orderBy('valid_until')->get(),
        ]);
    }

    private function bump(array &$arr, string $key): void
    {
        if ($key !== '') {
            $arr[$key] = ($arr[$key] ?? 0) + 1;
        }
    }
}
