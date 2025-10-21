import React, { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Plus } from 'lucide-react';

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
    createLabel = "Add New",
    actions,
    children
}: PageHeaderProps) {
    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                <div>
                    <h2 className="text-lg md:text-xl font-semibold">{title}</h2>
                    {description && (
                        <p className="text-sm text-muted-foreground mt-1">{description}</p>
                    )}
                </div>

                {(createLink || actions) && (
                    <div className="flex gap-2">
                        {actions}
                        {createLink && (
                            <Link href={createLink} className="w-full sm:w-auto">
                                <Button className="bg-blue-600 hover:bg-blue-700 text-white w-full sm:w-auto">
                                    <Plus className="w-4 h-4 mr-2" />
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
