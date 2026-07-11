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

        // Richtung ist eine reine Funktion des Ereignisnamens — deshalb als
        // SQL-Filter VOR der Pagination (sonst stimmen Seitenzahl und Inhalt
        // nicht: leere Seiten trotz Treffern, Paginator zählt Ungefiltertes).
        if ($this->direction !== '') {
            $explicit = AuditEvent::eventsForDirection($this->direction);
            $query->where(function ($w) use ($explicit) {
                $w->whereIn('event', $explicit);
                if ($this->direction === 'System') {
                    // Präfix-Regel aus AuditEvent::direction(): admin_*/cert_*/
                    // settings_* gelten als System, sofern nicht explizit anders
                    // zugeordnet (z.B. cert_harvested => eingehend).
                    $w->orWhere(function ($p) {
                        $p->whereNotIn('event', AuditEvent::mappedEvents())
                            ->where(function ($x) {
                                $x->where('event', 'like', 'admin\_%')
                                    ->orWhere('event', 'like', 'cert\_%')
                                    ->orWhere('event', 'like', 'settings\_%');
                            });
                    });
                }
            });
        }

        $events = $query->paginate(60);

        return view('livewire.admin.log', ['events' => $events]);
    }
}
