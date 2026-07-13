<?php

namespace App\Livewire\Admin;

use App\Models\AuditEvent;
use App\Models\SendClassifyLog;
use App\Models\SendRule;
use App\Models\Setting;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('admin.layout')]
#[Title('Sicher versenden')]
class SendRules extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'regeln'; // regeln | diagnose

    // Globale Einstellungen
    public bool $enabled = false;

    public int $threshold = 60;

    public bool $smime_exception = true;

    public bool $debug = false;

    // Nachgelagerte KI-Prüfung (Gateway, unabhängig vom Add-in)
    public string $llm_mode = 'off';

    public int $review_threshold = 60;

    // Regel-Editor
    public ?int $editId = null;

    public string $name = '';

    public string $type = 'keyword';

    public string $terms = '';

    public int $rule_threshold = 1;

    // Nur bei Typ „llm" eigenständig: Faktor (%) für die nachgelagerte Prüfung.
    // Bei allen anderen Typen wird der Wert beim Speichern = rule_threshold gesetzt.
    public int $review_rule_threshold = 0;

    public int $score = 50;

    public int $review_score = 50;

    public bool $active = true;

    public function mount(): void
    {
        $this->enabled = Setting::getBool('classify_enabled', false);
        $this->threshold = (int) Setting::get('classify_threshold', 60);
        $this->smime_exception = Setting::getBool('classify_smime_exception', true);
        $this->debug = Setting::getBool('classify_debug', false);
        $this->llm_mode = Setting::llmReviewMode();
        // Fällt für Kontinuität auf den früheren Wert llm_review_score zurück,
        // solange classify_review_threshold noch nie gespeichert wurde.
        $this->review_threshold = (int) Setting::get('classify_review_threshold', (int) Setting::get('llm_review_score', 60));
    }

    public function saveSettings(): void
    {
        $this->validate([
            'threshold' => 'required|integer|min:1|max:1000',
            'llm_mode' => 'required|in:off,log,secure',
            'review_threshold' => 'required|integer|min:1|max:1000',
        ]);
        Setting::set('classify_enabled', $this->enabled);
        Setting::set('classify_threshold', $this->threshold);
        Setting::set('classify_smime_exception', $this->smime_exception);
        Setting::set('classify_debug', $this->debug);
        Setting::set('llm_review_mode', $this->llm_mode);
        Setting::set('classify_review_threshold', $this->review_threshold);
        AuditEvent::log('settings_changed', ip: request()->ip(), details: [
            'classify_enabled' => $this->enabled, 'classify_threshold' => $this->threshold,
            'classify_debug' => $this->debug,
            'llm_review_mode' => $this->llm_mode, 'classify_review_threshold' => $this->review_threshold,
        ]);
        session()->flash('ok', 'Einstellungen gespeichert.'.($this->debug ? ' Diagnose-Modus AKTIV — speichert Mailinhalte!' : ''));
    }

    /** Diagnose-Schalter im Diagnose-Tab: sofort speichern (kein Formular nötig). */
    public function updatedDebug(): void
    {
        Setting::set('classify_debug', $this->debug);
        AuditEvent::log('settings_changed', ip: request()->ip(), details: ['classify_debug' => $this->debug]);
        session()->flash('ok', $this->debug
            ? 'Diagnose-Modus AKTIV — es werden echte Mailinhalte gespeichert!'
            : 'Diagnose-Modus ausgeschaltet.');
    }

    /** Löscht die im Diagnose-Modus gespeicherten Mailinhalte (Metadaten bleiben). */
    public function purgeDebug(): void
    {
        SendClassifyLog::whereNotNull('debug_body')->update([
            'debug_subject' => null, 'debug_body' => null,
            'debug_attachments' => null, 'debug_rules' => null,
        ]);
        session()->flash('ok', 'Diagnose-Inhalte gelöscht.');
    }

    public function newRule(): void
    {
        $this->resetValidation();
        $this->editId = 0;
        $this->name = '';
        $this->type = 'keyword';
        $this->terms = '';
        $this->rule_threshold = 1;
        $this->review_rule_threshold = 0;
        $this->score = 50;
        $this->review_score = 50;
        $this->active = true;
    }

    public function edit(int $id): void
    {
        $r = SendRule::findOrFail($id);
        $this->resetValidation();
        $this->editId = $r->id;
        $this->name = $r->name;
        $this->type = $r->type;
        $this->terms = (string) $r->terms;
        $this->rule_threshold = (int) $r->threshold;
        $this->review_rule_threshold = (int) $r->review_threshold;
        $this->score = (int) $r->score;
        $this->review_score = (int) $r->review_score;
        $this->active = $r->active;
    }

    public function saveRule(): void
    {
        $this->validate([
            'name' => 'required|string|min:2|max:100',
            'type' => 'in:attachment_name,attachment_any,keyword,birthdate,llm',
            'terms' => 'required_unless:type,birthdate,llm,attachment_any|nullable|string|max:5000',
            'rule_threshold' => 'required|integer|min:0|max:100',
            'review_rule_threshold' => 'required|integer|min:0|max:100',
            'score' => 'required|integer|min:0|max:1000',
            'review_score' => 'required|integer|min:0|max:1000',
        ], [
            'terms.required_unless' => 'Bitte Begriffe angeben (komma- oder zeilengetrennt).',
        ]);

        $r = $this->editId ? SendRule::find($this->editId) : new SendRule;
        $r->fill([
            'name' => trim($this->name),
            'type' => $this->type,
            'terms' => in_array($this->type, ['birthdate', 'llm', 'attachment_any'], true) ? null : trim($this->terms),
            'threshold' => $this->rule_threshold,
            // Nur die KI-Regel hat einen eigenen Nachgelagert-Faktor; bei allen
            // anderen Typen ist das Kriterium (Mindesttreffer/-alter) in beiden
            // Modi identisch.
            'review_threshold' => $this->type === 'llm' ? $this->review_rule_threshold : $this->rule_threshold,
            'score' => $this->score,
            'review_score' => $this->review_score,
            'active' => $this->active,
        ])->save();

        $this->editId = null;
        session()->flash('ok', 'Regel „'.$r->name.'" gespeichert.');
    }

    public function delete(int $id): void
    {
        SendRule::findOrFail($id)->delete();
        session()->flash('ok', 'Regel gelöscht.');
    }

    public function cancel(): void
    {
        $this->editId = null;
    }

    public function render()
    {
        // Auswertung pro Regel aus dem Standard-Log (ohne Inhalte): wie oft
        // jede Regel angeschlagen hat bzw. eine Nachfrage ausgelöst hätte.
        // Hinweis: Outlooks eingebaute Sende-Rückfrage meldet die Nutzerwahl
        // nicht zurück (ein eigener Dialog ist im Sende-Ereignis nicht möglich)
        // — eine „sicher bestätigt"-Quote lässt sich daher nicht mehr messen.
        $logs = SendClassifyLog::where('source', 'addin')->where('created_at', '>=', now()->subDays(90))->get();
        $stats = [];
        foreach ($logs as $log) {
            foreach ($log->rule_hits ?? [] as $hit) {
                $id = $hit['id'] ?? null;
                if ($id === null) {
                    continue;
                }
                $stats[$id] ??= ['fired' => 0, 'asked' => 0];
                $stats[$id]['fired']++;
                if ($log->asked) {
                    $stats[$id]['asked']++;
                }
            }
        }

        return view('livewire.admin.send-rules', [
            'rules' => SendRule::orderByDesc('score')->orderBy('name')->get(),
            'stats' => $stats,
            'logSummary' => [
                'total' => $logs->count(),
                'asked' => $logs->where('asked', true)->count(),
                'smime' => $logs->where('smime_covered', true)->count(),
            ],
            'debugLogs' => SendClassifyLog::where('source', 'addin')->whereNotNull('debug_body')
                ->orderByDesc('id')->paginate(15, ['*'], 'dbg'),
            // Nachgelagerte Prüfungen: Inhalte existieren nur 7 Tage (danach
            // automatisch entfernt), daher schlicht alle mit Inhalt, blätterbar.
            'reviewLogs' => SendClassifyLog::where('source', 'review')->whereNotNull('debug_body')
                ->orderByDesc('id')->paginate(15, ['*'], 'rev'),
            'reviewThreshold' => (int) Setting::get('classify_review_threshold', 60),
        ]);
    }
}
