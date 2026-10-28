<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PcBulkTransferRequest extends FormRequest
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
            'transfers' => ['required', 'array', 'min:1'],
            'transfers.*.to_station_id' => ['required', 'integer', 'exists:stations,id'],
            'transfers.*.pc_spec_id' => ['required', 'integer', 'exists:pc_specs,id'],
            'transfers.*.transfer_type' => ['nullable', Rule::in(['assign', 'swap'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'transfers' => 'transfers',
            'transfers.*.to_station_id' => 'destination station',
            'transfers.*.pc_spec_id' => 'PC',
            'transfers.*.transfer_type' => 'transfer type',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'transfers.required' => 'Add at least one transfer before submitting.',
            'transfers.min' => 'Add at least one transfer before submitting.',
            'transfers.*.to_station_id.required' => 'Every transfer needs a destination station.',
            'transfers.*.to_station_id.exists' => 'A selected station no longer exists.',
            'transfers.*.pc_spec_id.exists' => 'A selected PC no longer exists.',
        ];
    }
}
