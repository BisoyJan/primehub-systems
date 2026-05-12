<?php

namespace App\Http\Requests;

use App\Models\AttendancePoint;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkStoreAttendancePointRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', AttendancePoint::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'entries' => ['required', 'array', 'min:1', 'max:100'],
            'entries.*.user_id' => ['required', 'exists:users,id'],
            'entries.*.shift_date' => ['required', 'date', 'before_or_equal:today'],
            'entries.*.point_type' => ['required', Rule::in(['whole_day_absence', 'half_day_absence', 'undertime', 'undertime_more_than_hour', 'tardy'])],
            'entries.*.is_advised' => ['boolean'],
            'entries.*.violation_details' => ['nullable', 'string', 'max:1000'],
            'entries.*.notes' => ['nullable', 'string', 'max:1000'],
            'entries.*.tardy_minutes' => ['nullable', 'integer', 'min:0'],
            'entries.*.undertime_minutes' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'entries.*.user_id' => 'employee',
            'entries.*.shift_date' => 'violation date',
            'entries.*.point_type' => 'violation type',
            'entries.*.is_advised' => 'advised status',
            'entries.*.violation_details' => 'violation details',
            'entries.*.tardy_minutes' => 'tardy duration',
            'entries.*.undertime_minutes' => 'undertime duration',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'entries.required' => 'At least one entry is required.',
            'entries.min' => 'At least one entry is required.',
            'entries.max' => 'Cannot submit more than 100 entries at once.',
            'entries.*.user_id.required' => 'Please select an employee for each entry.',
            'entries.*.user_id.exists' => 'One or more selected employees do not exist.',
            'entries.*.shift_date.required' => 'Please enter a violation date for each entry.',
            'entries.*.shift_date.before_or_equal' => 'Violation dates cannot be in the future.',
            'entries.*.point_type.required' => 'Please select a violation type for each entry.',
            'entries.*.point_type.in' => 'Invalid violation type selected.',
        ];
    }
}
