<?php

namespace App\Support;

use App\Models\EntraUser;
use App\Services\EntraTokenValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Anmeldung der Outlook-Add-ins an der API (/api/classify, /api/signature).
 *
 *  - bevorzugt Office-SSO: Bearer = Entra-Zugriffstoken des angemeldeten
 *    Benutzers (NAA bzw. getAccessToken), geprüft durch EntraTokenValidator.
 *    Der Benutzer muss im Entra-Cache (entra_users) vorhanden sein.
 *  - Übergang: festes Token aus der .env (MGW_CLASSIFY_TOKEN bzw.
 *    MGW_SIGNATURE_TOKEN), nur solange dort gesetzt.
 */
class AddinAuth
{
    /** @return EntraUser|null der angemeldete Benutzer bei SSO, null beim Übergangs-Token */
    public static function check(Request $request, string $legacyConfigKey, string $api): ?EntraUser
    {
        $bearer = (string) $request->bearerToken();
        abort_if($bearer === '', 401, 'Token fehlt.');

        if (substr_count($bearer, '.') === 2) {
            try {
                $claims = app(EntraTokenValidator::class)->validate($bearer);
            } catch (Throwable $e) {
                Log::warning("api/{$api}: SSO-Token abgelehnt: ".$e->getMessage());
                abort(401, 'Ungültiges Token.');
            }
            $user = EntraUser::where('entra_id', $claims['oid'])->first();
            abort_if($user === null, 403, 'Benutzer unbekannt.');

            return $user;
        }

        $token = (string) config($legacyConfigKey);
        abort_if($token === '' || ! hash_equals($token, $bearer), 401, 'Ungültiges Token.');

        return null;
    }
}
