import React from "react";
import { Link } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import { ChevronLeft, ChevronRight } from "lucide-react";

export interface PaginationLink {
    url: string | null;
    label: string;
    active?: boolean;
    enabled?: boolean;
}

interface Props {
    links: PaginationLink[];
    className?: string;
    onPageChange?: (page: number) => void;
    only?: string[];
    /** Maximum number of page buttons to show on mobile (default: 5) */
    maxVisiblePagesMobile?: number;
    /** Maximum number of page buttons to show on desktop (default: 10) */
    maxVisiblePagesDesktop?: number;
}

export default function PaginationNav({
    links,
    className = "",
    onPageChange,
    only,
    maxVisiblePagesMobile = 5,
    maxVisiblePagesDesktop = 10
}: Props) {
    if (!links || links.length === 0) return null;

    const getPageNumber = (url: string | null): number | null => {
        if (!url) return null;
        const match = url.match(/([?&])page=(\d+)/);
        return match ? parseInt(match[2], 10) : 1;
    };

    // Find prev, next, and current page info
    const prevLink = links[0]; // First link is typically "Previous"
    const nextLink = links[links.length - 1]; // Last link is typically "Next"
    const pageLinks = links.slice(1, -1); // Page number links (excluding prev/next)
    const currentPage = pageLinks.find(l => l.active);
    const currentPageNum = currentPage ? parseInt(currentPage.label, 10) || 1 : 1;
    const totalPages = pageLinks.filter(l => !["...", "…"].includes(l.label)).length > 0
        ? Math.max(...pageLinks.filter(l => !["...", "…"].includes(l.label)).map(l => parseInt(l.label, 10) || 0))
        : 1;

    // Calculate which pages to show (smart pagination)
    const getVisiblePages = (maxVisible: number): (number | 'ellipsis')[] => {
        if (totalPages <= maxVisible) {
            return Array.from({ length: totalPages }, (_, i) => i + 1);
        }

        const pages: (number | 'ellipsis')[] = [];
        const halfVisible = Math.floor((maxVisible - 2) / 2); // Reserve 2 for first and last

        // Always show first page
        pages.push(1);

        // Calculate start and end of middle section
        let start = Math.max(2, currentPageNum - halfVisible);
        let end = Math.min(totalPages - 1, currentPageNum + halfVisible);

        // Adjust if we're near the beginning
        if (currentPageNum <= halfVisible + 2) {
            end = Math.min(totalPages - 1, maxVisible - 1);
        }

        // Adjust if we're near the end
        if (currentPageNum >= totalPages - halfVisible - 1) {
            start = Math.max(2, totalPages - maxVisible + 2);
        }

        // Add ellipsis before middle section if needed
        if (start > 2) {
            pages.push('ellipsis');
        }

        // Add middle pages
        for (let i = start; i <= end; i++) {
            pages.push(i);
        }

        // Add ellipsis after middle section if needed
        if (end < totalPages - 1) {
            pages.push('ellipsis');
        }

        // Always show last page
        if (totalPages > 1) {
            pages.push(totalPages);
        }

        return pages;
    };

    const mobilePages = getVisiblePages(maxVisiblePagesMobile);
    const desktopPages = getVisiblePages(maxVisiblePagesDesktop);

    const base =
        "inline-flex items-center justify-center min-w-[2rem] h-8 px-2 rounded-md text-sm font-medium border transition-colors";

    const activeClasses = "bg-blue-600 text-white border-blue-600 shadow-sm";
    const normalClasses = "bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700";
    const disabledClasses = "bg-transparent text-gray-400 dark:text-gray-500 border-transparent cursor-not-allowed";

    const renderPageButton = (page: number | 'ellipsis', key: React.Key, responsiveClass: string) => {
        if (page === 'ellipsis') {
            return (
                <span
                    key={key}
                    className={`${base} ${disabledClasses} ${responsiveClass} cursor-default select-none`}
                    aria-hidden
                >
                    …
                </span>
            );
        }

        const isActive = page === currentPageNum;
        const pageLink = pageLinks.find(l => l.label === String(page));
        const url = pageLink?.url;

        if (url) {
            if (onPageChange) {
                return (
                    <Button
                        key={key}
                        type="button"
                        className={`${base} ${isActive ? activeClasses : normalClasses} ${responsiveClass}`}
                        aria-current={isActive ? "page" : undefined}
                        onClick={() => onPageChange(page)}
                    >
                        {page}
                    </Button>
                );
            } else {
                return (
                    <Link
                        key={key}
                        href={url}
                        className={`${base} ${isActive ? activeClasses : normalClasses} ${responsiveClass}`}
                        preserveScroll
                        only={only}
                        aria-current={isActive ? "page" : undefined}
                    >
                        {page}
                    </Link>
                );
            }
        }

        return (
            <Button
                key={key}
                type="button"
                disabled={!isActive}
                className={`${base} ${isActive ? activeClasses : disabledClasses} ${responsiveClass}`}
                aria-current={isActive ? "page" : undefined}
            >
                {page}
            </Button>
        );
    };

    const renderNavButton = (link: PaginationLink, direction: 'prev' | 'next') => {
        const enabled = link.enabled !== false && !!link.url;
        const Icon = direction === 'prev' ? ChevronLeft : ChevronRight;

        if (enabled && link.url) {
            if (onPageChange) {
                const pageNum = getPageNumber(link.url);
                return (
                    <Button
                        type="button"
                        className={`${base} ${normalClasses}`}
                        onClick={() => pageNum && onPageChange(pageNum)}
                        aria-label={direction === 'prev' ? 'Previous page' : 'Next page'}
                    >
                        <Icon className="h-4 w-4" />
                    </Button>
                );
            } else {
                return (
                    <Link
                        href={link.url}
                        className={`${base} ${normalClasses}`}
                        preserveScroll
                        only={only}
                        aria-label={direction === 'prev' ? 'Previous page' : 'Next page'}
                    >
                        <Icon className="h-4 w-4" />
                    </Link>
                );
            }
        }

        return (
            <Button
                type="button"
                disabled
                className={`${base} ${disabledClasses}`}
                aria-disabled="true"
                aria-label={direction === 'prev' ? 'Previous page' : 'Next page'}
            >
                <Icon className="h-4 w-4" />
            </Button>
        );
    };

    return (
        <nav aria-label="Pagination" className={`inline-flex flex-wrap items-center justify-center gap-1 md:gap-2 ${className}`}>
            {/* Previous button - always visible */}
            {renderNavButton(prevLink, 'prev')}

            {/* Mobile: Show fewer page links (hidden on md and up) */}
            <div className="flex items-center gap-1 md:hidden">
                {mobilePages.map((page, i) => renderPageButton(page, `mobile-${i}`, "inline-flex"))}
            </div>

            {/* Desktop: Show more page links (hidden below md) */}
            <div className="hidden md:flex items-center gap-1">
                {desktopPages.map((page, i) => renderPageButton(page, `desktop-${i}`, "inline-flex"))}
            </div>

            {/* Next button - always visible */}
            {renderNavButton(nextLink, 'next')}
        </nav>
    );
}
