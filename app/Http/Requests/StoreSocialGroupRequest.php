<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSocialGroupRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'visibility' => ['required', 'in:public,private,campaign'],
            'campaign_id' => ['exclude_unless:visibility,campaign', 'required_if:visibility,campaign', 'integer', 'exists:campaigns,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'campaign_id.required_if' => 'Campaign is required for campaign groups.',
        ];
    }
}
