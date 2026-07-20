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

    public int $reminder_before_expiry_hours = 0;

    public bool $reply_enabled = false;

    public int $reply_max_size_mb = 20;

    public int $reply_max_per_message = 5;

    public bool $inbound_hold_enabled = false;

    public int $inbound_hold_hours = 72;

    public string $admin_notify_email = '';

    public string $internal_domains = '';

    public string $operator_name = '';

    public string $legal_impressum = '';

    public string $legal_datenschutz = '';

    public function mount(): void
    {
        $this->subject_tag = (string) Setting::get('subject_tag', config('mailgateway.subject_tag'));
        $this->smime_auto = Setting::getBool('smime_auto', true);
        $this->smime_sign = Setting::getBool('smime_sign', true);
        $this->retention_days = (int) Setting::get('retention_days', config('mailgateway.retention_days'));
        $this->password_delay_minutes = (int) Setting::get('password_delay_minutes', config('mailgateway.password_delay_minutes'));
        $this->reminder_after_hours = (int) Setting::get('reminder_after_hours', config('mailgateway.reminder_after_hours'));
        $this->reminder_before_expiry_hours = (int) Setting::get('reminder_before_expiry_hours', config('mailgateway.reminder_before_expiry_hours'));
        $this->reply_enabled = Setting::getBool('reply_enabled', (bool) config('mailgateway.reply_enabled'));
        $this->reply_max_size_mb = (int) Setting::get('reply_max_size_mb', config('mailgateway.reply_max_size_mb'));
        $this->reply_max_per_message = (int) Setting::get('reply_max_per_message', config('mailgateway.reply_max_per_message'));
        $this->inbound_hold_enabled = Setting::getBool('inbound_hold_enabled', (bool) config('mailgateway.inbound_hold_enabled'));
        $this->inbound_hold_hours = (int) Setting::get('inbound_hold_hours', config('mailgateway.inbound_hold_hours'));
        $this->admin_notify_email = (string) Setting::get('admin_notify_email', config('mailgateway.admin_notify_email'));
        $this->internal_domains = (string) Setting::get('internal_domains', config('mailgateway.internal_domains'));
        $this->operator_name = Setting::operator();
        $this->legal_impressum = (string) Setting::get('legal_impressum', '');
        $this->legal_datenschutz = (string) Setting::get('legal_datenschutz', '');
    }

    public function save(): void
    {
        $this->validate([
            'subject_tag' => 'required|string|min:2|max:50',
            'retention_days' => 'required|integer|min:1|max:365',
            'password_delay_minutes' => 'required|integer|min:0|max:60',
            'reminder_after_hours' => 'required|integer|min:0|max:2160',
            'reminder_before_expiry_hours' => 'required|integer|min:0|max:2160',
            'reply_max_size_mb' => 'required|integer|min:1|max:50',
            'reply_max_per_message' => 'required|integer|min:1|max:50',
            'inbound_hold_hours' => 'required|integer|min:1|max:720',
            'admin_notify_email' => 'nullable|email',
            'internal_domains' => ['required', 'regex:/^[a-z0-9.-]+(\s*,\s*[a-z0-9.-]+)*$/i'],
            'operator_name' => 'required|string|min:2|max:100',
            'legal_impressum' => 'nullable|string|max:60000',
            'legal_datenschutz' => 'nullable|string|max:60000',
        ], [
            'internal_domains.required' => 'Mindestens eine interne Domain wird benötigt (z.B. example.org).',
            'internal_domains.regex' => 'Bitte Domains kommagetrennt angeben, z.B.: example.org, zweite-domain.de',
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
        Setting::set('reminder_before_expiry_hours', $this->reminder_before_expiry_hours);
        Setting::set('reply_enabled', $this->reply_enabled);
        Setting::set('reply_max_size_mb', $this->reply_max_size_mb);
        Setting::set('reply_max_per_message', $this->reply_max_per_message);
        Setting::set('inbound_hold_enabled', $this->inbound_hold_enabled);
        Setting::set('inbound_hold_hours', $this->inbound_hold_hours);
        Setting::set('admin_notify_email', trim($this->admin_notify_email));
        Setting::set('internal_domains', strtolower(trim($this->internal_domains)));
        Setting::set('operator_name', trim($this->operator_name));
        Setting::set('legal_impressum', trim($this->legal_impressum));
        Setting::set('legal_datenschutz', trim($this->legal_datenschutz));

        AuditEvent::log('settings_changed', ip: request()->ip(), details: [
            'subject_tag' => trim($this->subject_tag),
            'smime_auto' => $this->smime_auto,
            'smime_sign' => $this->smime_sign,
            'retention_days' => $this->retention_days,
            'password_delay_minutes' => $this->password_delay_minutes,
            'reminder_after_hours' => $this->reminder_after_hours,
            'reminder_before_expiry_hours' => $this->reminder_before_expiry_hours,
            'reply_enabled' => $this->reply_enabled,
            'reply_max_size_mb' => $this->reply_max_size_mb,
            'reply_max_per_message' => $this->reply_max_per_message,
            'inbound_hold_enabled' => $this->inbound_hold_enabled,
            'inbound_hold_hours' => $this->inbound_hold_hours,
            'admin_notify_email' => trim($this->admin_notify_email),
            'internal_domains' => strtolower(trim($this->internal_domains)),
            'operator_name' => trim($this->operator_name),
        ]);

        session()->flash('ok', 'Einstellungen gespeichert. Sie gelten sofort für alle neu eingehenden Mails.');
    }

    public function render()
    {
        return view('livewire.admin.settings');
    }
}
