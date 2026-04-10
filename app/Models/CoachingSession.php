<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CoachingSession extends Model
{
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'coachee_id',
        'coach_id',
        'session_date',
        // Agent's Profile
        'profile_new_hire',
        'profile_tenured',
        'profile_returning',
        'profile_previously_coached_same_issue',
        // Purpose
        'purpose',
        // Focus Areas
        'focus_attendance_tardiness',
        'focus_productivity',
        'focus_compliance',
        'focus_callouts',
        'focus_recognition_milestones',
        'focus_growth_development',
        'focus_other',
        'focus_other_notes',
        // Narrative
        'performance_description',
        // Root Causes
        'root_cause_lack_of_skills',
        'root_cause_lack_of_clarity',
        'root_cause_personal_issues',
        'root_cause_motivation_engagement',
        'root_cause_health_fatigue',
        'root_cause_workload_process',
        'root_cause_peer_conflict',
        'root_cause_others',
        'root_cause_others_notes',
        // More Narrative
        'agent_strengths_wins',
        'smart_action_plan',
        'follow_up_date',
        // Acknowledgement & Compliance
        'ack_status',
        'ack_timestamp',
        'ack_comment',
        'agent_response',
        'agent_response_at',
        'compliance_status',
        'compliance_reviewer_id',
        'compliance_review_timestamp',
        'compliance_notes',
        // Other
        'severity_flag',
        'attachment_url',
        // Draft
        'is_draft',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'session_date' => 'date',
            'follow_up_date' => 'date',
            'ack_timestamp' => 'datetime',
            'agent_response_at' => 'datetime',
            'compliance_review_timestamp' => 'datetime',
            'submitted_at' => 'datetime',
            'is_draft' => 'boolean',
            // Booleans - Agent Profile
            'profile_new_hire' => 'boolean',
            'profile_tenured' => 'boolean',
            'profile_returning' => 'boolean',
            'profile_previously_coached_same_issue' => 'boolean',
            // Booleans - Focus Areas
            'focus_attendance_tardiness' => 'boolean',
            'focus_productivity' => 'boolean',
            'focus_compliance' => 'boolean',
            'focus_callouts' => 'boolean',
            'focus_recognition_milestones' => 'boolean',
            'focus_growth_development' => 'boolean',
            'focus_other' => 'boolean',
            // Booleans - Root Causes
            'root_cause_lack_of_skills' => 'boolean',
            'root_cause_lack_of_clarity' => 'boolean',
            'root_cause_personal_issues' => 'boolean',
            'root_cause_motivation_engagement' => 'boolean',
            'root_cause_health_fatigue' => 'boolean',
            'root_cause_workload_process' => 'boolean',
            'root_cause_peer_conflict' => 'boolean',
            'root_cause_others' => 'boolean',
        ];
    }

    /**
     * Purpose enum values.
     */
    public const PURPOSES = [
        'performance_behavior_issue',
        'regular_checkin_progress_review',
        'reinforce_positive_behavior_growth',
        'recognition_appreciation',
    ];

    /**
     * Acknowledgement status enum values.
     */
    public const ACK_STATUSES = [
        'Pending',
        'Acknowledged',
        'Disputed',
    ];

    /**
     * Compliance status enum values.
     */
    public const COMPLIANCE_STATUSES = [
        'Awaiting_Agent_Ack',
        'For_Review',
        'Verified',
        'Rejected',
    ];

    /**
     * Severity flag enum values.
     */
    public const SEVERITY_FLAGS = [
        'Normal',
        'Critical',
    ];

    /**
     * Human-readable purpose labels.
     */
    public const PURPOSE_LABELS = [
        'performance_behavior_issue' => 'Address a performance/behavior issue',
        'regular_checkin_progress_review' => 'Conduct regular check-in or progress review',
        'reinforce_positive_behavior_growth' => 'Reinforce positive behavior or growth',
        'recognition_appreciation' => 'Recognition or appreciation coaching',
    ];

    // ─── Relationships ──────────────────────────────────────────────

    /**
     * Get the person being coached (agent or team lead).
     */
    public function coachee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coachee_id');
    }

    /**
     * Get the coach who conducted the session.
     */
    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    /**
     * Get the compliance reviewer for this session.
     */
    public function complianceReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'compliance_reviewer_id');
    }

    /**
     * Get the image attachments for this session.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(CoachingSessionAttachment::class);
    }

    // ─── Scopes ─────────────────────────────────────────────────────

    /**
     * Scope to sessions for a specific coachee.
     */
    public function scopeForCoachee(Builder $query, int $coacheeId): Builder
    {
        return $query->where('coachee_id', $coacheeId);
    }

    /**
     * Scope to sessions created by a specific coach.
     */
    public function scopeForCoach(Builder $query, int $coachId): Builder
    {
        return $query->where('coach_id', $coachId);
    }

    /**
     * Scope to sessions for agents within a specific campaign.
     */
    public function scopeForCampaign(Builder $query, int $campaignId): Builder
    {
        return $query->whereHas('coachee', function (Builder $q) use ($campaignId) {
            $q->whereHas('activeSchedule', function (Builder $sq) use ($campaignId) {
                $sq->where('campaign_id', $campaignId);
            });
        });
    }

    /**
     * Scope to only draft sessions.
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('is_draft', true);
    }

    /**
     * Scope to only submitted (non-draft) sessions.
     */
    public function scopeSubmitted(Builder $query): Builder
    {
        return $query->where('is_draft', false);
    }

    /**
     * Scope to sessions with pending acknowledgement.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('ack_status', 'Pending');
    }

    /**
     * Scope to sessions ready for compliance review.
     */
    public function scopeForReview(Builder $query): Builder
    {
        return $query->where('compliance_status', 'For_Review');
    }

    /**
     * Scope to verified sessions.
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('compliance_status', 'Verified');
    }

    /**
     * Scope to search by agent name or team lead name.
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (! $search) {
            return $query;
        }

        $searchTerm = '%'.$search.'%';

        return $query->where(function (Builder $q) use ($searchTerm) {
            $q->whereHas('coachee', function (Builder $aq) use ($searchTerm) {
                $aq->where(function ($q2) use ($searchTerm) {
                    $q2->whereRaw("CONCAT(first_name, ' ', COALESCE(CONCAT(middle_name, '. '), ''), last_name) LIKE ?", [$searchTerm])
                        ->orWhere('first_name', 'like', $searchTerm)
                        ->orWhere('last_name', 'like', $searchTerm)
                        ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$searchTerm]);
                });
            })->orWhereHas('coach', function (Builder $tq) use ($searchTerm) {
                $tq->where(function ($q2) use ($searchTerm) {
                    $q2->whereRaw("CONCAT(first_name, ' ', COALESCE(CONCAT(middle_name, '. '), ''), last_name) LIKE ?", [$searchTerm])
                        ->orWhere('first_name', 'like', $searchTerm)
                        ->orWhere('last_name', 'like', $searchTerm)
                        ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$searchTerm]);
                });
            });
        });
    }

    /**
     * Scope to filter by coaching status.
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('compliance_status', $status);
    }

    /**
     * Scope to filter by date range.
     */
    public function scopeDateRange(Builder $query, ?string $startDate, ?string $endDate): Builder
    {
        if ($startDate) {
            $query->where('session_date', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('session_date', '<=', $endDate);
        }

        return $query;
    }

    /**
     * Get human-readable purpose label.
     */
    public function getPurposeLabelAttribute(): string
    {
        return self::PURPOSE_LABELS[$this->purpose] ?? $this->purpose;
    }

    /**
     * Get focus areas as an array of active focus labels.
     */
    public function getActiveFocusAreasAttribute(): array
    {
        $areas = [];
        $map = [
            'focus_attendance_tardiness' => 'Attendance/Tardiness',
            'focus_productivity' => 'Productivity',
            'focus_compliance' => 'Compliance',
            'focus_callouts' => 'Callouts',
            'focus_recognition_milestones' => 'Recognition/Milestones',
            'focus_growth_development' => 'Growth/Development',
            'focus_other' => 'Other',
        ];

        foreach ($map as $field => $label) {
            if ($this->{$field}) {
                $areas[] = $label;
            }
        }

        return $areas;
    }

    /**
     * Get root causes as an array of active cause labels.
     */
    public function getActiveRootCausesAttribute(): array
    {
        $causes = [];
        $map = [
            'root_cause_lack_of_skills' => 'Lack of Skills / Knowledge',
            'root_cause_lack_of_clarity' => 'Lack of Clarity on Expectations',
            'root_cause_personal_issues' => 'Personal Issues',
            'root_cause_motivation_engagement' => 'Motivation / Engagement',
            'root_cause_health_fatigue' => 'Health / Fatigue',
            'root_cause_workload_process' => 'Workload or Process Issues',
            'root_cause_peer_conflict' => 'Peer / Team Conflict',
            'root_cause_others' => 'Progress Update',
        ];

        foreach ($map as $field => $label) {
            if ($this->{$field}) {
                $causes[] = $label;
            }
        }

        return $causes;
    }
}
