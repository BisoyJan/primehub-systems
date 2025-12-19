import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';

interface NavGroupProps {
    label: string;
    items: readonly NavItem[];
    groupId: string;
    isOpen: boolean;
    onToggle: (groupId: string) => void;
}

export function NavGroup({ label, items = [], groupId, isOpen, onToggle }: NavGroupProps) {
    const page = usePage();
    const { state } = useSidebar();

    if (items.length === 0) {
        return null;
    }

    // Check if any item in this group is active
    const hasActiveItem = items.some((item) => {
        const href = typeof item.href === 'string' ? item.href : item.href.url;
        return page.url.startsWith(href);
    });

    const isExpanded = isOpen || hasActiveItem;
    const isCollapsed = state === 'collapsed';

    // When sidebar is collapsed, show items directly without collapsible wrapper
    if (isCollapsed) {
        return (
            <SidebarGroup className="px-2 py-0">
                <SidebarMenu>
                    {items.map((item) => (
                        <SidebarMenuItem key={item.title}>
                            <SidebarMenuButton
                                asChild
                                isActive={page.url.startsWith(
                                    typeof item.href === 'string'
                                        ? item.href
                                        : item.href.url,
                                )}
                                tooltip={{ children: item.title }}
                            >
                                <Link href={item.href} prefetch="mount">
                                    {item.icon && <item.icon />}
                                    <span>{item.title}</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    ))}
                </SidebarMenu>
            </SidebarGroup>
        );
    }

    return (
        <Collapsible
            open={isExpanded}
            onOpenChange={() => onToggle(groupId)}
            className="group/collapsible"
        >
            <SidebarGroup className="px-2 py-0">
                <CollapsibleTrigger asChild>
                    <SidebarGroupLabel
                        className="cursor-pointer select-none hover:bg-sidebar-accent hover:text-sidebar-accent-foreground rounded-md transition-colors flex items-center justify-between w-full pr-2"
                    >
                        <span>{label}</span>
                        <ChevronRight
                            className={cn(
                                "h-4 w-4 shrink-0 transition-transform duration-500 ease-in-out",
                                isExpanded && "rotate-90"
                            )}
                        />
                    </SidebarGroupLabel>
                </CollapsibleTrigger>
                <CollapsibleContent className="overflow-hidden transition-all duration-500 ease-in-out data-[state=closed]:animate-collapsible-up data-[state=open]:animate-collapsible-down">
                    <SidebarMenu>
                        {items.map((item) => (
                            <SidebarMenuItem key={item.title}>
                                <SidebarMenuButton
                                    asChild
                                    isActive={page.url.startsWith(
                                        typeof item.href === 'string'
                                            ? item.href
                                            : item.href.url,
                                    )}
                                    tooltip={{ children: item.title }}
                                >
                                    <Link href={item.href} prefetch="mount">
                                        {item.icon && <item.icon />}
                                        <span>{item.title}</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        ))}
                    </SidebarMenu>
                </CollapsibleContent>
            </SidebarGroup>
        </Collapsible>
    );
}
