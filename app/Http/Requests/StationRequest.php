<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('station_number')) {
            $this->merge([
                'station_number' => strtoupper((string) $this->input('station_number')),
            ]);
        }

        // Convert empty pc_spec_id to null
        if ($this->has('pc_spec_id') && ($this->input('pc_spec_id') === '' || $this->input('pc_spec_id') === '0')) {
            $this->merge([
                'pc_spec_id' => null,
            ]);
        }

        // Convert empty campaign_id to null
        if ($this->has('campaign_id') && ($this->input('campaign_id') === '' || $this->input('campaign_id') === '0')) {
            $this->merge([
                'campaign_id' => null,
            ]);
        }

        // Convert empty status to null
        if ($this->has('status') && $this->input('status') === '') {
            $this->merge([
                'status' => null,
            ]);
        }
    }

    public function rules(): array
    {
        $station = $this->route('station');
        $ignoreId = is_object($station) ? $station->id : (is_numeric($station) ? (int) $station : null);

        return [
            'site_id' => ['required', 'exists:sites,id'],
            'station_number' => [
                'required', 'string', 'max:255',
                Rule::unique('stations', 'station_number')->ignore($ignoreId),
            ],
            'campaign_id' => ['nullable', 'exists:campaigns,id'],
            'status' => ['nullable', 'string', 'max:255'],
            'monitor_type' => ['required', Rule::in(['single', 'dual'])],
            'pc_spec_id' => ['nullable', 'exists:pc_specs,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'site_id' => 'site',
            'station_number' => 'station number',
            'campaign_id' => 'campaign',
            'monitor_type' => 'monitor type',
            'pc_spec_id' => 'PC spec',
        ];
    }

    public function messages(): array
    {
        return [
            'station_number.unique' => 'The station number has already been used.',
        ];
    }
}
