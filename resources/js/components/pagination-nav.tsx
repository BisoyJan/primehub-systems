import React from "react";
import { Link } from "@inertiajs/react";

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
}

export default function PaginationNav({ links, className = "", onPageChange }: Props) {
    if (!links || links.length === 0) return null;

    const getPageNumber = (url: string | null): number | null => {
        if (!url) return null;
        const match = url.match(/([?&])page=(\d+)/);
        return match ? parseInt(match[2], 10) : 1;
    };

    const renderLink = (link: PaginationLink, key: React.Key) => {
        const isEllipsis = link.label === "..." || link.label === "…";
        const active = !!link.active;
        const enabled = link.enabled !== false && !!link.url && !isEllipsis;

        const base =
            "inline-flex items-center justify-center min-w-[2rem] h-8 px-2 rounded-md text-sm font-medium border transition-colors";

        const activeClasses = "bg-blue-600 text-white border-blue-600 shadow-sm";
        const normalClasses = "bg-white text-gray-700 border-gray-200 hover:bg-gray-50";
        const disabledClasses = "bg-transparent text-gray-400 border-transparent cursor-not-allowed";

        if (isEllipsis) {
            return (
                <span
                    key={key}
                    className={`${base} ${disabledClasses} cursor-default select-none`}
                    aria-hidden
                >
                    …
                </span>
            );
        }

        if (enabled && link.url) {
            if (onPageChange) {
                const pageNum = getPageNumber(link.url);
                return (
                    <button
                        key={key}
                        type="button"
                        className={`${base} ${active ? activeClasses : normalClasses}`}
                        aria-current={active ? "page" : undefined}
                        onClick={() => pageNum && onPageChange(pageNum)}
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                );
            } else {
                return (
                    <Link
                        key={key}
                        href={link.url}
                        className={`${base} ${active ? activeClasses : normalClasses}`}
                        preserveScroll
                        aria-current={active ? "page" : undefined}
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                );
            }
        }

        return (
            <button
                key={key}
                type="button"
                disabled
                className={`${base} ${disabledClasses}`}
                aria-disabled="true"
            >
                <span dangerouslySetInnerHTML={{ __html: link.label }} />
            </button>
        );
    };

    return (
        <nav aria-label="Pagination" className={`inline-flex items-center gap-2 ${className}`}>
            {links.map((l, i) => renderLink(l, i))}
        </nav>
    );
}
