<?php

namespace App\Http\Requests;

use App\Models\CoachingSession;
use App\Models\EmployeeSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCoachingSessionRequest extends FormRequest
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
        $user = $this->user();
        $isAdmin = in_array($user->role, ['Super Admin', 'Admin']);

        return [
            'team_lead_id' => $isAdmin
                ? ['required', 'exists:users,id']
                : ['nullable'],
            'agent_id' => ['required', 'exists:users,id'],
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
            'follow_up_date' => ['nullable', 'date', 'after_or_equal:today'],
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
            'team_lead_id.required' => 'Please select a team lead.',
            'team_lead_id.exists' => 'The selected team lead does not exist.',
            'agent_id.required' => 'Please select an agent.',
            'agent_id.exists' => 'The selected agent does not exist.',
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
            'follow_up_date.after_or_equal' => 'Follow-up date must be today or later.',
        ];
    }

    /**
     * Configure the validator instance — ensure agent belongs to the same campaign as the team lead.
     */
    public function after(): array
    {
        return [
            function (\Illuminate\Validation\Validator $validator) {
                $agentId = $this->input('agent_id');
                if (! $agentId) {
                    return;
                }

                $user = $this->user();
                $isAdmin = in_array($user->role, ['Super Admin', 'Admin']);
                $teamLeadId = $isAdmin ? $this->input('team_lead_id') : $user->id;

                if (! $teamLeadId) {
                    return;
                }

                // Get team lead's active campaign
                $tlSchedule = EmployeeSchedule::where('user_id', $teamLeadId)
                    ->where('is_active', true)
                    ->first();

                if (! $tlSchedule?->campaign_id) {
                    return;
                }

                // Check if agent has an active schedule in the same campaign
                $agentInCampaign = EmployeeSchedule::where('user_id', $agentId)
                    ->where('campaign_id', $tlSchedule->campaign_id)
                    ->where('is_active', true)
                    ->exists();

                if (! $agentInCampaign) {
                    $validator->errors()->add(
                        'agent_id',
                        'The selected agent does not belong to the same campaign as the team lead.',
                    );
                }
            },
        ];
    }
}
