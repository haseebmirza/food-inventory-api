<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class AuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        $register = $this->routeIs('api.v1.auth.register');

        return [
            'name' => $register ? ['required', 'string', 'max:120'] : ['prohibited'],
            'email' => $register ? ['required', 'email', 'max:254', 'unique:users,email'] : ['required', 'email', 'max:254'],
            'password' => $register ? ['bail', 'required', 'string', 'max:'.config('api.password.max_bytes'), 'confirmed', Password::min(config('api.password.min_length'))->letters()->numbers(), function (string $attribute, mixed $value, \Closure $fail): void {
                if (strlen($value) > config('api.password.max_bytes')) {
                    $fail('The password must not exceed '.config('api.password.max_bytes').' bytes.');
                }
            }] : ['required', 'string', 'max:'.config('api.password.max_bytes')],
        ];
    }
}
