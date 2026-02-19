<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLeaveCarryoverRequest extends FormRequest
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
            'carryover_credits' => ['required', 'numeric', 'min:0', 'max:30'],
            'year' => ['required', 'integer', 'min:2024', 'max:'.(now()->year + 1)],
            'reason' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
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
            'carryover_credits.required' => 'The carryover credits value is required.',
            'carryover_credits.numeric' => 'The carryover credits must be a number.',
            'carryover_credits.min' => 'The carryover credits cannot be negative.',
            'carryover_credits.max' => 'The carryover credits cannot exceed 30.',
            'year.required' => 'The year is required.',
            'year.integer' => 'The year must be a valid integer.',
            'reason.required' => 'A reason for this adjustment is required.',
            'reason.max' => 'The reason cannot exceed 500 characters.',
        ];
    }
}
