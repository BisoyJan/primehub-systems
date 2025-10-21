import React from 'react';
import { Loader2 } from 'lucide-react';

interface LoadingOverlayProps {
    isLoading?: boolean;
    message?: string;
    fullScreen?: boolean;
}

/**
 * Loading overlay component with spinner
 * Can be used for page-level or component-level loading states
 *
 * @example
 * ```tsx
 * <LoadingOverlay isLoading={isDeleting} message="Deleting..." />
 * ```
 */
export function LoadingOverlay({
    isLoading = false,
    message = "Loading...",
    fullScreen = false
}: LoadingOverlayProps) {
    if (!isLoading) return null;

    const containerClass = fullScreen
        ? "fixed inset-0 z-50"
        : "absolute inset-0 z-10";

    return (
        <div className={`${containerClass} bg-background/80 backdrop-blur-sm flex items-center justify-center`}>
            <div className="flex flex-col items-center gap-3">
                <Loader2 className="h-8 w-8 animate-spin text-primary" />
                <p className="text-sm text-muted-foreground">{message}</p>
            </div>
        </div>
    );
}

/**
 * Inline loading spinner (smaller, for buttons or inline use)
 */
export function LoadingSpinner({ className = "" }: { className?: string }) {
    return <Loader2 className={`h-4 w-4 animate-spin ${className}`} />;
}
