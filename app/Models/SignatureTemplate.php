<?php

namespace App\Models;

use App\Support\InternalDomains;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class SignatureTemplate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
        'valid_from' => 'date',
        'valid_until' => 'date',
    ];

    /**
     * Alle Vorlagen, die auf Absender + Empfänger anzuwenden sind — nach
     * Priorität. Pro Vorlage steuert on_applied/on_not_applied, ob danach
     * weitere Vorlagen geprüft werden.
     *
     * @param  array<int, string>  $recipients
     * @return Collection<int, self>
     */
    public static function applicable(EntraUser $sender, array $recipients): Collection
    {
        $today = now()->toDateString();
        $result = collect();

        foreach (self::where('active', true)->orderBy('priority')->orderBy('id')->get() as $t) {
            $inDate = (! $t->valid_from || $t->valid_from->toDateString() <= $today)
                && (! $t->valid_until || $t->valid_until->toDateString() >= $today);

            $matches = $inDate && $t->senderMatches($sender) && $t->recipientsMatch($recipients);

            if ($matches) {
                $result->push($t);
                if ($t->on_applied === 'stop') {
                    break;
                }
            } elseif ($t->on_not_applied === 'stop') {
                break;
            }
        }

        return $result;
    }

    public function senderMatches(EntraUser $user): bool
    {
        // Ausnahmen haben Vorrang
        if ($this->matchesAddressList($this->sender_exclude, $user)) {
            return false;
        }

        return match ($this->sender_mode) {
            'users' => $this->matchesAddressList($this->sender_users, $user),
            'group' => $this->sender_group_id !== null && in_array($this->sender_group_id, $user->group_ids ?? [], true),
            default => true,
        };
    }

    /** @param  array<int, string>  $recipients */
    public function recipientsMatch(array $recipients): bool
    {
        $recipients = array_values(array_filter(array_map('strval', $recipients)));

        // Richtungs-Logik so, dass GEMISCHTE Mails genau eine Signatur bekommen:
        //  - „extern"  greift, sobald mindestens ein externer Empfänger dabei ist
        //  - „intern"  greift NUR, wenn ALLE Empfänger intern sind (kein externer)
        // Damit schließen sich intern/extern bei gemischten Empfängern aus; die
        // gemischte Mail erhält die externe Signatur (das eine Mail-Objekt geht
        // ohnehin identisch an alle Empfänger).
        $hasExternal = false;
        foreach ($recipients as $r) {
            if (! InternalDomains::isInternal($r)) {
                $hasExternal = true;
                break;
            }
        }

        $eligible = match ($this->direction) {
            'external' => array_filter($recipients, fn ($r) => ! InternalDomains::isInternal($r)),
            'internal' => $hasExternal ? [] : $recipients,
            default => $recipients,
        };

        $include = self::parseList($this->recipient_include);
        if ($include !== []) {
            $eligible = array_filter($eligible, fn ($r) => $this->addressOrDomainIn($r, $include));
        }

        $exclude = self::parseList($this->recipient_exclude);
        if ($exclude !== []) {
            $eligible = array_filter($eligible, fn ($r) => ! $this->addressOrDomainIn($r, $exclude));
        }

        return count($eligible) > 0;
    }

    /** Prüft mail/UPN des Benutzers gegen eine Adress-/Domainliste. */
    protected function matchesAddressList(?string $list, EntraUser $user): bool
    {
        $items = self::parseList($list);
        if ($items === []) {
            return false;
        }
        foreach ([strtolower((string) $user->mail), strtolower((string) $user->upn)] as $addr) {
            if ($addr !== '' && $this->addressOrDomainIn($addr, $items)) {
                return true;
            }
        }

        return false;
    }

    /** true, wenn die Adresse exakt oder über ihre Domain in der Liste steht. */
    protected function addressOrDomainIn(string $email, array $list): bool
    {
        $email = strtolower($email);
        $domain = str_contains($email, '@') ? substr($email, strrpos($email, '@') + 1) : '';
        foreach ($list as $item) {
            if ($item === $email) {
                return true;
            }
            if ($domain !== '' && ($item === '@'.$domain || $item === $domain)) {
                return true;
            }
        }

        return false;
    }

    /** Zerlegt eine komma-/whitespace-getrennte Adressliste (kleingeschrieben). */
    public static function parseList(?string $s): array
    {
        return array_values(array_filter(array_map(
            fn ($x) => strtolower(trim($x)),
            preg_split('/[\s,;]+/', (string) $s, -1, PREG_SPLIT_NO_EMPTY)
        )));
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
        $base = match ($this->sender_mode) {
            'users' => 'bestimmte Adressen',
            'group' => 'Gruppe: '.($this->sender_group_name ?: $this->sender_group_id),
            default => 'alle Benutzer',
        };

        return trim((string) $this->sender_exclude) !== '' ? $base.' (mit Ausnahmen)' : $base;
    }

    public function recipientLabel(): string
    {
        $base = $this->directionLabel();
        if (trim((string) $this->recipient_include) !== '') {
            $base .= ', nur bestimmte';
        }
        if (trim((string) $this->recipient_exclude) !== '') {
            $base .= ' (mit Ausnahmen)';
        }

        return $base;
    }

    public function periodLabel(): string
    {
        if (! $this->valid_from && ! $this->valid_until) {
            return 'dauerhaft';
        }

        return ($this->valid_from?->format('d.m.Y') ?? '…').' – '.($this->valid_until?->format('d.m.Y') ?? '…');
    }
}
