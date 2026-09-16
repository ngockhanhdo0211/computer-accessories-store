<?php

namespace Database\Factories;

use App\Enums\MembershipLevel;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make(Str::random(32)),
            'remember_token' => Str::random(10),
            'role' => UserRole::Customer->value,
            'status' => UserStatus::Active->value,
            'current_tier' => MembershipLevel::Dong->value,
            'membership_spending' => 0,
            'must_change_password' => false,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function employee(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Employee->value,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Admin->value,
        ]);
    }

    public function locked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::Locked->value,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::Inactive->value,
        ]);
    }
}
