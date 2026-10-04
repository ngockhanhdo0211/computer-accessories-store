<?php

namespace App\Actions;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarkSupportConversationRead
{
    public function __construct(private readonly GetSupportMessages $messages) {}

    public function handle(User $user, SupportConversation $conversation, int $messageId): void
    {
        $this->messages->authorize($user, $conversation);
        DB::transaction(function () use ($user, $conversation, $messageId): void {
            $actor = User::query()->lockForUpdate()->findOrFail($user->id);
            $locked = SupportConversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $role = UserRole::tryFrom((string) $actor->getRawOriginal('role'));
            if (UserStatus::tryFrom((string) $actor->getRawOriginal('status')) !== UserStatus::Active
                || ! in_array($role, UserRole::cases(), true)
                || ($role === UserRole::Customer && $locked->customer_id !== $actor->id)) {
                throw ValidationException::withMessages(['authorization' => 'Tài khoản không thể đánh dấu hội thoại này.']);
            }
            $message = SupportMessage::query()->where('conversation_id', $locked->id)->where('id', $messageId)->lockForUpdate()->first();
            if ($message === null) {
                throw ValidationException::withMessages(['message_id' => 'Tin nhắn không thuộc hội thoại này.']);
            }
            $marker = DB::table('support_conversation_reads')->where('conversation_id', $locked->id)
                ->where('user_id', $actor->id)->lockForUpdate()->first();
            if ($marker !== null && $marker->last_read_message_id !== null && (int) $marker->last_read_message_id >= $message->id) {
                return;
            }
            $values = ['last_read_message_id' => $message->id, 'read_at' => CarbonImmutable::now('UTC')];
            if ($marker === null) {
                DB::table('support_conversation_reads')->insert(['conversation_id' => $locked->id, 'user_id' => $actor->id, ...$values]);
            } else {
                DB::table('support_conversation_reads')->where('conversation_id', $locked->id)->where('user_id', $actor->id)->update($values);
            }
        }, 3);
    }
}
