<div>
    <h1>Konto</h1>

    @if (session('ok'))
        <div class="alert ok">{{ session('ok') }}</div>
    @endif

    <div class="card" style="max-width:560px;">
        <h2 style="margin-top:0;">Profil</h2>
        <form wire:submit="saveProfile">
            <label>Benutzername (für die Anmeldung)</label>
            <input type="text" wire:model="username" autocomplete="off">
            @error('username')<div class="error">{{ $message }}</div>@enderror

            <label>Anzeigename</label>
            <input type="text" wire:model="name">
            @error('name')<div class="error">{{ $message }}</div>@enderror

            <button type="submit" class="btn" wire:loading.attr="disabled">Profil speichern</button>
        </form>
    </div>

    <div class="card" style="max-width:560px;">
        <h2 style="margin-top:0;">Kennwort ändern</h2>
        <form wire:submit="changePassword">
            <label>Aktuelles Kennwort</label>
            <input type="password" wire:model="current_password" autocomplete="current-password">
            @error('current_password')<div class="error">{{ $message }}</div>@enderror

            <label>Neues Kennwort (mindestens 10 Zeichen)</label>
            <input type="password" wire:model="new_password" autocomplete="new-password">
            @error('new_password')<div class="error">{{ $message }}</div>@enderror

            <label>Neues Kennwort wiederholen</label>
            <input type="password" wire:model="new_password_confirmation" autocomplete="new-password">

            <button type="submit" class="btn" wire:loading.attr="disabled">Kennwort ändern</button>
        </form>
    </div>
</div>
