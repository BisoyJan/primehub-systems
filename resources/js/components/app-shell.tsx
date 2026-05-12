import { SidebarProvider } from '@/components/ui/sidebar';
import { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

interface AppShellProps {
    children: React.ReactNode;
    variant?: 'header' | 'sidebar';
}

/**
 * Reads sidebar_state cookie directly from document.cookie.
 * This avoids stale values from Inertia's prefetch cache, which can cache
 * the sidebarOpen prop from when the link was first prefetched (before the
 * user toggled the sidebar).
 */
function getClientSidebarState(): boolean | null {
    if (typeof document === 'undefined') return null;
    const match = document.cookie.split(';').find((c) => c.trim().startsWith('sidebar_state='));
    if (!match) return null;
    return match.split('=')[1]?.trim() !== 'false';
}

export function AppShell({ children, variant = 'header' }: AppShellProps) {
    const serverIsOpen = usePage<SharedData>().props.sidebarOpen;
    // Prefer the live cookie value over the server prop so that Inertia's
    // prefetch cache (which may hold a stale sidebarOpen value) does not
    // reset the sidebar to the wrong state on every navigation.
    const clientState = getClientSidebarState();
    const isOpen = clientState !== null ? clientState : serverIsOpen;

    if (variant === 'header') {
        return (
            <div className="flex min-h-screen w-full flex-col">{children}</div>
        );
    }

    return <SidebarProvider defaultOpen={isOpen}>{children}</SidebarProvider>;
}
