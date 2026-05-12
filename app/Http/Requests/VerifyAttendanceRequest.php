<?php

namespace App\Http\Requests;

use App\Enums\AttendanceSecondaryStatus;
use App\Enums\AttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;

class VerifyAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled via policy in the controller.
    }

    public function rules(): array
    {
        return [
            'status' => ['required', AttendanceStatus::validationIn()],
            'secondary_status' => ['nullable', AttendanceSecondaryStatus::validationIn()],
            'actual_time_in' => ['nullable', 'date'],
            'actual_time_out' => ['nullable', 'date'],
            'verification_notes' => ['nullable', 'string', 'max:1000'],
            'overtime_approved' => ['nullable', 'boolean'],
            'is_set_home' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
            'adjust_leave' => ['nullable', 'boolean'],
            'undertime_approval_action' => ['nullable', 'in:approve,reject,request'],
            'undertime_approval_reason' => ['nullable', 'in:generate_points,skip_points,lunch_used'],
        ];
    }
}
