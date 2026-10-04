<?php

namespace App\Actions;

use App\Enums\SupportConversationStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class GetSupportInbox
{
    public function handle(User $staff, ?string $search, ?string $status): LengthAwarePaginator
    {
        $role = UserRole::tryFrom((string) $staff->getRawOriginal('role'));
        if (UserStatus::tryFrom((string) $staff->getRawOriginal('status')) !== UserStatus::Active
            || ! in_array($role, [UserRole::Admin, UserRole::Employee], true)) {
            throw ValidationException::withMessages(['authorization' => 'Tài khoản không thể xem hộp thư hỗ trợ.']);
        }
        $normalizedSearch = is_string($search) ? trim($search) : null;
        $normalizedStatus = is_string($status) && $status !== '' ? $status : null;
        if ($normalizedStatus !== null && SupportConversationStatus::tryFrom($normalizedStatus) === null) {
            throw ValidationException::withMessages(['status' => 'Trạng thái hội thoại không hợp lệ.']);
        }

        $unread = SupportMessage::query()->selectRaw('COUNT(*)')
            ->whereColumn('support_messages.conversation_id', 'support_conversations.id')
            ->where('support_messages.sender_id', '<>', $staff->id)
            ->whereRaw('support_messages.id > COALESCE((SELECT r.last_read_message_id FROM support_conversation_reads r WHERE r.conversation_id = support_conversations.id AND r.user_id = ?), 0)', [$staff->id]);
        $query = SupportConversation::query()->select('support_conversations.*')->selectSub($unread, 'unread_count')
            ->with(['customer:id,name,email',
                'lastMessage' => fn ($message) => $message->select(['support_messages.id', 'support_messages.conversation_id', 'support_messages.sender_id', 'support_messages.content', 'support_messages.created_at']),
                'lastMessage.sender:id,name,role']);
        if ($normalizedSearch !== null && $normalizedSearch !== '') {
            $like = '%'.$this->escapeLike(mb_strtolower($normalizedSearch)).'%';
            $query->whereHas('customer', fn ($customer) => $customer
                ->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$like]));
        }
        if ($normalizedStatus !== null) {
            $query->where('status', $normalizedStatus);
        }

        return $query->orderByRaw('last_message_at IS NULL')->orderByDesc('last_message_at')->orderByDesc('id')
            ->paginate(20)->withQueryString();
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
