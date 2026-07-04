<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrafficAttribution extends Model
{
    protected $fillable = [
        'visitor_id',
        'user_id',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'referrer',
        'landing_page',
        'first_seen_at',
        'last_utm_source',
        'last_utm_medium',
        'last_utm_campaign',
        'last_utm_content',
        'last_utm_term',
        'last_referrer',
        'last_landing_page',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
