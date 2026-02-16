import type { UserRole } from '@/types';

// ─── Tab Types ───────────────────────────────────────────────────────────────

export type TabType =
    | 'infrastructure'
    | 'attendance'
    | 'it-concerns'
    | 'presence-insights'
    | 'stock-overview'
    | 'personal';

export const ROLE_TABS: Record<UserRole, TabType[]> = {
    'Super Admin': ['attendance', 'presence-insights', 'infrastructure', 'it-concerns', 'stock-overview'],
    'Admin': ['attendance', 'presence-insights', 'infrastructure'],
    'HR': ['attendance', 'presence-insights'],
    'IT': ['infrastructure', 'it-concerns', 'stock-overview', 'attendance'],
    'Team Lead': ['attendance', 'presence-insights'],
    'Agent': ['personal', 'attendance', 'presence-insights'],
    'Utility': ['personal', 'attendance'],
};

export const TAB_CONFIG: Record<TabType, { label: string; iconName: string }> = {
    'infrastructure': { label: 'Infrastructure', iconName: 'Building2' },
    'attendance': { label: 'Attendance', iconName: 'Users' },
    'it-concerns': { label: 'IT Concerns', iconName: 'ClipboardList' },
    'presence-insights': { label: 'Presence Insights', iconName: 'UserCheck' },
    'stock-overview': { label: 'Stock Overview', iconName: 'Package' },
    'personal': { label: 'My Dashboard', iconName: 'User' },
};

// ─── Widget Types ────────────────────────────────────────────────────────────

export type WidgetType = 'notifications' | 'user-accounts' | 'recent-activity' | 'biometric-anomalies';

export const ROLE_WIDGETS: Record<UserRole, WidgetType[]> = {
    'Super Admin': ['notifications', 'user-accounts', 'recent-activity', 'biometric-anomalies'],
    'Admin': ['notifications', 'user-accounts', 'recent-activity', 'biometric-anomalies'],
    'HR': ['notifications', 'biometric-anomalies'],
    'IT': ['notifications'],
    'Team Lead': ['notifications'],
    'Agent': ['notifications'],
    'Utility': ['notifications'],
};

// ─── Shared Sub-Types ────────────────────────────────────────────────────────

export interface NotificationSummary {
    unread_count: number;
    recent: Array<{
        id: number;
        type: string;
        title: string;
        message: string;
        created_at: string;
    }>;
}

export interface PersonalSchedule {
    campaign: string;
    site: string;
    shift_type: string;
    time_in: string;
    time_out: string;
    work_days: string[];
    grace_period_minutes: number;
    next_shifts: string[];
}

export interface PersonalRequests {
    leaves: Array<{
        id: number;
        leave_type: string;
        start_date: string;
        end_date: string;
        days_requested: number;
        status: string;
        created_at: string;
    }>;
    it_concerns: Array<{
        id: number;
        category: string;
        description: string;
        status: string;
        priority: string;
        created_at: string;
    }>;
    medication_requests: Array<{
        id: number;
        name: string;
        medication_type: string;
        status: string;
        created_at: string;
    }>;
}

export interface PersonalAttendanceSummary {
    month: string;
    total: number;
    present: number;
    on_time: number;
    tardy: number;
    absent: number;
    ncns: number;
    half_day: number;
    on_leave: number;
    total_points: number;
    points_by_type: Record<string, number>;
    points_threshold: number;
    upcoming_expirations: Array<{
        point_type: string;
        points: number;
        expires_at: string;
    }>;
}

export interface StockSummaryItem {
    total: number;
    reserved: number;
    available: number;
    items: number;
}

export type StockSummary = Record<string, StockSummaryItem>;

export interface UserAccountStats {
    total: number;
    by_role: Record<string, number>;
    pending_approvals: number;
    recently_deactivated: number;
    resigned: number;
}

export interface ActivityLogEntry {
    id: number;
    description: string;
    event: string;
    causer_name: string;
    subject_type: string;
    subject_id: number;
    created_at: string;
}

export interface BiometricAnomalies {
    simultaneous_sites: number;
    impossible_gaps: number;
    duplicate_scans: number;
    unusual_hours: number;
    excessive_scans: number;
    total: number;
}

// ─── Phase 4: Enhanced Analytics Types ───────────────────────────────────────

export interface PointsEscalation {
    count: number;
    employees: Array<{
        user_id: number;
        user_name: string;
        user_role: string;
        total_points: number;
        remaining_before_threshold: number;
        violations_count: number;
        latest_violation: string | null;
    }>;
}

export interface NcnsTrendItem {
    month: string;
    label: string;
    ncns_count: number;
    change: 'increasing' | 'decreasing' | 'stable';
}

export interface LeaveUtilization {
    months: Array<{
        month: string;
        label: string;
        earned: number;
        used: number;
        utilization_rate: number;
    }>;
    totals: {
        total_earned: number;
        total_used: number;
        utilization_rate: number;
    };
}

export interface CampaignPresenceItem {
    campaign_id: number;
    campaign_name: string;
    total_scheduled: number;
    present: number;
    absent: number;
    on_leave: number;
    presence_rate: number;
}

export interface PointsByCampaignItem {
    campaign_id: number;
    campaign_name: string;
    total_points: number;
    violations_count: number;
    high_risk_count: number;
    employees_with_points: number;
}

export interface LeaveCredits {
    year: number;
    is_eligible: boolean;
    eligibility_date: string | null;
    monthly_rate: number;
    total_earned: number;
    total_used: number;
    balance: number;
}

// ─── Dashboard Props ─────────────────────────────────────────────────────────

export interface DashboardProps {
    // Infrastructure
    totalStations?: {
        total: number;
        bysite: Array<{ site: string; count: number }>;
    };
    noPcs?: {
        total: number;
        stations: Array<{ station: string; site: string; campaign: string }>;
    };
    vacantStations?: {
        total: number;
        bysite: Array<{ site: string; count: number }>;
        stations: Array<{ site: string; station_number: string }>;
    };

    dualMonitor?: {
        total: number;
        bysite: Array<{ site: string; count: number }>;
    };
    maintenanceDue?: {
        total: number;
        stations: Array<{ station: string; site: string; dueDate: string; daysOverdue: number }>;
    };
    unassignedPcSpecs?: Array<{
        id: number;
        pc_number: string;
        model: string;
        ram: string;
        ram_gb: number;
        ram_count: number;
        disk: string;
        disk_tb: number;
        disk_count: number;
        processor: string;
        cpu_count: number;
        issue: string | null;
    }>;

    // IT Concerns
    itConcernStats?: {
        pending: number;
        in_progress: number;
        resolved: number;
        bySite?: Array<{
            site: string;
            pending: number;
            in_progress: number;
            resolved: number;
            total: number;
        }>;
    };
    itConcernTrends?: Array<{
        month: string;
        label: string;
        total: number;
        pending: number;
        in_progress: number;
        resolved: number;
    }>;

    // Attendance
    attendanceStatistics: {
        total: number;
        on_time: number;
        time_adjustment: number;
        overtime: number;
        undertime: number;
        tardy: number;
        half_day: number;
        ncns: number;
        advised: number;
        needs_verification: number;
    };
    monthlyAttendanceData: Record<string, {
        month: string;
        total: number;
        on_time: number;
        time_adjustment: number;
        tardy: number;
        half_day: number;
        ncns: number;
        advised: number;
    }>;
    dailyAttendanceData: Record<string, Array<{
        month: string;
        day: number;
        total: number;
        on_time: number;
        time_adjustment: number;
        tardy: number;
        half_day: number;
        ncns: number;
        advised: number;
    }>>;
    campaigns?: Array<{
        id: number;
        name: string;
    }>;
    startDate: string;
    endDate: string;
    campaignId?: string;
    verificationFilter: string;
    isRestrictedRole: boolean;

    // Presence Insights
    presenceInsights?: {
        todayPresence: {
            total_scheduled: number;
            present: number;
            absent: number;
            on_leave: number;
            unaccounted: number;
        };
        leaveCalendar: Array<{
            id: number;
            user_id: number;
            user_name: string;
            user_role: string;
            avatar_url: string | null;
            campaign_name: string;
            leave_type: string;
            start_date: string;
            end_date: string;
            days_requested: number;
            reason: string;
        }>;
        attendancePoints: {
            total_active_points: number;
            total_violations: number;
            high_risk_count: number;
            high_risk_employees: Array<{
                user_id: number;
                user_name: string;
                user_role: string;
                total_points: number;
                violations_count: number;
                points: Array<{
                    id: number;
                    shift_date: string;
                    point_type: string;
                    points: number;
                    violation_details: string;
                    expires_at: string;
                }>;
            }>;
            by_type: {
                whole_day_absence: number;
                half_day_absence: number;
                tardy: number;
                undertime: number;
                undertime_more_than_hour: number;
            };
            trend: Array<{
                month: string;
                label: string;
                total_points: number;
                violations_count: number;
            }>;
        };
    };

    // Leave
    leaveCredits?: LeaveCredits;
    leaveCalendarMonth?: string;
    leaveConflicts?: {
        total: number;
        records: Array<{
            id: number;
            user_id: number;
            user_name: string;
            user_role: string;
            campaign_name: string;
            shift_date: string;
            leave_type: string;
            leave_start: string;
            leave_end: string;
            actual_time_in: string | null;
            actual_time_out: string | null;
        }>;
    };

    // ─── Phase 1: New Props ──────────────────────────────────────────────────

    /** Current user's role string from backend */
    userRole?: string;

    /** Notification summary for sidebar widget */
    notificationSummary?: NotificationSummary;

    /** Personal schedule for Agent/Utility dashboard */
    personalSchedule?: PersonalSchedule | null;

    /** Personal request summaries for Agent/Utility dashboard */
    personalRequests?: PersonalRequests;

    /** Personal attendance summary for Agent/Utility dashboard */
    personalAttendanceSummary?: PersonalAttendanceSummary;

    /** Stock levels by category */
    stockSummary?: StockSummary;

    /** User account statistics for admin widgets */
    userAccountStats?: UserAccountStats;

    /** Recent activity log entries for admin widgets */
    recentActivityLogs?: ActivityLogEntry[];

    /** Biometric anomaly summary for admin widgets */
    biometricAnomalies?: BiometricAnomalies;

    // ─── Phase 4: Enhanced Analytics Props ───────────────────────────────────

    /** Employees nearing 6-point threshold (4–5.99 points) */
    pointsEscalation?: PointsEscalation;

    /** NCNS trend for last 6 months with direction */
    ncnsTrend?: NcnsTrendItem[];

    /** Monthly leave earned vs used aggregated */
    leaveUtilization?: LeaveUtilization;

    /** Presence stats grouped by campaign */
    campaignPresence?: CampaignPresenceItem[];

    /** Attendance points grouped by campaign */
    pointsByCampaign?: PointsByCampaignItem[];
}
