<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PcTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('transfer_type')) {
            $this->merge(['transfer_type' => 'assign']);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'to_station_id' => ['required', 'integer', 'exists:stations,id'],
            'pc_spec_id' => ['required', 'integer', 'exists:pc_specs,id'],
            'transfer_type' => ['required', Rule::in(['assign', 'swap'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'to_station_id' => 'destination station',
            'pc_spec_id' => 'PC',
            'transfer_type' => 'transfer type',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to_station_id.required' => 'Select a destination station.',
            'to_station_id.exists' => 'The selected station no longer exists.',
            'pc_spec_id.required' => 'Select a PC to transfer.',
            'pc_spec_id.exists' => 'The selected PC no longer exists.',
        ];
    }
}
