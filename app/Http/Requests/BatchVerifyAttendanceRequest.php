<?php

namespace App\Http\Requests;

use App\Enums\AttendanceSecondaryStatus;
use App\Enums\AttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;

class BatchVerifyAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled via policy in the controller.
    }

    public function rules(): array
    {
        return [
            'record_ids' => ['required', 'array', 'min:1'],
            'record_ids.*' => ['required', 'exists:attendances,id'],
            'status' => ['required', AttendanceStatus::validationIn()],
            'secondary_status' => ['nullable', AttendanceSecondaryStatus::validationIn()],
            'verification_notes' => ['nullable', 'string', 'max:1000'],
            'overtime_approved' => ['nullable', 'boolean'],
            'is_set_home' => ['nullable', 'boolean'],
        ];
    }
}
