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
    }

    public function rules(): array
    {
        $station = $this->route('station');
        $ignoreId = is_object($station) ? $station->id : (is_numeric($station) ? (int)$station : null);

        return [
            'site_id' => ['required', 'exists:sites,id'],
            'station_number' => [
                'required', 'string', 'max:255',
                Rule::unique('stations', 'station_number')->ignore($ignoreId)
            ],
            'campaign_id' => ['required', 'exists:campaigns,id'],
            'status' => ['required', 'string', 'max:255'],
            'monitor_type' => ['required', Rule::in(['single', 'dual'])],
            'pc_spec_id' => ['nullable', 'exists:pc_specs,id'],
            'monitor_ids' => ['nullable', 'array'],
            'monitor_ids.*.id' => ['required', 'exists:monitor_specs,id'],
            'monitor_ids.*.quantity' => ['required', 'integer', 'min:1', 'max:2'],
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
            'monitor_ids' => 'monitors',
        ];
    }

    public function messages(): array
    {
        return [
            'station_number.unique' => 'The station number has already been used.',
        ];
    }
}
