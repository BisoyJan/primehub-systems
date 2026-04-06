import { format, parseISO } from 'date-fns';

// ─── Shared Interfaces ──────────────────────────────────────────────

export interface PendingRegularizationCredits {
    year: number;
    credits: number;
    months_accrued: number;
    regularization_date: string | null;
    is_pending: boolean;
}

export interface CreditsSummary {
    year: number;
    is_eligible: boolean;
    eligibility_date: string | null;
    monthly_rate: number;
    total_earned: number;
    total_used: number;
    balance: number;
    pending_credits: number;
    pending_regularization_credits?: PendingRegularizationCredits;
}

export interface AttendanceViolation {
    id: number;
    shift_date: string;
    point_type: string;
    points: number;
    violation_details: string;
    expires_at: string;
}

export interface ExistingLeaveRequest {
    id: number;
    leave_type: string;
    start_date: string;
    end_date: string;
    status: string;
}

export interface CampaignConflict {
    id: number;
    user_name: string;
    leave_type: string;
    start_date: string;
    end_date: string;
    status: string;
    created_at: string;
    overlapping_dates: string[];
}

export interface DateSuggestion {
    start_date: string;
    end_date: string;
    conflicts: number;
    label: string;
}

// ─── Pure Date Helpers ──────────────────────────────────────────────

/** Check if a date string falls on a weekend (Saturday or Sunday) */
export function isWeekend(dateString: string): boolean {
    if (!dateString) return false;
    const date = new Date(dateString);
    const day = date.getDay();
    return day === 0 || day === 6;
}

/** Get the full day name for a date string (e.g. "Saturday") */
export function getDayName(dateString: string): string {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { weekday: 'long' });
}

/** Sick Leave: minimum start date (3 weeks ago) */
export function getSlMinDate(): string {
    const date = new Date();
    date.setDate(date.getDate() - 21);
    return format(date, 'yyyy-MM-dd');
}

/** Sick Leave: maximum end date (1 month ahead) */
export function getSlMaxEndDate(): string {
    const date = new Date();
    date.setMonth(date.getMonth() + 1);
    return format(date, 'yyyy-MM-dd');
}

/** Solo Parent Leave: minimum start date (2 weeks ago) */
export function getSplMinDate(): string {
    const date = new Date();
    date.setDate(date.getDate() - 14);
    return format(date, 'yyyy-MM-dd');
}

/** Solo Parent Leave: maximum end date (1 month ahead) */
export function getSplMaxEndDate(): string {
    const date = new Date();
    date.setMonth(date.getMonth() + 1);
    return format(date, 'yyyy-MM-dd');
}

/** Maternity Leave: maximum end date (1 year ahead) */
export function getMlMaxEndDate(): string {
    const date = new Date();
    date.setFullYear(date.getFullYear() + 1);
    return format(date, 'yyyy-MM-dd');
}

// ─── Credit Calculation Helpers ─────────────────────────────────────

/** Check if user will be eligible for leave credits by a given start date */
export function willBeEligibleByStartDate(
    startDate: string,
    isEligible: boolean,
    eligibilityDate: string | null,
): boolean {
    if (isEligible) return true;
    if (!startDate || !eligibilityDate) return false;
    const start = parseISO(startDate);
    const eligibility = parseISO(eligibilityDate);
    return start >= eligibility;
}

/** Calculate projected leave credit balance for a future date */
export function getProjectedBalance(
    startDate: string,
    eligibilityDate: string | null,
    monthlyRate: number,
    pendingRegularization?: PendingRegularizationCredits,
): number {
    if (!startDate || !eligibilityDate) return 0;

    const start = parseISO(startDate);
    const eligibility = parseISO(eligibilityDate);

    if (start < eligibility) return 0;

    let projectedBalance = 0;
    if (pendingRegularization?.is_pending) {
        projectedBalance += pendingRegularization.credits;
    }

    const eligMonth = eligibility.getMonth();
    const eligYear = eligibility.getFullYear();
    const leaveMonth = start.getMonth();
    const leaveYear = start.getFullYear();

    const monthsOfCredits = (leaveYear - eligYear) * 12 + (leaveMonth - eligMonth);
    projectedBalance += Math.max(0, monthsOfCredits) * monthlyRate;

    return projectedBalance;
}

/** Calculate future credits that will accrue between now and the leave start date */
export function calculateFutureCredits(
    startDate: string,
    creditsSummary: CreditsSummary,
): number {
    if (!startDate) return 0;

    if (!creditsSummary.is_eligible && creditsSummary.eligibility_date) {
        const leaveStart = parseISO(startDate);
        const eligibilityDate = parseISO(creditsSummary.eligibility_date);
        if (leaveStart < eligibilityDate) return 0;
    } else if (!creditsSummary.is_eligible) {
        return 0;
    }

    const today = new Date();
    const leaveStart = parseISO(startDate);

    const currentMonth = today.getMonth();
    const currentYear = today.getFullYear();
    const leaveMonth = leaveStart.getMonth();
    const leaveYear = leaveStart.getFullYear();

    let monthsToAccrue = 0;
    if (leaveYear > currentYear || (leaveYear === currentYear && leaveMonth > currentMonth)) {
        monthsToAccrue = (leaveYear - currentYear) * 12 + (leaveMonth - currentMonth);
    }

    return monthsToAccrue * creditsSummary.monthly_rate;
}

/** Count working days (weekdays only) between two date strings */
export function countWorkingDays(startDate: string, endDate: string): number {
    const start = parseISO(startDate);
    const end = parseISO(endDate);
    let workingDays = 0;
    const currentDate = new Date(start);

    while (currentDate <= end) {
        const dayOfWeek = currentDate.getDay();
        if (dayOfWeek >= 1 && dayOfWeek <= 5) {
            workingDays++;
        }
        currentDate.setDate(currentDate.getDate() + 1);
    }

    return workingDays;
}
