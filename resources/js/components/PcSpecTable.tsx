import React from "react";
import { DataTable, DataTableColumn } from "@/components/ui/data-table";
import { Input } from "./ui/input";
import {
    Pagination,
    PaginationContent,
    PaginationItem,
    PaginationLink,
    PaginationPrevious,
    PaginationNext
} from "@/components/ui/pagination";
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription
} from "@/components/ui/dialog";

export interface PcSpec {
    id: number;
    model: string;
    ram?: string;
    ram_gb?: number;
    disk?: string;
    disk_gb?: number;
    processor?: string;
    [key: string]: unknown;
}

interface PcSpecTableProps {
    pcSpecs: PcSpec[];
    selectedId: string;
    onSelect: (id: string) => void;
    usedPcSpecIds?: number[];
}

const columns: DataTableColumn<PcSpec>[] = [
    { accessor: "model", header: "Model" },
    { accessor: "processor", header: "Processor" },
    { accessor: "ram", header: "RAM", cell: (value, row) => `${row.ram} (${row.ram_gb ?? ''} GB)` },
    { accessor: "disk", header: "Disk", cell: (value, row) => `${row.disk} (${row.disk_gb ?? ''} GB)` },
];

export default function PcSpecTable({ pcSpecs, selectedId, onSelect, usedPcSpecIds }: PcSpecTableProps) {
    const [dialogOpen, setDialogOpen] = React.useState(false);
    const [dialogRow, setDialogRow] = React.useState<PcSpec | null>(null);
    const [search, setSearch] = React.useState("");
    const [filterRam, setFilterRam] = React.useState("");
    const [filterDisk, setFilterDisk] = React.useState("");
    const [page, setPage] = React.useState(1);
    const pageSize = 7;

    const filteredSpecs = pcSpecs.filter(spec => {
        const matchesSearch =
            spec.model.toLowerCase().includes(search.toLowerCase()) ||
            (spec.processor?.toLowerCase().includes(search.toLowerCase()) ?? false);
        const matchesRam = filterRam ? (spec.ram?.toLowerCase().includes(filterRam.toLowerCase()) ?? false) : true;
        const matchesDisk = filterDisk ? (spec.disk?.toLowerCase().includes(filterDisk.toLowerCase()) ?? false) : true;
        return matchesSearch && matchesRam && matchesDisk;
    });

    const paginatedSpecs = filteredSpecs.slice((page - 1) * pageSize, page * pageSize);
    const totalPages = Math.ceil(filteredSpecs.length / pageSize);

    // Add Details column at the end
    const tableColumns: DataTableColumn<PcSpec>[] = [
        ...columns,
        {
            accessor: "view",
            header: "Details",
            cell: (value, row) => (
                <button
                    type="button"
                    className="text-blue-600 underline text-sm"
                    onClick={() => {
                        setDialogRow(row);
                        setDialogOpen(true);
                    }}
                >
                    View Details
                </button>
            )
        }
    ];

    return (
        <div className="space-y-2">
            <div className="flex gap-2 mb-2">
                <Input
                    type="search"
                    value={search}
                    onChange={e => { setSearch(e.target.value); setPage(1); }}
                    placeholder="Search model or processor..."
                    className="border rounded px-2 py-1 w-48"
                />
                <Input
                    type="search"
                    value={filterRam}
                    onChange={e => { setFilterRam(e.target.value); setPage(1); }}
                    placeholder="Filter RAM..."
                    className="border rounded px-2 py-1 w-32"
                />
                <Input
                    type="search"
                    value={filterDisk}
                    onChange={e => { setFilterDisk(e.target.value); setPage(1); }}
                    placeholder="Filter Disk..."
                    className="border rounded px-2 py-1 w-32"
                />
            </div>
            <DataTable
                columns={tableColumns}
                data={paginatedSpecs}
                radio
                rowDisabled={p => !!usedPcSpecIds && usedPcSpecIds.includes(p.id)}
                rowSelected={p => selectedId === String(p.id)}
                onRowSelect={p => onSelect(String(p.id))}
            />
            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>PC Spec Details</DialogTitle>
                        <DialogDescription>
                            {dialogRow && (
                                <div className="space-y-2 text-left">
                                    <div><strong>Model:</strong> {dialogRow.model}</div>
                                    <div><strong>Processor:</strong> {dialogRow.processor}</div>
                                    <div><strong>RAM:</strong> {dialogRow.ram} ({dialogRow.ram_gb ?? ''} GB)</div>
                                    <div><strong>Disk:</strong> {dialogRow.disk} ({dialogRow.disk_gb ?? ''} GB)</div>
                                </div>
                            )}
                        </DialogDescription>
                    </DialogHeader>
                </DialogContent>
            </Dialog>
            <div className="flex justify-center items-center mt-2">
                <Pagination>
                    <PaginationContent>
                        <PaginationItem>
                            <PaginationPrevious
                                href="#"
                                onClick={(e: React.MouseEvent) => { e.preventDefault(); if (page > 1) setPage(page - 1); }}
                                className={page === 1 ? "pointer-events-none opacity-50" : ""}
                            />
                        </PaginationItem>
                        {[...Array(totalPages)].map((_, i) => (
                            <PaginationItem key={i}>
                                <PaginationLink
                                    href="#"
                                    isActive={page === i + 1}
                                    onClick={(e: React.MouseEvent) => { e.preventDefault(); setPage(i + 1); }}
                                >
                                    {i + 1}
                                </PaginationLink>
                            </PaginationItem>
                        ))}
                        <PaginationItem>
                            <PaginationNext
                                href="#"
                                onClick={(e: React.MouseEvent) => { e.preventDefault(); if (page < totalPages) setPage(page + 1); }}
                                className={page === totalPages || totalPages === 0 ? "pointer-events-none opacity-50" : ""}
                            />
                        </PaginationItem>
                    </PaginationContent>
                </Pagination>
            </div>
        </div>
    );
}
