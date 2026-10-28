<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PcRemoveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'station_id' => ['required', 'integer', 'exists:stations,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'station_id' => 'station',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'station_id.exists' => 'The selected station no longer exists.',
        ];
    }
}
