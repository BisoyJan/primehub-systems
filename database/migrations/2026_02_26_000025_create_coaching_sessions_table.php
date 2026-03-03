<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('coaching_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_lead_id')->constrained('users')->cascadeOnDelete();
            $table->date('session_date');

            // Agent's Profile (multi-select booleans)
            $table->boolean('profile_new_hire')->default(false);
            $table->boolean('profile_tenured')->default(false);
            $table->boolean('profile_returning')->default(false);
            $table->boolean('profile_previously_coached_same_issue')->default(false);

            // Purpose of Coaching (single select)
            $table->enum('purpose', [
                'performance_behavior_issue',
                'regular_checkin_progress_review',
                'reinforce_positive_behavior_growth',
                'recognition_appreciation',
            ]);

            // Focus Areas (multi-select booleans)
            $table->boolean('focus_attendance_tardiness')->default(false);
            $table->boolean('focus_productivity')->default(false);
            $table->boolean('focus_compliance')->default(false);
            $table->boolean('focus_callouts')->default(false);
            $table->boolean('focus_recognition_milestones')->default(false);
            $table->boolean('focus_growth_development')->default(false);
            $table->boolean('focus_other')->default(false);
            $table->text('focus_other_notes')->nullable();

            // Narrative Fields
            $table->text('performance_description');

            // Root Causes (multi-select booleans)
            $table->boolean('root_cause_lack_of_skills')->default(false);
            $table->boolean('root_cause_lack_of_clarity')->default(false);
            $table->boolean('root_cause_personal_issues')->default(false);
            $table->boolean('root_cause_motivation_engagement')->default(false);
            $table->boolean('root_cause_health_fatigue')->default(false);
            $table->boolean('root_cause_workload_process')->default(false);
            $table->boolean('root_cause_peer_conflict')->default(false);
            $table->boolean('root_cause_others')->default(false);
            $table->text('root_cause_others_notes')->nullable();

            // More Narrative Fields
            $table->text('agent_strengths_wins')->nullable();
            $table->text('smart_action_plan');
            $table->date('follow_up_date')->nullable();

            // Acknowledgement & Compliance
            $table->enum('ack_status', ['Pending', 'Acknowledged', 'Disputed'])->default('Pending');
            $table->timestamp('ack_timestamp')->nullable();
            $table->text('ack_comment')->nullable();

            $table->enum('compliance_status', [
                'Awaiting_Agent_Ack',
                'For_Review',
                'Verified',
                'Rejected',
            ])->default('Awaiting_Agent_Ack');
            $table->foreignId('compliance_reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('compliance_review_timestamp')->nullable();
            $table->text('compliance_notes')->nullable();

            // Other
            $table->enum('severity_flag', ['Normal', 'Critical'])->default('Normal');
            $table->string('attachment_url')->nullable();

            $table->timestamps();

            // Indexes for common queries
            $table->index('agent_id');
            $table->index('team_lead_id');
            $table->index('session_date');
            $table->index('ack_status');
            $table->index('compliance_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coaching_sessions');
    }
};
