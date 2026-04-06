import React from 'react';
import { cn } from '@/lib/utils';

interface FormErrorProps {
    message?: string;
    className?: string;
}

/**
 * Standardized inline form error message component
 * Uses the semantic `text-destructive` color token for consistency across light/dark modes
 *
 * @example
 * ```tsx
 * <FormError message={errors.email} />
 * ```
 */
export function FormError({ message, className }: FormErrorProps) {
    if (!message) return null;

    return (
        <p className={cn('text-destructive text-sm', className)} role="alert">
            {message}
        </p>
    );
}
