<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class SignatureTemplate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
        'continue_processing' => 'boolean',
        'valid_from' => 'date',
        'valid_until' => 'date',
    ];

    /**
     * Alle Vorlagen, die auf einen Absender/eine Richtung anzuwenden sind —
     * nach Priorität, abgeschnitten nach der ersten Vorlage ohne
     * continue_processing. $direction: 'external' | 'internal'.
     *
     * @return Collection<int, self>
     */
    public static function applicable(EntraUser $sender, string $direction): Collection
    {
        $today = now()->toDateString();

        $candidates = self::where('active', true)
            ->where(fn ($q) => $q->where('direction', 'both')->orWhere('direction', $direction))
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $today))
            ->orderBy('priority')->orderBy('id')
            ->get()
            ->filter(fn (self $t) => $t->senderMatches($sender))
            ->values();

        $result = collect();
        foreach ($candidates as $t) {
            $result->push($t);
            if (! $t->continue_processing) {
                break;
            }
        }

        return $result;
    }

    public function senderMatches(EntraUser $user): bool
    {
        return match ($this->sender_mode) {
            'users' => $this->senderListContains($user),
            'group' => $this->sender_group_id !== null
                && in_array($this->sender_group_id, $user->group_ids ?? [], true),
            default => true,
        };
    }

    protected function senderListContains(EntraUser $user): bool
    {
        $list = array_map('strtolower', preg_split('/[\s,;]+/', (string) $this->sender_users, -1, PREG_SPLIT_NO_EMPTY));

        return in_array(strtolower((string) $user->mail), $list, true)
            || in_array(strtolower((string) $user->upn), $list, true);
    }

    public function directionLabel(): string
    {
        return match ($this->direction) {
            'external' => 'extern',
            'internal' => 'intern',
            default => 'alle',
        };
    }

    public function senderLabel(): string
    {
        return match ($this->sender_mode) {
            'users' => 'bestimmte Adressen',
            'group' => 'Gruppe: '.($this->sender_group_name ?: $this->sender_group_id),
            default => 'alle Benutzer',
        };
    }

    public function periodLabel(): string
    {
        if (! $this->valid_from && ! $this->valid_until) {
            return 'dauerhaft';
        }

        return ($this->valid_from?->format('d.m.Y') ?? '…').' – '.($this->valid_until?->format('d.m.Y') ?? '…');
    }
}
