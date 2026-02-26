<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewCoachingSessionRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'compliance_status' => ['required', Rule::in(['Verified', 'Rejected'])],
            'compliance_notes' => ['nullable', 'required_if:compliance_status,Rejected', 'string', 'max:2000'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'compliance_status.required' => 'Please select a review decision.',
            'compliance_status.in' => 'Decision must be either Verified or Rejected.',
            'compliance_notes.required_if' => 'Please provide a reason for rejection.',
            'compliance_notes.max' => 'Notes cannot exceed 2000 characters.',
        ];
    }
}
