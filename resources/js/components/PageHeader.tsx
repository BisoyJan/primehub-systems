import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { ReactNode } from 'react';

interface PageHeaderProps {
    title: string;
    description?: string;
    createLink?: string;
    createLabel?: string;
    actions?: ReactNode;
    children?: ReactNode;
}

/**
 * Reusable page header component with title, description, and action buttons
 * Provides consistent page header layout across the application
 *
 * @example
 * ```tsx
 * <PageHeader
 *   title="RAM Specifications"
 *   description="Manage RAM component specifications"
 *   createLink={create().url}
 *   createLabel="Add RAM Spec"
 * />
 * ```
 */
export function PageHeader({
    title,
    description,
    createLink,
    createLabel = 'Add New',
    actions,
    children,
}: PageHeaderProps) {
    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="text-lg font-semibold md:text-xl">
                        {title}
                    </h2>
                    {description && (
                        <p className="mt-1 text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>

                {(createLink || actions) && (
                    <div className="flex gap-2">
                        {actions}
                        {createLink && (
                            <Link
                                href={createLink}
                                className="w-full sm:w-auto"
                            >
                                <Button
                                    variant="default"
                                    className="w-full sm:w-auto"
                                >
                                    <Plus className="mr-2 h-4 w-4" />
                                    {createLabel}
                                </Button>
                            </Link>
                        )}
                    </div>
                )}
            </div>

            {children}
        </div>
    );
}
