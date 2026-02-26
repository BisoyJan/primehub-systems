<?php

namespace App\Http\Requests;

use App\Models\CoachingSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCoachingSessionRequest extends FormRequest
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
            'session_date' => ['required', 'date', 'before_or_equal:today'],
            // Agent Profile
            'profile_new_hire' => ['sometimes', 'boolean'],
            'profile_tenured' => ['sometimes', 'boolean'],
            'profile_returning' => ['sometimes', 'boolean'],
            'profile_previously_coached_same_issue' => ['sometimes', 'boolean'],
            // Purpose
            'purpose' => ['required', Rule::in(CoachingSession::PURPOSES)],
            // Focus Areas
            'focus_attendance_tardiness' => ['sometimes', 'boolean'],
            'focus_productivity' => ['sometimes', 'boolean'],
            'focus_compliance' => ['sometimes', 'boolean'],
            'focus_callouts' => ['sometimes', 'boolean'],
            'focus_recognition_milestones' => ['sometimes', 'boolean'],
            'focus_growth_development' => ['sometimes', 'boolean'],
            'focus_other' => ['sometimes', 'boolean'],
            'focus_other_notes' => ['nullable', 'required_if:focus_other,true', 'string', 'max:2000'],
            // Narrative
            'performance_description' => ['required', 'string', 'min:10', 'max:10000'],
            // Root Causes
            'root_cause_lack_of_skills' => ['sometimes', 'boolean'],
            'root_cause_lack_of_clarity' => ['sometimes', 'boolean'],
            'root_cause_personal_issues' => ['sometimes', 'boolean'],
            'root_cause_motivation_engagement' => ['sometimes', 'boolean'],
            'root_cause_health_fatigue' => ['sometimes', 'boolean'],
            'root_cause_workload_process' => ['sometimes', 'boolean'],
            'root_cause_peer_conflict' => ['sometimes', 'boolean'],
            'root_cause_others' => ['sometimes', 'boolean'],
            'root_cause_others_notes' => ['nullable', 'required_if:root_cause_others,true', 'string', 'max:2000'],
            // More Narrative
            'agent_strengths_wins' => ['nullable', 'string', 'max:10000'],
            'smart_action_plan' => ['required', 'string', 'min:10', 'max:10000'],
            'follow_up_date' => ['nullable', 'date'],
            // Other
            'severity_flag' => ['sometimes', Rule::in(CoachingSession::SEVERITY_FLAGS)],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'session_date.required' => 'Session date is required.',
            'session_date.before_or_equal' => 'Session date cannot be in the future.',
            'purpose.required' => 'Please select the purpose of coaching.',
            'purpose.in' => 'Invalid coaching purpose selected.',
            'performance_description.required' => 'Performance description is required.',
            'performance_description.min' => 'Performance description must be at least 10 characters.',
            'smart_action_plan.required' => 'SMART action plan is required.',
            'smart_action_plan.min' => 'SMART action plan must be at least 10 characters.',
            'focus_other_notes.required_if' => 'Please specify the other focus area.',
            'root_cause_others_notes.required_if' => 'Please specify the other root cause.',
        ];
    }
}
