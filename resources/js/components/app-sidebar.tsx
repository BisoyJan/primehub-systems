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
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as ramIndex } from '@/routes/ramspecs'
import { index as diskIndex } from '@/routes/diskspecs'
import { index as processorIndex } from '@/routes/processorspecs'
import { index as pcIndex } from '@/routes/pcspecs'
import { index as stocksIndex } from '@/routes/stocks'
import { index as stationIndex } from '@/routes/stations'
import { index as monitorIndex } from '@/routes/monitorspecs'
import { index as medicationRequestsIndex } from '@/routes/medication-requests'
import { Link } from '@inertiajs/react';
import { ArrowUpDown, CalendarCheck, Computer, CpuIcon, Database, Folder, HardDrive, LayoutGrid, MemoryStick, Microchip, Monitor, User, Wrench, Clock, RefreshCw, AlertTriangle, Download, Shield, FileText, Award, Plane, LucideIcon, AlertCircle, Pill, Activity } from 'lucide-react';
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
    permission?: string;
}

// Navigation configuration function that takes auth user
const getNavigationConfig = (userId: number, userRole: string) => {
    // Restricted roles should go to their own show page
    const restrictedRoles = ['Agent', 'IT', 'Utility'];
    const isRestrictedUser = restrictedRoles.includes(userRole);
    const attendancePointsHref = isRestrictedUser
        ? `/attendance-points/${userId}`
        : '/attendance-points';

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
                    title: 'Ram Specs',
                    href: ramIndex.url(),
                    icon: MemoryStick,
                    permission: 'hardware.view',
                },
                {
                    title: 'Disk Specs',
                    href: diskIndex.url(),
                    icon: HardDrive,
                    permission: 'hardware.view',
                },
                {
                    title: 'Processor Specs',
                    href: processorIndex.url(),
                    icon: CpuIcon,
                    permission: 'hardware.view',
                },
                {
                    title: 'Monitor Specs',
                    href: monitorIndex.url(),
                    icon: Monitor,
                    permission: 'hardware.view',
                },
                {
                    title: 'PC Specs',
                    href: pcIndex.url(),
                    icon: Microchip,
                    permission: 'hardware.view',
                },
                {
                    title: 'Stocks',
                    href: stocksIndex.url(),
                    icon: Folder,
                    permission: 'stock.view',
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
                    title: 'Recent Uploads',
                    href: '/attendance-uploads',
                    icon: FileText,
                    permission: 'biometric.view',
                },
                {
                    title: 'Attendance Points',
                    href: attendancePointsHref,
                    icon: Award,
                    permission: 'attendance.view',
                },
                {
                    title: 'Reprocess Attendance',
                    href: '/biometric-reprocessing',
                    icon: RefreshCw,
                    permission: 'biometric.reprocess',
                },
                {
                    title: 'Anomaly Detection',
                    href: '/biometric-anomalies',
                    icon: AlertTriangle,
                    permission: 'biometric.anomalies',
                },
                {
                    title: 'Export Records',
                    href: '/biometric-export',
                    icon: Download,
                    permission: 'biometric.export',
                },
                {
                    title: 'Retention Policies',
                    href: '/biometric-retention-policies',
                    icon: Shield,
                    permission: 'biometric.retention',
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
                    permission: 'it_concerns.view',
                },
                {
                    title: 'Leave Requests',
                    href: '/form-requests/leave-requests',
                    icon: Plane,
                    permission: 'leave.view',
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
        account: {
            label: 'Account Management',
            items: [
                {
                    title: 'Accounts',
                    href: '/accounts',
                    icon: User,
                    permission: 'accounts.view',
                },
                {
                    title: 'Activity Logs',
                    href: '/activity-logs',
                    icon: Activity,
                    permission: 'activity_logs.view',
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

export function AppSidebar() {
    const { can } = usePermission();
    const { auth } = usePage<SharedData>().props;

    // Get navigation config based on current user
    const navigationConfig = getNavigationConfig(auth.user.id, auth.user.role);

    // Filter navigation items based on permissions
    const filterItemsByPermission = (items: readonly NavItemConfig[]): NavItem[] => {
        return items
            .filter(item => {
                // If no permission specified, show the item (e.g., Dashboard)
                if (!item.permission) return true;
                // Check if user has the required permission
                return can(item.permission);
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
        requests: {
            label: navigationConfig.requests.label,
            items: filterItemsByPermission(navigationConfig.requests.items),
        },
        account: {
            label: navigationConfig.account.label,
            items: filterItemsByPermission(navigationConfig.account.items),
        },
    };

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
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

            <SidebarContent>
                <NavGroup label={filteredNavigation.main.label} items={filteredNavigation.main.items} />
                <NavGroup label={filteredNavigation.computer.label} items={filteredNavigation.computer.items} />
                <NavGroup label={filteredNavigation.station.label} items={filteredNavigation.station.items} />
                <NavGroup label={filteredNavigation.attendance.label} items={filteredNavigation.attendance.items} />
                <NavGroup label={filteredNavigation.requests.label} items={filteredNavigation.requests.items} />
                <NavGroup label={filteredNavigation.account.label} items={filteredNavigation.account.items} />
            </SidebarContent>

            <SidebarFooter>
                {/* <NavFooter items={footerNavItems} className="mt-auto" /> */}
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
