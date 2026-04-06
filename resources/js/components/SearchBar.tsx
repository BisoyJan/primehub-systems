import React from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

interface SearchBarProps {
    value: string;
    onChange: (value: string) => void;
    onSubmit: (e: React.FormEvent) => void;
    placeholder?: string;
    className?: string;
}

/**
 * Reusable search bar component for list/index pages
 * Provides consistent search UI across the application
 *
 * @example
 * ```tsx
 * <SearchBar
 *   value={searchTerm}
 *   onChange={setSearchTerm}
 *   onSubmit={handleSearch}
 *   placeholder="Search specifications..."
 * />
 * ```
 */
export function SearchBar({
    value,
    onChange,
    onSubmit,
    placeholder = "Search...",
    className = ""
}: SearchBarProps) {
    return (
        <form onSubmit={onSubmit} className={cn('flex gap-2', className)} role="search">
            <Input
                type="text"
                name="search"
                placeholder={placeholder}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                aria-label={placeholder}
                className="w-full"
            />
            <Button type="submit" className="shrink-0">Search</Button>
        </form>
    );
}
