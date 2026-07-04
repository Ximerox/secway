<?php

namespace App\Http\Controllers;

use App\Models\SignatureImage;
use Illuminate\Support\Facades\Storage;

class SignatureImageController extends Controller
{
    public function show(SignatureImage $image)
    {
        abort_unless(Storage::exists($image->path), 404);

        return response()->file(Storage::path($image->path), [
            'Content-Type' => $image->mime,
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
