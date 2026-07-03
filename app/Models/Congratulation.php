<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTree;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Congratulation extends Model
{
    use BelongsToTree;

    protected $fillable = [
        'tree_id',
        'sender_user_id',
        'sender_person_id',
        'recipient_person_id',
        'partnership_id',
        'occasion',
        'message',
        'site_status',
        'telegram_status',
        'telegram_error',
        'telegram_delivered_at',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'telegram_delivered_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function senderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function senderPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'sender_person_id');
    }

    public function recipientPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'recipient_person_id');
    }

    public function partnership(): BelongsTo
    {
        return $this->belongsTo(Partnership::class);
    }
}
