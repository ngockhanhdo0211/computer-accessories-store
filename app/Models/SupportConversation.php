<?php

namespace App\Models;

use App\Enums\SupportConversationStatus;
use Database\Factories\SupportConversationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class SupportConversation extends Model
{
    /** @use HasFactory<SupportConversationFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['status' => SupportConversationStatus::class, 'last_message_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (SupportConversation $conversation): void {
            if ($conversation->isDirty(['customer_id', 'created_at'])) {
                throw new LogicException('Support conversation identity is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Support conversations cannot be deleted.'));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'conversation_id')->orderBy('id');
    }

    public function lastMessage(): HasOne
    {
        return $this->hasOne(SupportMessage::class, 'conversation_id')->latestOfMany();
    }

    public function reads(): HasMany
    {
        return $this->hasMany(SupportConversationRead::class, 'conversation_id');
    }
}
