<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmtpTestLog extends Model
{
    protected $fillable = [
        'user_id',
        'recipient',
        'status',
        'stage',
        'message_id',
        'diagnostics',
        'error',
    ];

    protected function casts(): array
    {
        return ['diagnostics' => 'array'];
    }
}
