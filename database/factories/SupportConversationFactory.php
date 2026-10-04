<?php

namespace Database\Factories;

use App\Enums\SupportConversationStatus;
use App\Models\SupportConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupportConversation> */
class SupportConversationFactory extends Factory
{
    protected $model = SupportConversation::class;

    public function definition(): array
    {
        return ['customer_id' => User::factory(), 'status' => SupportConversationStatus::Open, 'last_message_at' => null,
            'closed_by' => null, 'closed_at' => null];
    }

    public function closed(): static
    {
        return $this->state(fn (): array => ['status' => SupportConversationStatus::Closed,
            'closed_by' => User::factory()->employee(), 'closed_at' => now()]);
    }
}
