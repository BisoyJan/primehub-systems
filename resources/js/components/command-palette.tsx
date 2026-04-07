import { useEffect, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
} from '@/components/ui/command';
import { usePermission } from '@/hooks/useAuthorization';
import type { SharedData } from '@/types';
import { LucideIcon, LayoutGrid, CpuIcon, Microchip, Computer, ArrowUpDown, Wrench, CalendarCheck, Clock, Database, Award, Settings, ClipboardCheck, FileText, AlertCircle, Plane, CreditCard, Pill, Shield, Timer, Coffee, BarChart3, User, Activity, DatabaseBackup } from 'lucide-react';
import { dashboard } from '@/routes';
import { index as processorIndex } from '@/routes/processorspecs';
import { index as pcIndex } from '@/routes/pcspecs';
import { index as stationIndex } from '@/routes/stations';
import { index as medicationRequestsIndex } from '@/routes/medication-requests';
import { index as attendanceToolsIndex } from '@/routes/attendance-tools';
import { dashboard as coachingDashboard } from '@/routes/coaching';
import { index as coachingSessionsIndex } from '@/routes/coaching/sessions';
import { index as breakTimerIndex, dashboard as breakDashboard, reports as breakReports } from '@/routes/break-timer';
import { index as breakPoliciesIndex } from '@/routes/break-timer/policies';
import { index as databaseBackupsIndex } from '@/routes/database-backups';

interface NavItemConfig {
    title: string;
    href: string;
    icon: LucideIcon;
    permission?: string | readonly string[];
}

interface NavGroupConfig {
    label: string;
    items: NavItemConfig[];
}

function getNavigationGroups(userId: number, userRole: string): NavGroupConfig[] {
    const restrictedRoles = ['Agent', 'IT', 'Utility'];
    const isRestrictedUser = restrictedRoles.includes(userRole);
    const attendancePointsHref = isRestrictedUser ? `/attendance-points/${userId}` : '/attendance-points';
    const leaveCreditsHref = isRestrictedUser ? `/form-requests/leave-requests/credits/${userId}` : '/form-requests/leave-requests/credits';

    const resolveHref = (href: string | { url: string } | (() => string)): string => {
        if (typeof href === 'string') return href;
        if (typeof href === 'function') return href();
        return href.url;
    };

    return [
        {
            label: 'Platform',
            items: [
                { title: 'Dashboard', href: resolveHref(dashboard()), icon: LayoutGrid },
            ],
        },
        {
            label: 'Computer Specs',
            items: [
                { title: 'Processor Specs', href: processorIndex.url(), icon: CpuIcon, permission: 'hardware.view' },
                { title: 'PC Specs', href: pcIndex.url(), icon: Microchip, permission: 'hardware.view' },
            ],
        },
        {
            label: 'Station Details',
            items: [
                { title: 'Stations', href: stationIndex.url(), icon: Computer, permission: 'stations.view' },
                { title: 'PC Transfer', href: '/pc-transfers', icon: ArrowUpDown, permission: 'pc_transfers.view' },
                { title: 'PC Maintenance', href: '/pc-maintenance', icon: Wrench, permission: 'pc_maintenance.view' },
            ],
        },
        {
            label: 'Attendance',
            items: [
                { title: 'Attendance', href: '/attendance', icon: CalendarCheck, permission: 'attendance.view' },
                { title: 'Employee Schedules', href: '/employee-schedules', icon: Clock, permission: 'schedules.view' },
                { title: 'Biometric Records', href: '/biometric-records', icon: Database, permission: 'biometric.view' },
                { title: 'Attendance Points', href: attendancePointsHref, icon: Award, permission: 'attendance.view' },
                { title: 'Attendance Tools', href: attendanceToolsIndex.url(), icon: Settings, permission: 'biometric.view' },
            ],
        },
        {
            label: 'Coaching',
            items: [
                { title: 'Coaching Dashboard', href: coachingDashboard.url(), icon: ClipboardCheck, permission: ['coaching.view_own', 'coaching.view_team', 'coaching.view_all'] as readonly string[] },
                { title: 'Coaching Sessions', href: coachingSessionsIndex.url(), icon: FileText, permission: ['coaching.view_own', 'coaching.view_team', 'coaching.view_all'] as readonly string[] },
            ],
        },
        {
            label: 'Request Forms',
            items: [
                { title: 'IT Concerns', href: '/form-requests/it-concerns', icon: AlertCircle, permission: 'it_concerns.create' },
                { title: 'Leave Requests', href: '/form-requests/leave-requests', icon: Plane, permission: 'leave.view' },
                { title: 'Leave Credits', href: leaveCreditsHref, icon: CreditCard, permission: 'leave_credits.view_own' },
                { title: 'Medication Requests', href: medicationRequestsIndex.url(), icon: Pill, permission: 'medication_requests.view' },
                { title: 'Retention Policies', href: '/form-requests/retention-policies', icon: Shield, permission: 'form_requests.retention' },
            ],
        },
        {
            label: 'Break Timer',
            items: [
                { title: 'Timer', href: breakTimerIndex.url(), icon: Timer, permission: 'break_timer.view' },
                { title: 'Break Dashboard', href: breakDashboard.url(), icon: Coffee, permission: 'break_timer.dashboard' },
                { title: 'Break Reports', href: breakReports.url(), icon: BarChart3, permission: 'break_timer.reports' },
                { title: 'Break Policies', href: breakPoliciesIndex.url(), icon: Settings, permission: 'break_timer.manage_policy' },
            ],
        },
        {
            label: 'Account Management',
            items: [
                { title: 'Accounts', href: '/accounts', icon: User, permission: 'accounts.view' },
                { title: 'Activity Logs', href: '/activity-logs', icon: Activity, permission: 'activity_logs.view' },
            ],
        },
        {
            label: 'System',
            items: [
                { title: 'Database Backups', href: databaseBackupsIndex.url(), icon: DatabaseBackup, permission: 'database_backups.view' },
            ],
        },
    ];
}

export function CommandPalette() {
    const [open, setOpen] = useState(false);
    const { can, canAny } = usePermission();
    const { auth } = usePage<SharedData>().props;

    useEffect(() => {
        const handleKeyDown = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                setOpen((prev) => !prev);
            }
        };

        document.addEventListener('keydown', handleKeyDown);
        return () => document.removeEventListener('keydown', handleKeyDown);
    }, []);

    const groups = getNavigationGroups(auth.user.id, auth.user.role);

    const filteredGroups = groups
        .map((group) => ({
            ...group,
            items: group.items.filter((item) => {
                if (!item.permission) return true;
                if (typeof item.permission === 'string') return can(item.permission);
                return canAny([...item.permission]);
            }),
        }))
        .filter((group) => group.items.length > 0);

    const handleSelect = (href: string) => {
        setOpen(false);
        router.visit(href);
    };

    return (
        <CommandDialog open={open} onOpenChange={setOpen}>
            <CommandInput placeholder="Search pages..." />
            <CommandList>
                <CommandEmpty>No results found.</CommandEmpty>
                {filteredGroups.map((group, index) => (
                    <div key={group.label}>
                        {index > 0 && <CommandSeparator />}
                        <CommandGroup heading={group.label}>
                            {group.items.map((item) => (
                                <CommandItem
                                    key={item.href}
                                    value={`${group.label} ${item.title}`}
                                    onSelect={() => handleSelect(item.href)}
                                    className="cursor-pointer"
                                >
                                    <item.icon className="mr-2 h-4 w-4" />
                                    <span>{item.title}</span>
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    </div>
                ))}
            </CommandList>
        </CommandDialog>
    );
}
