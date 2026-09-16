<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'regex:/\S/u'],
        ];
    }

    public function throttleKey(): string
    {
        return 'login:'.hash('sha256', $this->input('email').'|'.$this->ip());
    }
}
