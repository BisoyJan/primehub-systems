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
            'type' => ['required', 'string', Rule::in(['1st_break', '2nd_break', 'break', 'lunch', 'combined'])],
            'combined_break_count' => ['nullable', 'integer', 'min:1', 'max:10', 'required_if:type,combined'],
            'station' => [$stationRequired ? 'required' : 'nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.in' => 'Invalid break type.',
            'station.required' => 'Station is required.',
            'combined_break_count.required_if' => 'Break count is required for combined type.',
            'combined_break_count.min' => 'Break count must be at least 1.',
        ];
    }
}
