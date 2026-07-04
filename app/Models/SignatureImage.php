<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SignatureImage extends Model
{
    protected $guarded = [];

    /** Content-ID, unter der das Bild beim Versand eingebettet wird. */
    public function cid(): string
    {
        return 'sig-'.$this->id.'@secway';
    }
}
