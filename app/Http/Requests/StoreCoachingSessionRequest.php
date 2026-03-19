<?php

namespace App\Http\Requests;

use App\Models\CoachingSession;
use App\Models\EmployeeSchedule;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->user();
        $isAdmin = in_array($user->role, ['Super Admin', 'Admin']);
        $coachingMode = $this->input('coaching_mode', 'assign');

        $rules = [
            'coaching_mode' => [$isAdmin ? 'required' : 'nullable', Rule::in(['assign', 'direct'])],
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
            'root_cause_others_notes' => ['nullable', 'string', 'max:2000'],
            // More Narrative
            'agent_strengths_wins' => ['nullable', 'string', 'max:10000'],
            'smart_action_plan' => ['required', 'string', 'min:10', 'max:10000'],
            'follow_up_date' => ['nullable', 'date', 'after_or_equal:today'],
            // Other
            'severity_flag' => ['sometimes', Rule::in(CoachingSession::SEVERITY_FLAGS)],
        ];

        if ($coachingMode === 'direct' && $isAdmin) {
            $rules['coach_id'] = ['nullable'];
            $rules['coachee_id'] = ['required', 'exists:users,id'];
        } else {
            $rules['coach_id'] = $isAdmin
                ? ['required', 'exists:users,id']
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
            'coach_id.required' => 'Please select a team lead.',
            'coach_id.exists' => 'The selected team lead does not exist.',
            'coachee_id.required' => 'Please select a coachee.',
            'coachee_id.exists' => 'The selected coachee does not exist.',
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
        ];
    }

    /**
     * Configure the validator instance — ensure agent belongs to the same campaign as the team lead.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $coachingMode = $this->input('coaching_mode', 'assign');

                if ($coachingMode === 'direct') {
                    $coacheeId = $this->input('coachee_id');
                    if (! $coacheeId) {
                        return;
                    }

                    $coachee = User::find($coacheeId);
                    if (! $coachee) {
                        return;
                    }

                    if ($coachee->role !== 'Team Lead') {
                        $validator->errors()->add(
                            'coachee_id',
                            'The selected coachee must be a Team Lead for direct coaching.',
                        );
                    }

                    $hasActiveCampaign = EmployeeSchedule::where('user_id', $coacheeId)
                        ->where('is_active', true)
                        ->whereNotNull('campaign_id')
                        ->exists();

                    if (! $hasActiveCampaign) {
                        $validator->errors()->add(
                            'coachee_id',
                            'The selected Team Lead does not have an active campaign.',
                        );
                    }

                    return;
                }

                // Assign mode: validate coachee belongs to same campaign as coach
                $coacheeId = $this->input('coachee_id');
                if (! $coacheeId) {
                    return;
                }

                $user = $this->user();
                $isAdmin = in_array($user->role, ['Super Admin', 'Admin']);
                $coachId = $isAdmin ? $this->input('coach_id') : $user->id;

                if (! $coachId) {
                    return;
                }

                // Get coach's active campaign
                $coachSchedule = EmployeeSchedule::where('user_id', $coachId)
                    ->where('is_active', true)
                    ->first();

                if (! $coachSchedule?->campaign_id) {
                    return;
                }

                // Check if coachee has an active schedule in the same campaign
                $coacheeInCampaign = EmployeeSchedule::where('user_id', $coacheeId)
                    ->where('campaign_id', $coachSchedule->campaign_id)
                    ->where('is_active', true)
                    ->exists();

                if (! $coacheeInCampaign) {
                    $validator->errors()->add(
                        'coachee_id',
                        'The selected agent does not belong to the same campaign as the team lead.',
                    );
                }
            },
        ];
    }
}
