import React from 'react';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Trash } from 'lucide-react';

interface DeleteConfirmDialogProps {
    onConfirm: () => void;
    title?: string;
    description?: string;
    triggerLabel?: string;
    triggerClassName?: string;
    disabled?: boolean;
}

/**
 * Reusable delete confirmation dialog component
 * Provides consistent delete confirmation UI across the application
 *
 * @example
 * ```tsx
 * <DeleteConfirmDialog
 *   onConfirm={() => handleDelete(item.id)}
 *   title="Delete RAM Specification"
 *   description="Are you sure you want to delete this RAM specification? This action cannot be undone."
 * />
 * ```
 */
export function DeleteConfirmDialog({
    onConfirm,
    title = "Are you absolutely sure?",
    description = "This action cannot be undone. This will permanently delete this item from the database.",
    triggerLabel = "Delete",
    triggerClassName = "",
    disabled = false
}: DeleteConfirmDialogProps) {
    return (
        <AlertDialog>
            <AlertDialogTrigger asChild>
                <Button
                    variant="destructive"
                    size="sm"
                    className={triggerClassName}
                    disabled={disabled}
                >
                    <Trash className="w-4 h-4 mr-1" />
                    {triggerLabel}
                </Button>
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription>{description}</AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <AlertDialogAction
                        onClick={onConfirm}
                        className="bg-red-600 hover:bg-red-700"
                    >
                        Delete
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
