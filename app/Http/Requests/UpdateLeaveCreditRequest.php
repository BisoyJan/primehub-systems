<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLeaveCreditRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->hasPermission('leave_credits.edit');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'credits_earned' => ['required', 'numeric', 'min:0', 'max:20'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'credits_earned.required' => 'The credits earned value is required.',
            'credits_earned.numeric' => 'The credits earned must be a number.',
            'credits_earned.min' => 'The credits earned cannot be negative.',
            'credits_earned.max' => 'The credits earned cannot exceed 20.',
            'reason.required' => 'A reason for this adjustment is required.',
            'reason.max' => 'The reason cannot exceed 500 characters.',
        ];
    }
}
