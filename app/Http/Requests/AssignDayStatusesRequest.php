<?php

namespace App\Http\Requests;

use App\Models\LeaveRequestDay;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignDayStatusesRequest extends FormRequest
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
        return [
            'day_statuses' => ['required', 'array', 'min:1'],
            'day_statuses.*.date' => ['required', 'date'],
            'day_statuses.*.status' => [
                'required',
                Rule::in([
                    LeaveRequestDay::STATUS_SL_CREDITED,
                    LeaveRequestDay::STATUS_NCNS,
                    LeaveRequestDay::STATUS_ADVISED_ABSENCE,
                    LeaveRequestDay::STATUS_VL_CREDITED,
                    LeaveRequestDay::STATUS_UPTO,
                ]),
            ],
            'day_statuses.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'day_statuses.required' => 'Per-day statuses are required.',
            'day_statuses.array' => 'Per-day statuses must be an array.',
            'day_statuses.min' => 'At least one day status must be provided.',
            'day_statuses.*.date.required' => 'Each day status must include a date.',
            'day_statuses.*.date.date' => 'Each day status date must be a valid date.',
            'day_statuses.*.status.required' => 'Each day must have a status assigned.',
            'day_statuses.*.status.in' => 'Invalid day status. Must be sl_credited, ncns, or advised_absence.',
            'day_statuses.*.notes.max' => 'Day notes cannot exceed 500 characters.',
        ];
    }

    /**
     * Additional validation after the basic rules pass.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $leaveRequest = $this->route('leaveRequest');
            if (! $leaveRequest) {
                return;
            }

            // Verify leave type and status before running date/credit checks
            if ($leaveRequest->leave_type !== 'SL') {
                $validator->errors()->add('error', 'Per-day status assignment is only available for Sick Leave requests.');

                return;
            }

            if ($leaveRequest->status !== 'approved') {
                $validator->errors()->add('error', 'Per-day statuses can only be updated for approved leave requests.');

                return;
            }

            $dayStatuses = $this->input('day_statuses', []);
            if (empty($dayStatuses)) {
                return;
            }

            $startDate = Carbon::parse($leaveRequest->start_date);
            $endDate = Carbon::parse($leaveRequest->end_date);

            // Get denied dates
            $deniedDates = [];
            if ($leaveRequest->has_partial_denial) {
                $deniedDates = $leaveRequest->deniedDates()
                    ->pluck('denied_date')
                    ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
                    ->toArray();
            }

            // Validate each date is within the leave period and not denied
            foreach ($dayStatuses as $index => $dayStatus) {
                $date = Carbon::parse($dayStatus['date']);

                if ($date->lt($startDate) || $date->gt($endDate)) {
                    $validator->errors()->add(
                        "day_statuses.{$index}.date",
                        "Date {$dayStatus['date']} is outside the leave period ({$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')})."
                    );
                }

                if (in_array($dayStatus['date'], $deniedDates)) {
                    $validator->errors()->add(
                        "day_statuses.{$index}.date",
                        "Date {$dayStatus['date']} has been denied and cannot be assigned a status."
                    );
                }
            }

            // Validate sl_credited count does not exceed available credits
            $creditedCount = collect($dayStatuses)
                ->where('status', LeaveRequestDay::STATUS_SL_CREDITED)
                ->count();

            if ($creditedCount > 0) {
                $leaveCreditService = app(\App\Services\LeaveCreditService::class);
                $year = $startDate->year;
                $balance = $leaveCreditService->getBalance($leaveRequest->user, $year);

                // If editing existing day statuses, add back previously deducted credits for accurate check
                $previouslyDeducted = (float) ($leaveRequest->credits_deducted ?? 0);
                $availableCredits = $balance + $previouslyDeducted;

                if ($creditedCount > $availableCredits) {
                    $validator->errors()->add(
                        'day_statuses',
                        "Cannot assign {$creditedCount} day(s) as SL Credited. Only ".floor($availableCredits).' credit(s) available.'
                    );
                }
            }
        });
    }
}
