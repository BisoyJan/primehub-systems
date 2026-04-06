import React, { ReactNode } from 'react';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

interface FormFieldProps {
    label: string;
    htmlFor?: string;
    error?: string;
    description?: string;
    required?: boolean;
    className?: string;
    children: ReactNode;
}

/**
 * Reusable form field wrapper component
 * Provides consistent label, error display, and optional description across all forms
 *
 * @example
 * ```tsx
 * <FormField label="Manufacturer" htmlFor="manufacturer" error={errors.manufacturer} required>
 *     <Input id="manufacturer" value={data.manufacturer} onChange={...} />
 * </FormField>
 * ```
 */
export function FormField({
    label,
    htmlFor,
    error,
    description,
    required = false,
    className,
    children,
}: FormFieldProps) {
    return (
        <div className={cn('space-y-1.5', className)}>
            <Label htmlFor={htmlFor}>
                {label}
                {required && <span className="text-destructive ml-0.5">*</span>}
            </Label>
            {children}
            {error && (
                <p className="text-destructive text-sm" role="alert">
                    {error}
                </p>
            )}
            {description && !error && (
                <p className="text-xs text-muted-foreground">{description}</p>
            )}
        </div>
    );
}
