<?php

namespace App\Actions;

use App\Enums\SupportConversationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SendSupportMessage
{
    public function handle(User $sender, ?SupportConversation $conversation, mixed $clientMessageKey, mixed $content): SupportMessage
    {
        if (is_string($content) && ! mb_check_encoding($content, 'UTF-8')) {
            throw ValidationException::withMessages(['content' => 'Tin nhắn phải là UTF-8 hợp lệ.']);
        }
        $input = ['client_message_key' => is_string($clientMessageKey) ? Str::lower(Str::trim($clientMessageKey)) : $clientMessageKey,
            'content' => is_string($content) ? Str::trim($content) : $content];
        $validated = Validator::make($input, [
            'client_message_key' => ['required', 'uuid'],
            'content' => ['required', 'string', 'max:2000', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u'],
        ], [
            'client_message_key.required' => 'Thiếu mã chống gửi lặp.', 'client_message_key.uuid' => 'Mã chống gửi lặp không hợp lệ.',
            'content.required' => 'Vui lòng nhập nội dung tin nhắn.', 'content.string' => 'Nội dung tin nhắn không hợp lệ.',
            'content.max' => 'Tin nhắn không được vượt quá 2.000 ký tự.', 'content.not_regex' => 'Tin nhắn chứa ký tự điều khiển không hợp lệ.',
        ])->validate();

        return DB::transaction(function () use ($sender, $conversation, $validated): SupportMessage {
            $actor = User::query()->lockForUpdate()->find($sender->id);
            $role = UserRole::tryFrom((string) $actor?->getRawOriginal('role'));
            if ($actor === null || UserStatus::tryFrom((string) $actor->getRawOriginal('status')) !== UserStatus::Active
                || ! in_array($role, UserRole::cases(), true)) {
                throw ValidationException::withMessages(['authorization' => 'Tài khoản không thể gửi tin nhắn hỗ trợ.']);
            }

            $lockedConversation = $role === UserRole::Customer
                ? SupportConversation::query()->where('customer_id', $actor->id)->lockForUpdate()->first()
                : ($conversation === null ? null : SupportConversation::query()->lockForUpdate()->find($conversation->id));
            if ($role === UserRole::Customer && $lockedConversation === null) {
                $now = CarbonImmutable::now('UTC');
                $lockedConversation = new SupportConversation;
                $lockedConversation->forceFill(['customer_id' => $actor->id, 'status' => SupportConversationStatus::Open,
                    'last_message_at' => null, 'closed_by' => null, 'closed_at' => null,
                    'created_at' => $now, 'updated_at' => $now])->save();
            }
            if (! $lockedConversation instanceof SupportConversation) {
                throw ValidationException::withMessages(['conversation' => 'Hội thoại hỗ trợ không tồn tại.']);
            }

            $existing = SupportMessage::query()->where('sender_id', $actor->id)
                ->where('client_message_key', $validated['client_message_key'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->conversation_id !== $lockedConversation->id || $existing->content !== $validated['content']) {
                    throw ValidationException::withMessages(['client_message_key' => 'Mã chống gửi lặp đã được dùng với nội dung hoặc hội thoại khác.']);
                }

                return $existing->load('sender:id,name,role');
            }

            $now = CarbonImmutable::now('UTC');
            if ($lockedConversation->status === SupportConversationStatus::Closed) {
                $lockedConversation->forceFill(['status' => SupportConversationStatus::Open, 'closed_by' => null, 'closed_at' => null]);
            }
            $message = new SupportMessage;
            $message->forceFill(['conversation_id' => $lockedConversation->id, 'sender_id' => $actor->id,
                'content' => $validated['content'], 'client_message_key' => $validated['client_message_key'], 'created_at' => $now])->save();
            $lockedConversation->forceFill(['last_message_at' => $now, 'updated_at' => $now])->save();
            $this->advanceReadMarker($lockedConversation->id, $actor->id, $message->id, $now);

            return $message->load('sender:id,name,role');
        }, 3);
    }

    private function advanceReadMarker(int $conversationId, int $userId, int $messageId, CarbonImmutable $at): void
    {
        $existing = DB::table('support_conversation_reads')->where('conversation_id', $conversationId)
            ->where('user_id', $userId)->lockForUpdate()->first();
        if ($existing === null) {
            DB::table('support_conversation_reads')->insert(['conversation_id' => $conversationId, 'user_id' => $userId,
                'last_read_message_id' => $messageId, 'read_at' => $at]);
        } elseif ($existing->last_read_message_id === null || (int) $existing->last_read_message_id < $messageId) {
            DB::table('support_conversation_reads')->where('conversation_id', $conversationId)->where('user_id', $userId)
                ->update(['last_read_message_id' => $messageId, 'read_at' => $at]);
        }
    }
}
