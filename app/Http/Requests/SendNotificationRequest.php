<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendNotificationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by policy in controller
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'type' => ['nullable', 'string', 'in:system,announcement,reminder,alert,custom'],
            'recipient_type' => ['required', 'in:all,role,specific_users,single_user'],
        ];

        // Add conditional validation based on recipient_type
        if ($this->input('recipient_type') === 'role') {
            $rules['role'] = ['required', 'string', 'exists:App\Models\User,role'];
        }

        if ($this->input('recipient_type') === 'specific_users') {
            $rules['user_ids'] = ['required', 'array', 'min:1'];
            $rules['user_ids.*'] = ['required', 'integer', 'exists:users,id'];
        }

        if ($this->input('recipient_type') === 'single_user') {
            $rules['user_id'] = ['required', 'integer', 'exists:users,id'];
        }

        return $rules;
    }

    /**
     * Get custom attribute names.
     */
    public function attributes(): array
    {
        return [
            'title' => 'notification title',
            'message' => 'notification message',
            'type' => 'notification type',
            'recipient_type' => 'recipient type',
            'role' => 'user role',
            'user_ids' => 'selected users',
            'user_id' => 'selected user',
        ];
    }

    /**
     * Get custom error messages.
     */
    public function messages(): array
    {
        return [
            'recipient_type.required' => 'Please select who should receive this notification.',
            'user_ids.required' => 'Please select at least one user.',
            'user_id.required' => 'Please select a user.',
            'role.required' => 'Please select a role.',
        ];
    }
}
