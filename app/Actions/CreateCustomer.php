<?php

namespace App\Actions;

use App\Enums\MembershipLevel;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class CreateCustomer
{
    public function handle(array $validated): User
    {
        $user = new User;
        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'gender' => $validated['gender'],
            'dob' => $validated['dob'],
            'address' => $validated['address'],
            'password' => $validated['password'],
        ]);

        $user->role = UserRole::Customer;
        $user->status = UserStatus::Active;
        $user->current_tier = MembershipLevel::Dong;
        $user->membership_spending = 0;
        $user->must_change_password = false;

        try {
            $user->save();
        } catch (UniqueConstraintViolationException $exception) {
            $errors = [];

            if (User::query()->where('email', $validated['email'])->exists()) {
                $errors['email'] = 'Email này đã được sử dụng.';
            }

            if (User::query()->where('phone', $validated['phone'])->exists()) {
                $errors['phone'] = 'Số điện thoại này đã được sử dụng.';
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            throw $exception;
        }

        return $user;
    }
}
