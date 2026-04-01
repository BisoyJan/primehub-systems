<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BreakPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'max_breaks' => ['required', 'integer', 'min:0', 'max:10'],
            'break_duration_minutes' => ['required', 'integer', 'min:1', 'max:120'],
            'max_lunch' => ['required', 'integer', 'min:0', 'max:3'],
            'lunch_duration_minutes' => ['required', 'integer', 'min:1', 'max:180'],
            'grace_period_seconds' => ['required', 'integer', 'min:0', 'max:1800'],
            'allowed_pause_reasons' => ['nullable', 'array'],
            'allowed_pause_reasons.*' => ['string', 'max:255'],
            'is_active' => ['boolean'],
            'retention_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'shift_reset_time' => ['nullable', 'date_format:H:i'],
        ];
    }

    public function attributes(): array
    {
        return [
            'max_breaks' => 'maximum breaks',
            'break_duration_minutes' => 'break duration',
            'max_lunch' => 'maximum lunch breaks',
            'lunch_duration_minutes' => 'lunch duration',
            'grace_period_seconds' => 'grace period',
            'allowed_pause_reasons' => 'pause reasons',
            'retention_months' => 'data retention',
        ];
    }
}
