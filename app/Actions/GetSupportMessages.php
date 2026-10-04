<?php

namespace App\Actions;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class GetSupportMessages
{
    /** @return Collection<int, SupportMessage> */
    public function handle(User $viewer, SupportConversation $conversation, ?int $beforeId = null, ?int $afterId = null): Collection
    {
        $this->authorize($viewer, $conversation);
        if ($beforeId !== null && $afterId !== null) {
            throw ValidationException::withMessages(['cursor' => 'Không thể dùng before_id và after_id cùng lúc.']);
        }

        $query = SupportMessage::query()->select(['id', 'conversation_id', 'sender_id', 'content', 'created_at'])
            ->where('conversation_id', $conversation->id)->with('sender:id,name,role');
        if ($afterId !== null) {
            return $query->where('id', '>', $afterId)->orderBy('id')->limit(50)->get();
        }

        $messages = $query->when($beforeId !== null, fn ($builder) => $builder->where('id', '<', $beforeId))
            ->orderByDesc('id')->limit(50)->get();

        return new Collection($messages->reverse()->values()->all());
    }

    public function authorize(User $viewer, SupportConversation $conversation): void
    {
        $role = UserRole::tryFrom((string) $viewer->getRawOriginal('role'));
        if (UserStatus::tryFrom((string) $viewer->getRawOriginal('status')) !== UserStatus::Active
            || ($role === UserRole::Customer && $conversation->customer_id !== $viewer->id)
            || ! in_array($role, UserRole::cases(), true)) {
            abort(404);
        }
    }
}
