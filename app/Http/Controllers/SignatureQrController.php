<?php

namespace App\Http\Controllers;

use App\Models\SignatureQrCode;
use App\Support\QrGenerator;
use Throwable;

class SignatureQrController extends Controller
{
    /**
     * QR-Vorschau für den Editor: rendert den Roh-Text (Platzhalter literal).
     * Die pro Absender gefüllte Fassung entsteht erst beim Versand/in der Vorschau.
     */
    public function show(SignatureQrCode $qr, QrGenerator $generator)
    {
        try {
            $png = $generator->png($qr->text, $qr->size);
        } catch (Throwable $e) {
            abort(500, 'QR-Erzeugung fehlgeschlagen');
        }

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store',
        ]);
    }
}
