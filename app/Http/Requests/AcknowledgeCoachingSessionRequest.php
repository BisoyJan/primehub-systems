<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcknowledgeCoachingSessionRequest extends FormRequest
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
            'acknowledged' => ['required', 'accepted'],
            'ack_comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'acknowledged.required' => 'You must check the acknowledgement box.',
            'acknowledged.accepted' => 'You must acknowledge the coaching session and action plan.',
            'ack_comment.max' => 'Comment cannot exceed 2000 characters.',
        ];
    }
}
