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
            'type' => ['required', 'string', Rule::in(['1st_break', '2nd_break', 'lunch', 'combined'])],
            'station' => [$stationRequired ? 'required' : 'nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.in' => 'Invalid break type. Must be 1st_break, 2nd_break, lunch, or combined.',
            'station.required' => 'Station is required.',
        ];
    }
}
