<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SupportConversationRead extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $primaryKey = null;

    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Support read markers cannot be deleted.'));
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lastReadMessage(): BelongsTo
    {
        return $this->belongsTo(SupportMessage::class, 'last_read_message_id');
    }
}
