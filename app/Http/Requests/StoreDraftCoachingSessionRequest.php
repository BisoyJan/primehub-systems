<?php

namespace App\Http\Requests;

use App\Models\CoachingSession;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDraftCoachingSessionRequest extends FormRequest
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
     * Drafts have relaxed validation — only coachee selection is required.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->user();
        $isAdmin = in_array($user->role, ['Super Admin', 'Admin']);
        $coachingMode = $this->input('coaching_mode', 'assign');

        $rules = [
            'coaching_mode' => ['nullable', Rule::in(['assign', 'direct'])],
            'session_date' => ['nullable', 'date', 'before_or_equal:today'],
            // Agent Profile
            'profile_new_hire' => ['sometimes', 'boolean'],
            'profile_tenured' => ['sometimes', 'boolean'],
            'profile_returning' => ['sometimes', 'boolean'],
            'profile_previously_coached_same_issue' => ['sometimes', 'boolean'],
            // Purpose
            'purpose' => ['nullable', Rule::in(CoachingSession::PURPOSES)],
            // Focus Areas
            'focus_attendance_tardiness' => ['sometimes', 'boolean'],
            'focus_productivity' => ['sometimes', 'boolean'],
            'focus_compliance' => ['sometimes', 'boolean'],
            'focus_callouts' => ['sometimes', 'boolean'],
            'focus_recognition_milestones' => ['sometimes', 'boolean'],
            'focus_growth_development' => ['sometimes', 'boolean'],
            'focus_other' => ['sometimes', 'boolean'],
            'focus_other_notes' => ['nullable', 'string', 'max:100000'],
            // Narrative
            'performance_description' => ['nullable', 'string', 'max:100000'],
            // Root Causes
            'root_cause_lack_of_skills' => ['sometimes', 'boolean'],
            'root_cause_lack_of_clarity' => ['sometimes', 'boolean'],
            'root_cause_personal_issues' => ['sometimes', 'boolean'],
            'root_cause_motivation_engagement' => ['sometimes', 'boolean'],
            'root_cause_health_fatigue' => ['sometimes', 'boolean'],
            'root_cause_workload_process' => ['sometimes', 'boolean'],
            'root_cause_peer_conflict' => ['sometimes', 'boolean'],
            'root_cause_others' => ['sometimes', 'boolean'],
            'root_cause_others_notes' => ['nullable', 'string', 'max:7000'],
            // More Narrative
            'agent_strengths_wins' => ['nullable', 'string', 'max:100000'],
            'smart_action_plan' => ['nullable', 'string', 'max:100000'],
            'follow_up_date' => ['nullable', 'date'],
            // Other
            'severity_flag' => ['sometimes', Rule::in(CoachingSession::SEVERITY_FLAGS)],
            // Attachments
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['image', 'mimes:jpeg,jpg,png,gif,webp', 'max:4096'],
        ];

        if ($coachingMode === 'direct' && $isAdmin) {
            $rules['coach_id'] = ['nullable'];
            $rules['coachee_id'] = ['required', 'exists:users,id'];
        } else {
            $rules['coach_id'] = $isAdmin
                ? ['nullable', 'exists:users,id']
                : ['nullable'];
            $rules['coachee_id'] = ['required', 'exists:users,id'];
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'coachee_id.required' => 'Please select a coachee before saving as draft.',
            'coachee_id.exists' => 'The selected coachee does not exist.',
            'attachments.max' => 'You can upload a maximum of 10 images.',
            'attachments.*.image' => 'Each attachment must be an image.',
            'attachments.*.mimes' => 'Only JPEG, PNG, GIF, and WebP images are allowed.',
            'attachments.*.max' => 'Each image must be less than 4MB.',
        ];
    }
}
