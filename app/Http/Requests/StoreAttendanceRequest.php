<?php

namespace App\Http\Requests;

use App\Enums\AttendanceSecondaryStatus;
use App\Enums\AttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;

class StoreAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled via policy in the controller.
    }

    /**
     * Combine separated date + time inputs from the React form into the
     * single ISO datetime fields the validator expects.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('actual_time_in_date') && $this->filled('actual_time_in_time')) {
            $this->merge([
                'actual_time_in' => $this->input('actual_time_in_date').'T'.$this->input('actual_time_in_time'),
            ]);
        }

        if ($this->filled('actual_time_out_date') && $this->filled('actual_time_out_time')) {
            $this->merge([
                'actual_time_out' => $this->input('actual_time_out_date').'T'.$this->input('actual_time_out_time'),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'user_id' => 'required|exists:users,id',
            'shift_date' => 'required|date',
            'status' => ['nullable', AttendanceStatus::validationIn()],
            'secondary_status' => ['nullable', AttendanceSecondaryStatus::validationIn()],
            'actual_time_in' => 'nullable|date_format:Y-m-d\\TH:i',
            'actual_time_out' => 'nullable|date_format:Y-m-d\\TH:i',
            'notes' => 'nullable|string|max:500',
            'is_set_home' => 'nullable|boolean',
            'undertime_approval_status' => 'nullable|in:approved',
            'undertime_approval_reason' => 'nullable|in:generate_points,skip_points,lunch_used',
        ];
    }
}
