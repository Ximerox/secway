<?php

namespace App\Livewire\Admin;

use App\Models\AuditEvent;
use App\Models\SendClassifyLog;
use App\Models\SendRule;
use App\Models\Setting;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('admin.layout')]
#[Title('Sicher versenden')]
class SendRules extends Component
{
    // Globale Einstellungen
    public bool $enabled = false;

    public int $threshold = 60;

    public bool $smime_exception = true;

    // Regel-Editor
    public ?int $editId = null;

    public string $name = '';

    public string $type = 'keyword';

    public string $terms = '';

    public int $rule_threshold = 1;

    public int $score = 50;

    public bool $active = true;

    public function mount(): void
    {
        $this->enabled = Setting::getBool('classify_enabled', false);
        $this->threshold = (int) Setting::get('classify_threshold', 60);
        $this->smime_exception = Setting::getBool('classify_smime_exception', true);
    }

    public function saveSettings(): void
    {
        $this->validate(['threshold' => 'required|integer|min:1|max:1000']);
        Setting::set('classify_enabled', $this->enabled);
        Setting::set('classify_threshold', $this->threshold);
        Setting::set('classify_smime_exception', $this->smime_exception);
        AuditEvent::log('settings_changed', ip: request()->ip(), details: [
            'classify_enabled' => $this->enabled, 'classify_threshold' => $this->threshold,
        ]);
        session()->flash('ok', 'Einstellungen gespeichert.');
    }

    public function newRule(): void
    {
        $this->resetValidation();
        $this->editId = 0;
        $this->name = '';
        $this->type = 'keyword';
        $this->terms = '';
        $this->rule_threshold = 1;
        $this->score = 50;
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
        $this->score = (int) $r->score;
        $this->active = $r->active;
    }

    public function saveRule(): void
    {
        $this->validate([
            'name' => 'required|string|min:2|max:100',
            'type' => 'in:attachment_name,keyword,birthdate,llm',
            'terms' => 'required_unless:type,birthdate,llm|nullable|string|max:5000',
            'rule_threshold' => 'required|integer|min:0|max:100',
            'score' => 'required|integer|min:1|max:1000',
        ], [
            'terms.required_unless' => 'Bitte Begriffe angeben (komma- oder zeilengetrennt).',
        ]);

        $r = $this->editId ? SendRule::find($this->editId) : new SendRule;
        $r->fill([
            'name' => trim($this->name),
            'type' => $this->type,
            'terms' => in_array($this->type, ['birthdate', 'llm'], true) ? null : trim($this->terms),
            'threshold' => $this->rule_threshold,
            'score' => $this->score,
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
        $logs = SendClassifyLog::where('created_at', '>=', now()->subDays(90))->get();
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
        ]);
    }
}
