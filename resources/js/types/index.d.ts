import { InertiaLinkProps } from '@inertiajs/react';
import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href?: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
    badge?: number;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    sidebarOpen: boolean;
    coachingPendingAck?: number;
    [key: string]: unknown;
}

export type UserRole = 'Super Admin' | 'Admin' | 'Team Lead' | 'Agent' | 'HR' | 'IT' | 'Utility';

export interface User {
    id: number;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    email: string;
    role: UserRole;
    permissions?: string[];
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    inactivity_timeout?: number | null; // null = disabled (no auto-logout)
    hired_date?: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}

// ─── Coaching Module Types ──────────────────────────────────────

export type CoachingPurpose =
    | 'performance_behavior_issue'
    | 'regular_checkin_progress_review'
    | 'reinforce_positive_behavior_growth'
    | 'recognition_appreciation';

export type AckStatus = 'Pending' | 'Acknowledged' | 'Disputed';

export type ComplianceStatus = 'Awaiting_Agent_Ack' | 'For_Review' | 'Verified' | 'Rejected';

export type SeverityFlag = 'Normal' | 'Critical';

export type CoachingStatusLabel =
    | 'Coaching Done'
    | 'Needs Coaching'
    | 'Badly Needs Coaching'
    | 'Please Coach ASAP'
    | 'No Record';

export interface CoachingSession {
    id: number;
    agent_id: number;
    team_lead_id: number;
    session_date: string;
    // Agent Profile
    profile_new_hire: boolean;
    profile_tenured: boolean;
    profile_returning: boolean;
    profile_previously_coached_same_issue: boolean;
    // Purpose
    purpose: CoachingPurpose;
    purpose_label?: string;
    // Focus Areas
    focus_attendance_tardiness: boolean;
    focus_productivity: boolean;
    focus_compliance: boolean;
    focus_callouts: boolean;
    focus_recognition_milestones: boolean;
    focus_growth_development: boolean;
    focus_other: boolean;
    focus_other_notes: string | null;
    // Narrative
    performance_description: string;
    // Root Causes
    root_cause_lack_of_skills: boolean;
    root_cause_lack_of_clarity: boolean;
    root_cause_personal_issues: boolean;
    root_cause_motivation_engagement: boolean;
    root_cause_health_fatigue: boolean;
    root_cause_workload_process: boolean;
    root_cause_peer_conflict: boolean;
    root_cause_others: boolean;
    root_cause_others_notes: string | null;
    // More Narrative
    agent_strengths_wins: string | null;
    smart_action_plan: string;
    follow_up_date: string | null;
    // Acknowledgement & Compliance
    ack_status: AckStatus;
    ack_timestamp: string | null;
    ack_comment: string | null;
    compliance_status: ComplianceStatus;
    compliance_reviewer_id: number | null;
    compliance_review_timestamp: string | null;
    compliance_notes: string | null;
    // Other
    severity_flag: SeverityFlag;
    attachment_url: string | null;
    created_at: string;
    updated_at: string;
    // Relationships (loaded via with())
    agent?: User;
    team_lead?: User;
    compliance_reviewer?: User;
}

export interface CoachingSummary {
    coaching_status: CoachingStatusLabel;
    status_color: string;
    last_coached_date: string | null;
    previous_coached_date: string | null;
    older_coached_date: string | null;
    pending_acknowledgements: number;
    total_sessions: number;
}

export interface CoachingStatusColors {
    [key: string]: string;
}

export interface CoachingPurposeLabels {
    [key: string]: string;
}

export interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: PaginationLink[];
}

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface Campaign {
    id: number;
    name: string;
}

export interface CoachingStatusSetting {
    id: number;
    key: string;
    value: number;
    label: string;
    created_at: string;
    updated_at: string;
}
