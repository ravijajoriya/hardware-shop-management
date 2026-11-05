<?php

namespace App\Http\Requests\Admin;

use Database\Seeders\AdminUserSeeder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Someone at an unlocked, logged-in device can't change it without knowing it.
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            'password' => [
                'required',
                'string',
                'min:'.AdminUserSeeder::MIN_PASSWORD_LENGTH,
                'max:255',
                'confirmed',
                'different:current_password',
            ],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Type your current password.',
            'current_password.current_password' => 'This is not your current password.',
            'password.required' => 'Type a new password.',
            'password.min' => 'Use at least :min characters.',
            'password.confirmed' => 'The two new passwords are different.',
            'password.different' => 'Choose a password different from the current one.',
        ];
    }
}
