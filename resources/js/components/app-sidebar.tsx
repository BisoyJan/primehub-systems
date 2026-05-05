import { useState, useCallback } from 'react';
//import { NavFooter } from '@/components/nav-footer';
import { NavGroup } from '@/components/nav-group';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as processorIndex } from '@/routes/processorspecs'
import { index as pcIndex } from '@/routes/pcspecs'
import { index as stationIndex } from '@/routes/stations'

import { index as medicationRequestsIndex } from '@/routes/medication-requests'
import { index as attendanceToolsIndex } from '@/routes/attendance-tools'
import { dashboard as coachingDashboard } from '@/routes/coaching'
import { index as coachingSessionsIndex } from '@/routes/coaching/sessions'
import { index as breakTimerIndex, dashboard as breakDashboard, reports as breakReports } from '@/routes/break-timer'
import { index as breakPoliciesIndex } from '@/routes/break-timer/policies'
import { index as databaseBackupsIndex } from '@/routes/database-backups'
import { Link } from '@inertiajs/react';
import { ArrowUpDown, CalendarCheck, ClipboardCheck, Computer, CpuIcon, CreditCard, Database, DatabaseBackup, LayoutGrid, Microchip, User, Wrench, Clock, Award, Plane, LucideIcon, AlertCircle, Pill, Activity, Settings, Shield, FileText, Timer, BarChart3, Coffee, UserMinus } from 'lucide-react';
import AppLogo from './app-logo';
import { usePermission } from '@/hooks/useAuthorization';
import type { NavItem } from '@/types';
import { usePage } from '@inertiajs/react';
import type { SharedData } from '@/types';

// Internal navigation item type with permission
interface NavItemConfig {
    title: string;
    href: string | { url: string } | (() => string);
    icon: LucideIcon;
    permission?: string | readonly string[];
    badge?: number;
}

// Navigation configuration function that takes auth user
const getNavigationConfig = (userId: number, userRole: string, coachingPendingAck: number = 0) => {
    // Restricted roles should go to their own show page
    const restrictedRoles = ['Agent', 'IT', 'Utility'];
    const isRestrictedUser = restrictedRoles.includes(userRole);
    const attendancePointsHref = isRestrictedUser
        ? `/attendance-points/${userId}`
        : '/attendance-points';

    // Leave Credits - restricted roles go to their own page
    const leaveCreditsHref = isRestrictedUser
        ? `/form-requests/leave-requests/credits/${userId}`
        : '/form-requests/leave-requests/credits';

    return {
        main: {
            label: 'Platform',
            items: [
                {
                    title: 'Dashboard',
                    href: dashboard(),
                    icon: LayoutGrid,
                    permission: undefined, // Dashboard is always visible
                },
            ],
        },
        computer: {
            label: 'Computer Specs',
            items: [
                {
                    title: 'Processor Specs',
                    href: processorIndex.url(),
                    icon: CpuIcon,
                    permission: 'hardware.view',
                },
                {
                    title: 'PC Specs',
                    href: pcIndex.url(),
                    icon: Microchip,
                    permission: 'hardware.view',
                }
            ],
        },
        station: {
            label: 'Station Details',
            items: [
                {
                    title: 'Stations',
                    href: stationIndex.url(),
                    icon: Computer,
                    permission: 'stations.view',
                },
                {
                    title: 'PC Transfer',
                    href: '/pc-transfers',
                    icon: ArrowUpDown,
                    permission: 'pc_transfers.view',
                },
                {
                    title: 'PC Maintenance',
                    href: '/pc-maintenance',
                    icon: Wrench,
                    permission: 'pc_maintenance.view',
                },
            ],
        },
        attendance: {
            label: 'Attendance',
            items: [
                {
                    title: 'Attendance',
                    href: '/attendance',
                    icon: CalendarCheck,
                    permission: 'attendance.view',
                },
                {
                    title: 'Employee Schedules',
                    href: '/employee-schedules',
                    icon: Clock,
                    permission: 'schedules.view',
                },
                {
                    title: 'Biometric Records',
                    href: '/biometric-records',
                    icon: Database,
                    permission: 'biometric.view',
                },
                {
                    title: 'Attendance Points',
                    href: attendancePointsHref,
                    icon: Award,
                    permission: 'attendance.view',
                },
                {
                    title: 'Attendance Tools',
                    href: attendanceToolsIndex.url(),
                    icon: Settings,
                    permission: 'biometric.view',
                },
            ],
        },
        coaching: {
            label: 'Coaching',
            items: [
                {
                    title: 'Dashboard',
                    href: coachingDashboard.url(),
                    icon: ClipboardCheck,
                    permission: ['coaching.view_own', 'coaching.view_team', 'coaching.view_all'],
                    badge: coachingPendingAck,
                },
                {
                    title: 'Sessions',
                    href: coachingSessionsIndex.url(),
                    icon: FileText,
                    permission: ['coaching.view_own', 'coaching.view_team', 'coaching.view_all'],
                },
                {
                    title: 'Exclusions',
                    href: '/coaching/exclusions',
                    icon: UserMinus,
                    permission: 'coaching.manage_exclusions',
                },
            ],
        },
        requests: {
            label: 'Request Forms',
            items: [
                {
                    title: 'IT Concerns',
                    href: '/form-requests/it-concerns',
                    icon: AlertCircle,
                    permission: 'it_concerns.create',
                },
                {
                    title: 'Leave Requests',
                    href: '/form-requests/leave-requests',
                    icon: Plane,
                    permission: 'leave.view',
                },
                {
                    title: 'Leave Credits',
                    href: leaveCreditsHref,
                    icon: CreditCard,
                    permission: 'leave_credits.view_own',
                },
                {
                    title: 'Medication Requests',
                    href: medicationRequestsIndex.url(),
                    icon: Pill,
                    permission: 'medication_requests.view',
                },
                {
                    title: 'Retention Policies',
                    href: '/form-requests/retention-policies',
                    icon: Shield,
                    permission: 'form_requests.retention',
                },
            ],
        },
        breakTimer: {
            label: 'Break Timer',
            items: [
                {
                    title: 'Timer',
                    href: breakTimerIndex.url(),
                    icon: Timer,
                    permission: 'break_timer.view',
                },
                {
                    title: 'Dashboard',
                    href: breakDashboard.url(),
                    icon: Coffee,
                    permission: 'break_timer.dashboard',
                },
                {
                    title: 'Reports',
                    href: breakReports.url(),
                    icon: BarChart3,
                    permission: 'break_timer.reports',
                },
                {
                    title: 'Policies',
                    href: breakPoliciesIndex.url(),
                    icon: Settings,
                    permission: 'break_timer.manage_policy',
                },
            ],
        },
        account: {
            label: 'Account Management',
            items: [
                {
                    title: 'Accounts',
                    href: '/accounts',
                    icon: User,
                    permission: 'accounts.view',
                },
            ],
        },
        systemAdmin: {
            label: 'System',
            items: [
                {
                    title: 'Activity Logs',
                    href: '/activity-logs',
                    icon: Activity,
                    permission: 'activity_logs.view',
                },
                {
                    title: 'Database Backups',
                    href: databaseBackupsIndex.url(),
                    icon: DatabaseBackup,
                    permission: 'database_backups.view',
                },
            ],
        },
    } as const;
};

// const footerNavItems: NavItem[] = [
//     {
//         title: 'Repository',
//         href: 'https://github.com/laravel/react-starter-kit',
//         icon: Folder,
//     },
//     {
//         title: 'Documentation',
//         href: 'https://laravel.com/docs/starter-kits#react',
//         icon: BookOpen,
//     },
// ];

// Maximum number of groups that can be open at the same time
const MAX_OPEN_GROUPS = 1;

export function AppSidebar() {
    const { can, canAny } = usePermission();
    const { auth, coachingPendingAck } = usePage<SharedData>().props;
    const { state } = useSidebar();

    // Track which groups are currently open (max 1)
    // When sidebar is collapsed, keep all groups open to show icons
    const [openGroups, setOpenGroups] = useState<string[]>([]);

    // Handle click toggle on a group
    const handleGroupToggle = useCallback((groupId: string) => {
        setOpenGroups((prev) => {
            if (prev.includes(groupId)) {
                // Close the group
                return prev.filter((id) => id !== groupId);
            }
            // Open the group, remove oldest if we exceed max
            const newGroups = [...prev, groupId];
            if (newGroups.length > MAX_OPEN_GROUPS) {
                return newGroups.slice(-MAX_OPEN_GROUPS);
            }
            return newGroups;
        });
    }, []);

    // Get navigation config based on current user
    const navigationConfig = getNavigationConfig(auth.user.id, auth.user.role, (coachingPendingAck as number) ?? 0);

    // Filter navigation items based on permissions
    const filterItemsByPermission = (items: readonly NavItemConfig[]): NavItem[] => {
        return items
            .filter(item => {
                // If no permission specified, show the item (e.g., Dashboard)
                if (!item.permission) return true;
                // Check if user has any of the required permissions (array) or a single permission
                if (typeof item.permission === 'string') {
                    return can(item.permission);
                }
                return canAny([...item.permission]);
            })
            .map(item => {
                const href = typeof item.href === 'string'
                    ? item.href
                    : typeof item.href === 'function'
                        ? item.href()
                        : item.href.url;

                return {
                    title: item.title,
                    href,
                    icon: item.icon,
                    badge: item.badge,
                };
            });
    };

    const filteredNavigation = {
        main: {
            label: navigationConfig.main.label,
            items: filterItemsByPermission(navigationConfig.main.items),
        },
        computer: {
            label: navigationConfig.computer.label,
            items: filterItemsByPermission(navigationConfig.computer.items),
        },
        station: {
            label: navigationConfig.station.label,
            items: filterItemsByPermission(navigationConfig.station.items),
        },
        attendance: {
            label: navigationConfig.attendance.label,
            items: filterItemsByPermission(navigationConfig.attendance.items),
        },
        coaching: {
            label: navigationConfig.coaching.label,
            items: filterItemsByPermission(navigationConfig.coaching.items),
        },
        requests: {
            label: navigationConfig.requests.label,
            items: filterItemsByPermission(navigationConfig.requests.items),
        },
        breakTimer: {
            label: navigationConfig.breakTimer.label,
            items: filterItemsByPermission(navigationConfig.breakTimer.items),
        },
        account: {
            label: navigationConfig.account.label,
            items: filterItemsByPermission(navigationConfig.account.items),
        },
        systemAdmin: {
            label: navigationConfig.systemAdmin.label,
            items: filterItemsByPermission(navigationConfig.systemAdmin.items),
        },
    };

    // When sidebar is collapsed, all groups should be open to show icons
    const isCollapsed = state === 'collapsed';

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader className="shrink-0">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch="mount">
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="overflow-y-auto! min-h-0">
                <NavGroup
                    groupId="main"
                    label={filteredNavigation.main.label}
                    items={filteredNavigation.main.items}
                    isOpen={isCollapsed || openGroups.includes('main')}
                    onToggle={handleGroupToggle}
                />
                <NavGroup
                    groupId="computer"
                    label={filteredNavigation.computer.label}
                    items={filteredNavigation.computer.items}
                    isOpen={isCollapsed || openGroups.includes('computer')}
                    onToggle={handleGroupToggle}
                />
                <NavGroup
                    groupId="station"
                    label={filteredNavigation.station.label}
                    items={filteredNavigation.station.items}
                    isOpen={isCollapsed || openGroups.includes('station')}
                    onToggle={handleGroupToggle}
                />
                <NavGroup
                    groupId="attendance"
                    label={filteredNavigation.attendance.label}
                    items={filteredNavigation.attendance.items}
                    isOpen={isCollapsed || openGroups.includes('attendance')}
                    onToggle={handleGroupToggle}
                />
                <NavGroup
                    groupId="coaching"
                    label={filteredNavigation.coaching.label}
                    items={filteredNavigation.coaching.items}
                    isOpen={isCollapsed || openGroups.includes('coaching')}
                    onToggle={handleGroupToggle}
                />
                <NavGroup
                    groupId="requests"
                    label={filteredNavigation.requests.label}
                    items={filteredNavigation.requests.items}
                    isOpen={isCollapsed || openGroups.includes('requests')}
                    onToggle={handleGroupToggle}
                />
                <NavGroup
                    groupId="breakTimer"
                    label={filteredNavigation.breakTimer.label}
                    items={filteredNavigation.breakTimer.items}
                    isOpen={isCollapsed || openGroups.includes('breakTimer')}
                    onToggle={handleGroupToggle}
                />
                <NavGroup
                    groupId="account"
                    label={filteredNavigation.account.label}
                    items={filteredNavigation.account.items}
                    isOpen={isCollapsed || openGroups.includes('account')}
                    onToggle={handleGroupToggle}
                />
                <NavGroup
                    groupId="systemAdmin"
                    label={filteredNavigation.systemAdmin.label}
                    items={filteredNavigation.systemAdmin.items}
                    isOpen={isCollapsed || openGroups.includes('systemAdmin')}
                    onToggle={handleGroupToggle}
                />
            </SidebarContent>
            <SidebarFooter className="shrink-0">
                {/* <NavFooter items={footerNavItems} className="mt-auto" /> */}
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
