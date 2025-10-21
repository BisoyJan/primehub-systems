import React from 'react';
import { Button } from '@/components/ui/button';

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
        <form onSubmit={onSubmit} className={`flex gap-2 ${className}`}>
            <input
                type="text"
                name="search"
                placeholder={placeholder}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="border rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
            <Button type="submit" className="shrink-0">Search</Button>
        </form>
    );
}
