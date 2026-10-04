<?php

namespace App\Actions;

use App\Enums\SupportConversationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\SupportConversation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CloseSupportConversation
{
    public function handle(SupportConversation $conversation, User $actor, mixed $eventKey): SupportConversation
    {
        $validated = Validator::make(['event_key' => is_string($eventKey) ? trim($eventKey) : $eventKey],
            ['event_key' => ['required', 'uuid']])->validate();
        $fingerprint = hash('sha256', json_encode(['conversation_id' => $conversation->id, 'actor_id' => $actor->id,
            'action' => 'close'], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($conversation, $actor, $validated, $fingerprint): SupportConversation {
            $reviewer = User::query()->lockForUpdate()->find($actor->id);
            $role = UserRole::tryFrom((string) $reviewer?->getRawOriginal('role'));
            if ($reviewer === null || UserStatus::tryFrom((string) $reviewer->getRawOriginal('status')) !== UserStatus::Active
                || ! in_array($role, [UserRole::Admin, UserRole::Employee], true)) {
                throw ValidationException::withMessages(['authorization' => 'Tài khoản không thể đóng hội thoại.']);
            }
            $locked = SupportConversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $existing = AuditLog::query()->where('request_id', $validated['event_key'])->lockForUpdate()->get();
            if ($existing->isNotEmpty()) {
                if ($existing->count() !== 1) {
                    throw ValidationException::withMessages(['event_key' => 'Mã chống lặp đã có nhiều bằng chứng xung đột.']);
                }
                $audit = $existing->first();
                if ($audit->action !== 'support.conversation_closed' || $audit->subject_type !== SupportConversation::class
                    || $audit->subject_id !== $locked->id || $audit->actor_id !== $reviewer->id
                    || ($audit->after_json['fingerprint'] ?? null) !== $fingerprint
                    || $locked->status !== SupportConversationStatus::Closed || $locked->closed_by !== $reviewer->id) {
                    throw ValidationException::withMessages(['event_key' => 'Mã chống lặp đã được dùng hoặc bằng chứng đóng hội thoại không còn khớp.']);
                }

                return $locked;
            }
            if ($locked->status === SupportConversationStatus::Closed) {
                throw ValidationException::withMessages(['conversation' => 'Hội thoại đã đóng nhưng thiếu bằng chứng idempotency phù hợp.']);
            }
            $now = CarbonImmutable::now('UTC');
            $locked->forceFill(['status' => SupportConversationStatus::Closed, 'closed_by' => $reviewer->id,
                'closed_at' => $now, 'updated_at' => $now])->save();
            (new AuditLog)->forceFill(['actor_id' => $reviewer->id, 'action' => 'support.conversation_closed',
                'subject_type' => SupportConversation::class, 'subject_id' => $locked->id,
                'before_json' => ['status' => 'open'], 'after_json' => ['status' => 'closed', 'customer_id' => $locked->customer_id, 'fingerprint' => $fingerprint],
                'request_id' => $validated['event_key'], 'created_at' => $now])->save();

            return $locked->fresh();
        }, 3);
    }
}
