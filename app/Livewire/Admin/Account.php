<?php

namespace App\Livewire\Admin;

use App\Models\AuditEvent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('admin.layout')]
#[Title('Konto')]
class Account extends Component
{
    public string $username = '';

    public string $name = '';

    public string $current_password = '';

    public string $new_password = '';

    public string $new_password_confirmation = '';

    public function mount(): void
    {
        $this->username = (string) Auth::user()->username;
        $this->name = (string) Auth::user()->name;
    }

    public function saveProfile(): void
    {
        $user = Auth::user();

        $this->validate([
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($user->id)],
            'name' => ['required', 'string', 'max:100'],
        ], [
            'username.required' => 'Bitte einen Benutzernamen angeben.',
            'username.regex' => 'Nur Buchstaben, Ziffern, Punkt, Bindestrich und Unterstrich erlaubt.',
            'username.unique' => 'Dieser Benutzername ist bereits vergeben.',
            'name.required' => 'Bitte einen Anzeigenamen angeben.',
        ]);

        $user->update(['username' => trim($this->username), 'name' => trim($this->name)]);
        AuditEvent::log('admin_profile_changed', ip: request()->ip(), details: ['username' => $user->username]);

        session()->flash('ok', 'Profil gespeichert. Der Benutzername gilt ab der nächsten Anmeldung.');
    }

    public function changePassword(): void
    {
        $user = Auth::user();

        $this->validate([
            'current_password' => ['required'],
            'new_password' => ['required', 'string', 'min:10', 'confirmed'],
        ], [
            'current_password.required' => 'Bitte das aktuelle Kennwort eingeben.',
            'new_password.required' => 'Bitte ein neues Kennwort eingeben.',
            'new_password.min' => 'Das neue Kennwort muss mindestens 10 Zeichen haben.',
            'new_password.confirmed' => 'Die Kennwort-Wiederholung stimmt nicht überein.',
        ]);

        if (! Hash::check($this->current_password, $user->password)) {
            $this->addError('current_password', 'Das aktuelle Kennwort ist falsch.');

            return;
        }

        $user->update(['password' => $this->new_password]); // Cast 'hashed' übernimmt das Hashing
        $this->reset('current_password', 'new_password', 'new_password_confirmation');
        AuditEvent::log('admin_password_changed', ip: request()->ip(), details: ['username' => $user->username]);

        session()->flash('ok', 'Kennwort geändert.');
    }

    public function render()
    {
        return view('livewire.admin.account');
    }
}
