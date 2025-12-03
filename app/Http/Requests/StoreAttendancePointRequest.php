<?php

namespace App\Http\Requests;

use App\Models\AttendancePoint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttendancePointRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'exists:users,id'],
            'shift_date' => ['required', 'date', 'before_or_equal:today'],
            'point_type' => ['required', Rule::in(['whole_day_absence', 'half_day_absence', 'undertime', 'tardy'])],
            'is_advised' => ['boolean'],
            'violation_details' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'tardy_minutes' => ['nullable', 'integer', 'min:0'],
            'undertime_minutes' => ['nullable', 'integer', 'min:0'],
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
            'user_id' => 'employee',
            'shift_date' => 'violation date',
            'point_type' => 'violation type',
            'is_advised' => 'advised status',
            'violation_details' => 'violation details',
            'tardy_minutes' => 'tardy duration',
            'undertime_minutes' => 'undertime duration',
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
            'user_id.required' => 'Please select an employee.',
            'user_id.exists' => 'The selected employee does not exist.',
            'shift_date.required' => 'Please enter the violation date.',
            'shift_date.before_or_equal' => 'The violation date cannot be in the future.',
            'point_type.required' => 'Please select a violation type.',
            'point_type.in' => 'Invalid violation type selected.',
        ];
    }
}
