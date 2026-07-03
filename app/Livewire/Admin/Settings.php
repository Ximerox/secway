<?php

namespace App\Livewire\Admin;

use App\Models\AuditEvent;
use App\Models\Setting;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('admin.layout')]
#[Title('Einstellungen')]
class Settings extends Component
{
    public string $subject_tag = '';

    public bool $smime_auto = true;

    public bool $smime_sign = true;

    public int $retention_days = 30;

    public int $password_delay_minutes = 2;

    public int $reminder_after_hours = 0;

    public string $internal_domains = '';

    public function mount(): void
    {
        $this->subject_tag = (string) Setting::get('subject_tag', config('mailgateway.subject_tag'));
        $this->smime_auto = Setting::getBool('smime_auto', true);
        $this->smime_sign = Setting::getBool('smime_sign', true);
        $this->retention_days = (int) Setting::get('retention_days', config('mailgateway.retention_days'));
        $this->password_delay_minutes = (int) Setting::get('password_delay_minutes', config('mailgateway.password_delay_minutes'));
        $this->reminder_after_hours = (int) Setting::get('reminder_after_hours', config('mailgateway.reminder_after_hours'));
        $this->internal_domains = (string) Setting::get('internal_domains', config('mailgateway.internal_domains'));
    }

    public function save(): void
    {
        $this->validate([
            'subject_tag' => 'required|string|min:2|max:50',
            'retention_days' => 'required|integer|min:1|max:365',
            'password_delay_minutes' => 'required|integer|min:0|max:60',
            'reminder_after_hours' => 'required|integer|min:0|max:2160',
            'internal_domains' => ['required', 'regex:/^[a-z0-9.-]+(\s*,\s*[a-z0-9.-]+)*$/i'],
        ], [
            'internal_domains.required' => 'Mindestens eine interne Domain wird benötigt (z.B. straphael.de).',
            'internal_domains.regex' => 'Bitte Domains kommagetrennt angeben, z.B.: straphael.de, zweite-domain.de',
            'subject_tag.required' => 'Das Tag darf nicht leer sein — sonst gäbe es keinen Portal-Auslöser mehr.',
            'subject_tag.min' => 'Das Tag sollte mindestens 2 Zeichen haben, um Fehlauslösungen zu vermeiden.',
            'retention_days.min' => 'Mindestens 1 Tag.',
            'retention_days.max' => 'Höchstens 365 Tage.',
        ]);

        Setting::set('subject_tag', trim($this->subject_tag));
        Setting::set('smime_auto', $this->smime_auto);
        Setting::set('smime_sign', $this->smime_sign);
        Setting::set('retention_days', $this->retention_days);
        Setting::set('password_delay_minutes', $this->password_delay_minutes);
        Setting::set('reminder_after_hours', $this->reminder_after_hours);
        Setting::set('internal_domains', strtolower(trim($this->internal_domains)));

        AuditEvent::log('settings_changed', ip: request()->ip(), details: [
            'subject_tag' => trim($this->subject_tag),
            'smime_auto' => $this->smime_auto,
            'smime_sign' => $this->smime_sign,
            'retention_days' => $this->retention_days,
            'password_delay_minutes' => $this->password_delay_minutes,
            'reminder_after_hours' => $this->reminder_after_hours,
            'internal_domains' => strtolower(trim($this->internal_domains)),
        ]);

        session()->flash('ok', 'Einstellungen gespeichert. Sie gelten sofort für alle neu eingehenden Mails.');
    }

    public function render()
    {
        return view('livewire.admin.settings');
    }
}
