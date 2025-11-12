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
import { Link } from '@inertiajs/react';
import { ArrowUpDown, CalendarCheck, Computer, CpuIcon, Database, Folder, HardDrive, LayoutGrid, MemoryStick, Microchip, Monitor, User, Wrench, Clock, RefreshCw, AlertTriangle, Download, Shield, FileText, Award } from 'lucide-react';
import AppLogo from './app-logo';

// Navigation configuration
const navigationConfig = {
    main: {
        label: 'Platform',
        items: [
            {
                title: 'Dashboard',
                href: dashboard(),
                icon: LayoutGrid,
            },
        ],
    },
    computer: {
        label: 'Computer Specs',
        items: [
            {
                title: 'Ram Specs',
                href: ramIndex.url(),
                icon: MemoryStick
            },
            {
                title: 'Disk Specs',
                href: diskIndex.url(),
                icon: HardDrive
            },
            {
                title: 'Processor Specs',
                href: processorIndex.url(),
                icon: CpuIcon
            },
            {
                title: 'Monitor Specs',
                href: monitorIndex.url(),
                icon: Monitor
            },
            {
                title: 'PC Specs',
                href: pcIndex.url(),
                icon: Microchip
            },
            {
                title: 'Stocks',
                href: stocksIndex.url(),
                icon: Folder
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
            },
            {
                title: 'PC Transfer',
                href: '/pc-transfers',
                icon: ArrowUpDown,
            },
            {
                title: 'PC Maintenance',
                href: '/pc-maintenance',
                icon: Wrench,
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
            },
            {
                title: 'Employee Schedules',
                href: '/employee-schedules',
                icon: Clock,
            },
            {
                title: 'Biometric Records',
                href: '/biometric-records',
                icon: Database,
            },
            {
                title: 'Recent Uploads',
                href: '/attendance-uploads',
                icon: FileText,
            },
            {
                title: 'Attendance Points',
                href: '/attendance-points',
                icon: Award,
            },
            {
                title: 'Reprocess Attendance',
                href: '/biometric-reprocessing',
                icon: RefreshCw,
            },
            {
                title: 'Anomaly Detection',
                href: '/biometric-anomalies',
                icon: AlertTriangle,
            },
            {
                title: 'Export Records',
                href: '/biometric-export',
                icon: Download,
            },
            {
                title: 'Retention Policies',
                href: '/biometric-retention-policies',
                icon: Shield,
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
            },
        ],
    },
} as const;

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
                <NavGroup label={navigationConfig.main.label} items={navigationConfig.main.items} />
                <NavGroup label={navigationConfig.computer.label} items={navigationConfig.computer.items} />
                <NavGroup label={navigationConfig.station.label} items={navigationConfig.station.items} />
                <NavGroup label={navigationConfig.attendance.label} items={navigationConfig.attendance.items} />
                <NavGroup label={navigationConfig.account.label} items={navigationConfig.account.items} />
            </SidebarContent>

            <SidebarFooter>
                {/* <NavFooter items={footerNavItems} className="mt-auto" /> */}
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
