<?php

namespace App\Http\Requests;

use App\Enums\AttendanceSecondaryStatus;
use App\Enums\AttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;

class GenerateAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled via policy in the controller.
    }

    public function rules(): array
    {
        return [
            'user_id' => 'required|exists:users,id',
            'shift_date' => 'required|date',
            'actual_time_in' => 'nullable|date_format:Y-m-d\\TH:i',
            'actual_time_out' => 'nullable|date_format:Y-m-d\\TH:i',
            'notes' => 'nullable|string|max:500',
            'status' => ['nullable', AttendanceStatus::validationIn()],
            'secondary_status' => ['nullable', AttendanceSecondaryStatus::validationIn()],
            'verification_notes' => 'nullable|string|max:1000',
            'overtime_approved' => 'nullable|boolean',
            'is_set_home' => 'nullable|boolean',
        ];
    }
}
