<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StationBulkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('starting_number')) {
            $this->merge([
                'starting_number' => strtoupper((string) $this->input('starting_number')),
            ]);
        }
        if ($this->has('increment_type')) {
            $this->merge([
                'increment_type' => strtolower((string) $this->input('increment_type')),
            ]);
        }
        if ($this->has('monitor_type')) {
            $this->merge([
                'monitor_type' => strtolower((string) $this->input('monitor_type')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'site_id' => ['required', 'exists:sites,id'],
            'starting_number' => ['required', 'string', 'max:255'],
            'campaign_id' => ['required', 'exists:campaigns,id'],
            'status' => ['required', 'string', 'max:255'],
            'monitor_type' => ['required', Rule::in(['single', 'dual'])],
            'pc_spec_id' => ['nullable', 'exists:pc_specs,id'],
            'pc_spec_ids' => ['nullable', 'array'],
            'pc_spec_ids.*' => ['exists:pc_specs,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'increment_type' => ['required', Rule::in(['number', 'letter', 'both'])],
        ];
    }

    public function attributes(): array
    {
        return [
            'site_id' => 'site',
            'starting_number' => 'starting station number',
            'campaign_id' => 'campaign',
            'monitor_type' => 'monitor type',
            'pc_spec_id' => 'PC spec',
            'pc_spec_ids' => 'PC specs',
            'increment_type' => 'increment type',
        ];
    }
}
