<?php

namespace App\Livewire\Admin;

use App\Models\EntraUser;
use App\Models\Setting;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('admin.layout')]
#[Title('Benutzer')]
class EntraUsers extends Component
{
    use WithPagination;

    #[Url]
    public string $q = '';

    public function updating($name): void
    {
        if ($name === 'q') {
            $this->resetPage();
        }
    }

    public function sync(): void
    {
        $code = Artisan::call('entra:sync');
        $output = trim(Artisan::output());

        if ($code === 0) {
            session()->flash('ok', $output !== '' ? $output : 'Synchronisation abgeschlossen.');
        } else {
            session()->flash('err', $output !== '' ? $output : 'Synchronisation fehlgeschlagen — Details im Laravel-Log.');
        }
    }

    public function render()
    {
        $query = EntraUser::orderBy('display_name');

        if ($this->q !== '') {
            $like = '%'.$this->q.'%';
            $query->where(fn ($w) => $w
                ->where('display_name', 'like', $like)
                ->orWhere('mail', 'like', $like)
                ->orWhere('department', 'like', $like)
                ->orWhere('job_title', 'like', $like));
        }

        return view('livewire.admin.entra-users', [
            'users' => $query->paginate(50),
            'total' => EntraUser::count(),
            'lastSync' => Setting::get('entra_last_sync'),
            'missingTitle' => EntraUser::whereNull('job_title')->count(),
            'missingPhone' => EntraUser::whereNull('business_phone')->count(),
        ]);
    }
}
