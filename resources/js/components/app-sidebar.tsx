import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavComputer } from '@/components/nav-computer-group';
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
import { index as motherboardsIndex } from '@/routes/motherboards'
import { index as stocksIndex } from '@/routes/stocks'
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import { BookOpen, CpuIcon, Folder, HardDrive, LayoutGrid, MemoryStick, Microchip } from 'lucide-react';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

const computerNavItems: NavItem[] = [
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
        title: 'Motherboards',
        href: motherboardsIndex.url(),
        icon: Microchip
    },
    {
        title: 'Stocks',
        href: stocksIndex.url(),
        icon: Folder
    }
]

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: Folder,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
                <NavComputer items={computerNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
