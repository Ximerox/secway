<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SendRule extends Model
{
    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
        'threshold' => 'integer',
        'score' => 'integer',
    ];

    /** @return array<int, string> Begriffe der Regel (klein, getrimmt). */
    public function termList(): array
    {
        return array_values(array_filter(array_map(
            fn ($x) => mb_strtolower(trim($x)),
            preg_split('/[\r\n,;]+/', (string) $this->terms, -1, PREG_SPLIT_NO_EMPTY)
        )));
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'attachment_name' => 'Anhang-Name',
            'keyword' => 'Stichwörter',
            'birthdate' => 'Geburtsdatum',
            'llm' => 'Lokale KI-Prüfung',
            default => $this->type,
        };
    }
}
