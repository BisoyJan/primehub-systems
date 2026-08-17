<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSocialGroupInviteRequest extends FormRequest
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
            'invited_user_id' => ['required', 'integer', 'exists:users,id', 'different:auth_user_id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'auth_user_id' => $this->user()?->id,
        ]);
    }
}
