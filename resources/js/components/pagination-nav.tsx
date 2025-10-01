import { Link } from "@inertiajs/react";
import {
    Pagination,
    PaginationContent,
    PaginationItem,
    PaginationLink,
    PaginationPrevious,
    PaginationNext,
} from "@/components/ui/pagination";

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    links: PaginationLink[];
    className?: string;
}

export default function PaginationNav({ links, className }: Props) {
    // detect prev/next robustly
    const prev = links.find(l =>
        l.label.toLowerCase().includes("previous")
    ) || links[0];

    const next = links.find(l =>
        l.label.toLowerCase().includes("next")
    ) || links[links.length - 1];

    return (
        <Pagination className={className}>
            <PaginationContent>
                <PaginationItem disabled={!prev.url}>
                    {prev.url
                        ? <Link href={prev.url}><PaginationPrevious /></Link>
                        : <PaginationPrevious />}
                </PaginationItem>

                {links
                    .filter(l => !isNaN(Number(l.label)))
                    .map((l, idx) => (
                        <PaginationItem key={idx}>
                            {l.url
                                ? (
                                    <Link href={l.url}>
                                        <PaginationLink isActive={l.active}>
                                            {l.label}
                                        </PaginationLink>
                                    </Link>
                                )
                                : (
                                    <PaginationLink isActive={l.active}>
                                        {l.label}
                                    </PaginationLink>
                                )}
                        </PaginationItem>
                    ))}

                <PaginationItem disabled={!next.url}>
                    {next.url
                        ? <Link href={next.url}><PaginationNext /></Link>
                        : <PaginationNext />}
                </PaginationItem>
            </PaginationContent>
        </Pagination>
    );
}
