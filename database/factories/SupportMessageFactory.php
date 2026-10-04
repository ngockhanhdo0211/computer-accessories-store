<?php

namespace Database\Factories;

use App\Models\SupportConversation;
use App\Models\SupportMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SupportMessage> */
class SupportMessageFactory extends Factory
{
    protected $model = SupportMessage::class;

    public function definition(): array
    {
        return ['conversation_id' => SupportConversation::factory(), 'sender_id' => fn (array $attributes) => SupportConversation::query()->findOrFail($attributes['conversation_id'])->customer_id,
            'content' => fake()->sentence(), 'client_message_key' => (string) Str::uuid(), 'created_at' => now()];
    }
}
