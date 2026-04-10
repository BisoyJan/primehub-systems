<?php

namespace App\Http\Requests;

use App\Http\Traits\SanitizesHtmlInput;
use App\Models\CoachingSession;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCoachingSessionRequest extends FormRequest
{
    use SanitizesHtmlInput;

    protected function prepareForValidation(): void
    {
        $this->sanitizeHtmlFields();
    }

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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isDraft = $this->route('session')?->is_draft;

        return [
            'session_date' => [$isDraft ? 'nullable' : 'required', 'date', 'before_or_equal:today'],
            // Agent Profile
            'profile_new_hire' => ['sometimes', 'boolean'],
            'profile_tenured' => ['sometimes', 'boolean'],
            'profile_returning' => ['sometimes', 'boolean'],
            'profile_previously_coached_same_issue' => ['sometimes', 'boolean'],
            // Purpose
            'purpose' => [$isDraft ? 'nullable' : 'required', Rule::in(CoachingSession::PURPOSES)],
            // Focus Areas
            'focus_attendance_tardiness' => ['sometimes', 'boolean'],
            'focus_productivity' => ['sometimes', 'boolean'],
            'focus_compliance' => ['sometimes', 'boolean'],
            'focus_callouts' => ['sometimes', 'boolean'],
            'focus_recognition_milestones' => ['sometimes', 'boolean'],
            'focus_growth_development' => ['sometimes', 'boolean'],
            'focus_other' => ['sometimes', 'boolean'],
            'focus_other_notes' => ['nullable', $isDraft ? 'sometimes' : 'required_if:focus_other,true', 'string', 'max:100000'],
            // Narrative
            'performance_description' => array_filter([$isDraft ? 'nullable' : 'required', 'string', 'max:100000', $isDraft ? null : $this->richTextMinLength(10)]),
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
            'smart_action_plan' => array_filter([$isDraft ? 'nullable' : 'required', 'string', 'max:100000', $isDraft ? null : $this->richTextMinLength(10)]),
            'follow_up_date' => array_filter(['nullable', 'date', $isDraft ? null : 'after_or_equal:today']),
            // Other
            'severity_flag' => ['sometimes', Rule::in(CoachingSession::SEVERITY_FLAGS)],
            // Attachments
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['image', 'mimes:jpeg,jpg,png,gif,webp', 'max:4096'],
            'removed_attachments' => ['nullable', 'array'],
            'removed_attachments.*' => ['integer'],
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
            'follow_up_date.after_or_equal' => 'Follow-up date must be today or later.',
            'attachments.max' => 'You can upload a maximum of 10 images.',
            'attachments.*.image' => 'Each attachment must be an image.',
            'attachments.*.mimes' => 'Only JPEG, PNG, GIF, and WebP images are allowed.',
            'attachments.*.max' => 'Each image must be less than 4MB.',
        ];
    }

    /**
     * Additional validation after rules pass.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $isDraft = $this->route('session')?->is_draft;

                // Skip checkbox group validation for drafts (relaxed validation)
                if ($isDraft) {
                    return;
                }

                // Require at least one Agent Profile checkbox
                if (! $this->boolean('profile_new_hire')
                    && ! $this->boolean('profile_tenured')
                    && ! $this->boolean('profile_returning')
                    && ! $this->boolean('profile_previously_coached_same_issue')) {
                    $validator->errors()->add('profile', 'Please select at least one agent profile.');
                }

                // Require at least one Focus Area checkbox
                if (! $this->boolean('focus_attendance_tardiness')
                    && ! $this->boolean('focus_productivity')
                    && ! $this->boolean('focus_compliance')
                    && ! $this->boolean('focus_callouts')
                    && ! $this->boolean('focus_recognition_milestones')
                    && ! $this->boolean('focus_growth_development')
                    && ! $this->boolean('focus_other')) {
                    $validator->errors()->add('focus', 'Please select at least one focus area.');
                }

                // Require at least one Root Cause checkbox
                if (! $this->boolean('root_cause_lack_of_skills')
                    && ! $this->boolean('root_cause_lack_of_clarity')
                    && ! $this->boolean('root_cause_personal_issues')
                    && ! $this->boolean('root_cause_motivation_engagement')
                    && ! $this->boolean('root_cause_health_fatigue')
                    && ! $this->boolean('root_cause_workload_process')
                    && ! $this->boolean('root_cause_peer_conflict')
                    && ! $this->boolean('root_cause_others')) {
                    $validator->errors()->add('root_cause', 'Please select at least one root cause.');
                }
            },
        ];
    }
}
