<?php

namespace App\Http\Requests;

use App\Support\PhoneNumberNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() === null;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['name', 'address'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = trim(preg_replace('/\s+/u', ' ', $this->input($field)));
            }
        }

        if (is_string($this->input('email'))) {
            $normalized['email'] = mb_strtolower(trim($this->input('email')));
        }

        if (is_string($this->input('gender'))) {
            $normalized['gender'] = mb_strtolower(trim($this->input('gender')));
        }

        if (is_string($this->input('phone'))) {
            $normalized['phone'] = PhoneNumberNormalizer::normalize($this->input('phone'));
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['required', 'regex:/^0[35789][0-9]{8}$/', Rule::unique('users', 'phone')],
            'gender' => ['required', Rule::in(['nam', 'nu'])],
            'dob' => ['required', 'date', 'before_or_equal:today'],
            'address' => ['required', 'string', 'max:1000'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()],
            'password_confirmation' => ['required', 'same:password'],
        ];
    }
}
