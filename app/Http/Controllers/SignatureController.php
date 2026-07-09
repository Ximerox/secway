<?php

namespace App\Http\Controllers;

use App\Models\EntraUser;
use App\Models\Setting;
use App\Models\SignatureTemplate;
use App\Services\SignatureRenderer;
use Illuminate\Http\Request;

/**
 * Stateless-API für das Compose-Add-in „SecWay Signatur": liefert den
 * Signaturblock für Absender + Empfängerliste als selbstenthaltenes HTML
 * (Bilder/QR als data:-URIs — Outlook wandelt sie beim Senden in
 * CID-Anhänge um). Nutzt exakt dieselbe Regel-Engine wie der serverseitige
 * Signatur-Schritt, damit Client und Gateway identisch entscheiden.
 * Token-geschützt (Bearer = MGW_SIGNATURE_TOKEN), CSRF-frei.
 */
class SignatureController extends Controller
{
    public function signature(Request $request, SignatureRenderer $renderer)
    {
        $this->authorizeToken($request);

        if (! Setting::getBool('signature_enabled', false)) {
            return response()->json(['none' => true, 'html' => null, 'reason' => 'disabled']);
        }

        $data = $request->validate([
            'sender' => 'required|email|max:320',
            'recipients' => 'nullable|array|max:500',
            'recipients.*' => 'string|max:320',
        ]);

        $user = EntraUser::forSender($data['sender']);
        if ($user === null) {
            return response()->json(['none' => true, 'html' => null, 'reason' => 'unknown_sender']);
        }

        $templates = SignatureTemplate::applicable($user, array_values($data['recipients'] ?? []));
        if ($templates->isEmpty()) {
            return response()->json(['none' => true, 'html' => null]);
        }

        // Marker wie beim serverseitigen Anfügen — das Gateway erkennt daran
        // vorhandene Blöcke (skip/replace), der Header X-MGW-Signed bleibt
        // aber die primäre Kennung (Kommentare können gestrippt werden).
        $html = '';
        foreach ($templates as $t) {
            $html .= "\n<!--SECWAY-SIG t{$t->id}-->\n".$renderer->forPreview($t, $user)."\n<!--/SECWAY-SIG-->\n";
        }

        return response()->json(['none' => false, 'html' => $html]);
    }

    private function authorizeToken(Request $request): void
    {
        $token = (string) config('mailgateway.signature_token');
        abort_if($token === '' || ! hash_equals($token, (string) $request->bearerToken()), 401, 'Ungültiges Token.');
    }
}
