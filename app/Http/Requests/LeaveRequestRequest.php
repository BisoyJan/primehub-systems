<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeaveRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $leaveType = $this->input('leave_type');

        // Date constraints based on leave type
        if ($leaveType === 'SL') {
            // Sick Leave: start date can be up to 3 weeks ago, end date up to 1 month ahead
            $minStartDate = now()->subWeeks(3)->format('Y-m-d');
            $maxEndDate = now()->addMonth()->format('Y-m-d');
            $startDateRule = ['required', 'date', 'after_or_equal:' . $minStartDate, 'before_or_equal:' . $maxEndDate];
            $endDateRule = ['required', 'date', 'after_or_equal:start_date', 'before_or_equal:' . $maxEndDate];
        } else {
            // Other leave types: start date must be today or future
            $startDateRule = ['required', 'date', 'after_or_equal:today'];
            $endDateRule = ['required', 'date', 'after_or_equal:start_date'];
        }

        $rules = [
            'leave_type' => ['required', Rule::in(['VL', 'SL', 'BL', 'SPL', 'LOA', 'LDV', 'UPTO', 'ML'])],
            'start_date' => $startDateRule,
            'end_date' => $endDateRule,
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'campaign_department' => ['required', 'string', 'max:255'],
            'medical_cert_submitted' => ['sometimes', 'boolean'],
            'medical_cert_file' => ['nullable', 'image', 'mimes:jpeg,jpg,png,gif,webp', 'max:4096'], // 4MB max
        ];

        // Allow employee_id for admins
        $user = $this->user();
        if ($user && in_array($user->role, ['Super Admin', 'Admin'])) {
            $rules['employee_id'] = ['sometimes', 'nullable', 'exists:users,id'];
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'leave_type.required' => 'Please select a leave type.',
            'leave_type.in' => 'Invalid leave type selected.',
            'start_date.required' => 'Start date is required.',
            'start_date.after_or_equal' => 'Start date is outside the allowed range.',
            'start_date.before_or_equal' => 'Start date cannot exceed 1 month from today.',
            'end_date.required' => 'End date is required.',
            'end_date.after_or_equal' => 'End date must be on or after the start date.',
            'end_date.before_or_equal' => 'End date cannot exceed 1 month from today.',
            'reason.required' => 'Please provide a reason for your leave request.',
            'reason.min' => 'Reason must be at least 10 characters.',
            'reason.max' => 'Reason cannot exceed 1000 characters.',
            'campaign_department.required' => 'Campaign/Department is required.',
            'medical_cert_file.image' => 'Medical certificate must be an image file.',
            'medical_cert_file.mimes' => 'Medical certificate must be a JPEG, PNG, GIF, or WebP image.',
            'medical_cert_file.max' => 'Medical certificate file size must not exceed 4MB.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Ensure medical_cert_submitted is boolean
        if ($this->has('medical_cert_submitted')) {
            $this->merge([
                'medical_cert_submitted' => filter_var($this->medical_cert_submitted, FILTER_VALIDATE_BOOLEAN),
            ]);
        }
    }
}
