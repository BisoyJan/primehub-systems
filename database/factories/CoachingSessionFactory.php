<?php

namespace Database\Factories;

use App\Models\CoachingSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CoachingSession>
 */
class CoachingSessionFactory extends Factory
{
    protected $model = CoachingSession::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'coachee_id' => User::factory()->state(['role' => 'Agent', 'is_approved' => true]),
            'coach_id' => User::factory()->state(['role' => 'Team Lead', 'is_approved' => true]),
            'session_date' => fake()->dateTimeBetween('-60 days', 'now')->format('Y-m-d'),
            // Agent Profile
            'profile_new_hire' => fake()->boolean(30),
            'profile_tenured' => fake()->boolean(40),
            'profile_returning' => fake()->boolean(10),
            'profile_previously_coached_same_issue' => fake()->boolean(20),
            // Purpose
            'purpose' => fake()->randomElement(CoachingSession::PURPOSES),
            // Focus Areas
            'focus_attendance_tardiness' => fake()->boolean(40),
            'focus_productivity' => fake()->boolean(30),
            'focus_compliance' => fake()->boolean(20),
            'focus_callouts' => fake()->boolean(15),
            'focus_recognition_milestones' => fake()->boolean(15),
            'focus_growth_development' => fake()->boolean(25),
            'focus_other' => false,
            'focus_other_notes' => null,
            // Narrative
            'performance_description' => fake()->paragraphs(2, true),
            // Root Causes
            'root_cause_lack_of_skills' => fake()->boolean(30),
            'root_cause_lack_of_clarity' => fake()->boolean(20),
            'root_cause_personal_issues' => fake()->boolean(10),
            'root_cause_motivation_engagement' => fake()->boolean(20),
            'root_cause_health_fatigue' => fake()->boolean(10),
            'root_cause_workload_process' => fake()->boolean(15),
            'root_cause_peer_conflict' => fake()->boolean(10),
            'root_cause_others' => false,
            'root_cause_others_notes' => null,
            // More Narrative
            'agent_strengths_wins' => fake()->optional(0.7)->paragraph(),
            'smart_action_plan' => fake()->paragraphs(2, true),
            'follow_up_date' => fake()->optional(0.5)->dateTimeBetween('now', '+30 days')?->format('Y-m-d'),
            // Defaults
            'ack_status' => 'Pending',
            'compliance_status' => 'Awaiting_Agent_Ack',
            'severity_flag' => fake()->randomElement(['Normal', 'Normal', 'Normal', 'Critical']),
        ];
    }

    /**
     * State: session has been acknowledged by agent.
     */
    public function acknowledged(): static
    {
        return $this->state(fn (array $attributes) => [
            'ack_status' => 'Acknowledged',
            'ack_timestamp' => now(),
            'ack_comment' => fake()->optional(0.5)->sentence(),
            'compliance_status' => 'For_Review',
        ]);
    }

    /**
     * State: session has been verified by compliance.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'ack_status' => 'Acknowledged',
            'ack_timestamp' => now()->subDays(2),
            'compliance_status' => 'Verified',
            'compliance_reviewer_id' => User::factory()->state(['role' => 'HR', 'is_approved' => true]),
            'compliance_review_timestamp' => now(),
        ]);
    }

    /**
     * State: session has been rejected by compliance.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'ack_status' => 'Acknowledged',
            'ack_timestamp' => now()->subDays(2),
            'compliance_status' => 'Rejected',
            'compliance_reviewer_id' => User::factory()->state(['role' => 'HR', 'is_approved' => true]),
            'compliance_review_timestamp' => now(),
            'compliance_notes' => fake()->sentence(),
        ]);
    }

    /**
     * State: session has been disputed by agent.
     */
    public function disputed(): static
    {
        return $this->state(fn (array $attributes) => [
            'ack_status' => 'Disputed',
            'ack_timestamp' => now(),
            'ack_comment' => fake()->sentence(),
            'compliance_status' => 'Awaiting_Agent_Ack',
        ]);
    }

    /**
     * State: session where a Team Lead is the coachee (coached by admin).
     */
    public function forTeamLead(): static
    {
        return $this->state(fn (array $attributes) => [
            'coachee_id' => User::factory()->state(['role' => 'Team Lead', 'is_approved' => true]),
            'coach_id' => User::factory()->state(['role' => 'Super Admin', 'is_approved' => true]),
        ]);
    }

    /**
     * State: critical severity.
     */
    public function critical(): static
    {
        return $this->state(fn (array $attributes) => [
            'severity_flag' => 'Critical',
        ]);
    }
}
