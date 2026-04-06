import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import { type BreadcrumbItem } from '@/types';
import { type ReactNode } from 'react';
import { Toaster } from "@/components/ui/sonner";
import { CommandPalette } from "@/components/command-palette";
import { ErrorBoundary } from "@/components/ErrorBoundary";

interface AppLayoutProps {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
}

export default ({ children, breadcrumbs, ...props }: AppLayoutProps) => (
    <AppLayoutTemplate breadcrumbs={breadcrumbs} {...props}>
        <ErrorBoundary>
            {children}
        </ErrorBoundary>
        <Toaster position="top-center" richColors duration={4000} /> {/* #NOTE For displaying toast notifications */}
        <CommandPalette />
    </AppLayoutTemplate>
);
