import { useMemo } from 'react';
import type { BreadcrumbItem } from '@/types';

interface PageMetaOptions {
    title: string;
    breadcrumbs?: BreadcrumbItem[];
    description?: string;
}

/**
 * Custom hook to manage page metadata (title, breadcrumbs, description)
 * Provides a consistent way to handle Head component data across all pages
 *
 * @example
 * ```tsx
 * const { title, breadcrumbs } = usePageMeta({
 *   title: "Account Management",
 *   breadcrumbs: [{ title: "Accounts", href: accountsIndex().url }]
 * });
 *
 * return (
 *   <AppLayout breadcrumbs={breadcrumbs}>
 *     <Head title={title} />
 *     ...
 *   </AppLayout>
 * );
 * ```
 */
export function usePageMeta(options: PageMetaOptions) {
    const { title, breadcrumbs = [], description } = options;

    // Memoize to prevent unnecessary re-renders
    const meta = useMemo(() => ({
        title,
        breadcrumbs,
        description,
        // Generate page heading from title if not explicitly provided
        heading: title,
    }), [title, breadcrumbs, description]);

    return meta;
}
