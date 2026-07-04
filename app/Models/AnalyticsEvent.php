<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsEvent extends Model
{
    protected $fillable = [
        'event_uuid',
        'event_name',
        'deduplication_key',
        'visitor_id',
        'user_id',
        'tree_id',
        'plan_id',
        'platform',
        'landing_page',
        'referrer',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'user_agent',
        'ip_hash',
        'value',
        'currency',
        'parameters',
        'external_pending',
        'external_dispatched_at',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'value' => 'decimal:2',
            'external_pending' => 'boolean',
            'external_dispatched_at' => 'datetime',
            'occurred_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tree(): BelongsTo
    {
        return $this->belongsTo(FamilyTree::class, 'tree_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
