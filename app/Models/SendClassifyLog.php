<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SendClassifyLog extends Model
{
    protected $table = 'send_classify_log';

    protected $guarded = [];

    protected $casts = [
        'asked' => 'boolean',
        'smime_covered' => 'boolean',
        'rule_hits' => 'array',
    ];
}
