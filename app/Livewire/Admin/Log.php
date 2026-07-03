<?php

namespace App\Livewire\Admin;

use App\Models\AuditEvent;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('admin.layout')]
#[Title('Protokoll')]
class Log extends Component
{
    use WithPagination;

    #[Url]
    public string $direction = '';

    #[Url]
    public string $q = '';

    public function updating($name): void
    {
        if (in_array($name, ['direction', 'q'], true)) {
            $this->resetPage();
        }
    }

    public function render()
    {
        $query = AuditEvent::with(['message', 'recipient'])->orderByDesc('id');

        if ($this->q !== '') {
            $like = '%'.$this->q.'%';
            $query->where(fn ($w) => $w
                ->where('details', 'like', $like)
                ->orWhere('event', 'like', $like)
                ->orWhere('ip', 'like', $like));
        }

        $events = $query->paginate(60);

        // Nachträglich nach Richtung filtern (abgeleitet, keine DB-Spalte)
        if ($this->direction !== '') {
            $events->setCollection(
                $events->getCollection()->filter(fn ($e) => $e->direction() === $this->direction)->values()
            );
        }

        return view('livewire.admin.log', ['events' => $events]);
    }
}
