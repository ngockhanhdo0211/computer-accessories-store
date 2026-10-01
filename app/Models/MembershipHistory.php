<?php

namespace App\Models;

use App\Enums\MembershipLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class MembershipHistory extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        static::creating(function (MembershipHistory $history): void {
            if ($history->new_tier === null
                || ($history->old_tier !== null && $history->old_tier === $history->new_tier)
                || ! is_int($history->spending_vnd)
                || $history->spending_vnd < 0
                || ! is_string($history->reason)
                || trim($history->reason) === ''
                || mb_strlen($history->reason) > 40) {
                throw new LogicException('Membership history snapshot is invalid.');
            }
        });
        static::updating(fn () => throw new LogicException('Membership histories are append-only.'));
        static::deleting(fn () => throw new LogicException('Membership histories are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'old_tier' => MembershipLevel::class,
            'new_tier' => MembershipLevel::class,
            'spending_vnd' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
