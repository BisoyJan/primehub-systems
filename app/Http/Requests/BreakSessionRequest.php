<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BreakSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $role = $this->user()?->role;
        $stationRequired = in_array($role, ['Agent', 'Team Lead']);

        return [
            'type' => ['required', 'string', Rule::in(['1st_break', '2nd_break', 'break', 'lunch', 'combined', 'combined_break'])],
            'combined_break_count' => [
                'nullable',
                'integer',
                $this->input('type') === 'combined_break' ? 'min:2' : 'min:1',
                'max:10',
                Rule::requiredIf(in_array($this->input('type'), ['combined', 'combined_break'])),
            ],
            'station' => [$stationRequired ? 'required' : 'nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.in' => 'Invalid break type.',
            'station.required' => 'Station is required.',
            'combined_break_count.required' => 'Break count is required for combined types.',
            'combined_break_count.min' => $this->input('type') === 'combined_break'
                ? 'Combined breaks must include at least 2 breaks.'
                : 'Break count must be at least 1.',
        ];
    }
}
