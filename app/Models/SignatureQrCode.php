<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SignatureQrCode extends Model
{
    protected $guarded = [];

    /** Content-ID, unter der der (pro Absender erzeugte) QR eingebettet wird. */
    public function cid(): string
    {
        return 'qr-'.$this->id.'@secway';
    }
}
