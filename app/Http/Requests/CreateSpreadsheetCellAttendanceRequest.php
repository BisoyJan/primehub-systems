<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateSpreadsheetCellAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled via policy + role check in the controller.
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'shift_date' => ['required', 'date'],
            'actual_time_in' => ['nullable', 'date_format:H:i'],
            'actual_time_out' => ['nullable', 'date_format:H:i'],
            'hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'overtime_approved' => ['nullable', 'boolean'],
            'undertime_approval_reason' => ['nullable', 'in:generate_points,skip_points,lunch_used'],
            'is_set_home' => ['nullable', 'boolean'],
            'is_critical_day' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
