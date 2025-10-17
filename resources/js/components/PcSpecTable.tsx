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
    ram_capacities?: string;
    ram_ddr?: string;
    disk?: string;
    disk_gb?: number;
    disk_capacities?: string;
    disk_type?: string;
    processor?: string;
    [key: string]: unknown;
}

interface PcSpecTableProps {
    pcSpecs: PcSpec[];
    selectedId?: string;
    selectedIds?: string[];
    multiSelect?: boolean;
    maxSelections?: number;
    onSelect: (id: string) => void;
    usedPcSpecIds?: number[];
}

const columns: DataTableColumn<PcSpec>[] = [
    { accessor: "model", header: "Model" },
    { accessor: "processor", header: "Processor" },
    { accessor: "ram", header: "RAM", cell: (value, row) => `${row.ram} (${row.ram_gb ?? ''} GB)` },
    { accessor: "disk", header: "Disk", cell: (value, row) => `${row.disk} (${row.disk_gb ?? ''} GB)` },
];

export default function PcSpecTable({
    pcSpecs,
    selectedId,
    selectedIds = [],
    multiSelect = false,
    maxSelections,
    onSelect,
    usedPcSpecIds
}: PcSpecTableProps) {
    const [dialogOpen, setDialogOpen] = React.useState(false);
    const [dialogRow, setDialogRow] = React.useState<PcSpec | null>(null);
    const [search, setSearch] = React.useState("");
    const [filterRam, setFilterRam] = React.useState("");
    const [filterDisk, setFilterDisk] = React.useState("");
    const [page, setPage] = React.useState(1);
    const pageSize = 7;

    const filteredSpecs = pcSpecs.filter(spec => {
        // Exclude already assigned PC specs
        const isNotUsed = !usedPcSpecIds || !usedPcSpecIds.includes(spec.id);

        const matchesSearch =
            spec.model.toLowerCase().includes(search.toLowerCase()) ||
            (spec.processor?.toLowerCase().includes(search.toLowerCase()) ?? false);
        const matchesRam = filterRam ? (spec.ram?.toLowerCase().includes(filterRam.toLowerCase()) ?? false) : true;
        const matchesDisk = filterDisk ? (spec.disk?.toLowerCase().includes(filterDisk.toLowerCase()) ?? false) : true;

        return isNotUsed && matchesSearch && matchesRam && matchesDisk;
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
            {/* Filter Inputs - responsive layout */}
            <div className="flex flex-col sm:flex-row gap-2 mb-2">
                <Input
                    type="search"
                    value={search}
                    onChange={e => { setSearch(e.target.value); setPage(1); }}
                    placeholder="Search model or processor..."
                    className="border rounded px-2 py-1 w-full sm:w-48"
                />
                <Input
                    type="search"
                    value={filterRam}
                    onChange={e => { setFilterRam(e.target.value); setPage(1); }}
                    placeholder="Filter RAM..."
                    className="border rounded px-2 py-1 w-full sm:w-32"
                />
                <Input
                    type="search"
                    value={filterDisk}
                    onChange={e => { setFilterDisk(e.target.value); setPage(1); }}
                    placeholder="Filter Disk..."
                    className="border rounded px-2 py-1 w-full sm:w-32"
                />
            </div>
            {multiSelect && maxSelections && (
                <div className="mb-2 p-2 bg-blue-50 border border-blue-200 rounded text-sm text-blue-800">
                    Selected: {selectedIds.length} / {maxSelections}
                    {selectedIds.length >= maxSelections && <span className="ml-2 font-medium">(Max reached)</span>}
                </div>
            )}

            {/* Desktop Table View - hidden on mobile */}
            <div className="hidden md:block">
                <DataTable
                    columns={tableColumns}
                    data={paginatedSpecs}
                    radio={!multiSelect}
                    checkbox={multiSelect}
                    rowSelected={p => multiSelect
                        ? selectedIds.includes(String(p.id))
                        : selectedId === String(p.id)
                    }
                    onRowSelect={p => onSelect(String(p.id))}
                />
            </div>

            {/* Mobile Card View */}
            <div className="md:hidden space-y-3">
                {paginatedSpecs.length === 0 ? (
                    <div className="py-8 text-center text-gray-500 border rounded-lg bg-card">
                        No PC specs found
                    </div>
                ) : (
                    paginatedSpecs.map((spec) => {
                        const isSelected = multiSelect
                            ? selectedIds.includes(String(spec.id))
                            : selectedId === String(spec.id);

                        return (
                            <div
                                key={spec.id}
                                onClick={() => onSelect(String(spec.id))}
                                className={`border rounded-lg p-4 shadow-sm space-y-3 cursor-pointer transition-all ${isSelected
                                        ? 'bg-blue-50 dark:bg-blue-950 border-blue-500 ring-2 ring-blue-200 dark:ring-blue-800'
                                        : 'bg-card hover:bg-gray-50 dark:hover:bg-gray-800'
                                    }`}
                            >
                                <div className="flex items-start gap-3">
                                    {multiSelect ? (
                                        <input
                                            type="checkbox"
                                            checked={isSelected}
                                            onChange={() => onSelect(String(spec.id))}
                                            className="mt-1 h-4 w-4 rounded border-gray-300"
                                            onClick={(e) => e.stopPropagation()}
                                        />
                                    ) : (
                                        <input
                                            type="radio"
                                            checked={isSelected}
                                            onChange={() => onSelect(String(spec.id))}
                                            className="mt-1 h-4 w-4"
                                            onClick={(e) => e.stopPropagation()}
                                        />
                                    )}
                                    <div className="flex-1">
                                        <div className={`font-semibold text-base mb-2 ${isSelected ? 'text-blue-900 dark:text-blue-100' : ''}`}>
                                            {spec.model}
                                        </div>
                                        <div className="space-y-1.5 text-sm">
                                            <div className="flex justify-between">
                                                <span className={isSelected ? 'text-blue-700 dark:text-blue-300' : 'text-muted-foreground'}>Processor:</span>
                                                <span className={`font-medium text-right break-words max-w-[60%] ${isSelected ? 'text-blue-900 dark:text-blue-100' : ''}`}>
                                                    {spec.processor}
                                                </span>
                                            </div>
                                            <div className="flex justify-between">
                                                <span className={isSelected ? 'text-blue-700 dark:text-blue-300' : 'text-muted-foreground'}>RAM:</span>
                                                <span className={`font-medium ${isSelected ? 'text-blue-900 dark:text-blue-100' : ''}`}>
                                                    {spec.ram} ({spec.ram_gb ?? ''} GB)
                                                </span>
                                            </div>
                                            <div className="flex justify-between">
                                                <span className={isSelected ? 'text-blue-700 dark:text-blue-300' : 'text-muted-foreground'}>Disk:</span>
                                                <span className={`font-medium ${isSelected ? 'text-blue-900 dark:text-blue-100' : ''}`}>
                                                    {spec.disk} ({spec.disk_gb ?? ''} GB)
                                                </span>
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            className={`underline text-sm mt-2 ${isSelected
                                                    ? 'text-blue-700 dark:text-blue-300 hover:text-blue-800 dark:hover:text-blue-200'
                                                    : 'text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300'
                                                }`}
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                setDialogRow(spec);
                                                setDialogOpen(true);
                                            }}
                                        >
                                            View Details
                                        </button>
                                    </div>
                                </div>
                            </div>
                        );
                    })
                )}
            </div>

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent className="max-w-[90vw] sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>PC Spec Details</DialogTitle>
                        <DialogDescription>
                            {dialogRow && (
                                <div className="space-y-4 text-left mt-4">
                                    <div className="space-y-3">
                                        <div>
                                            <div className="font-semibold text-foreground mb-1">Model:</div>
                                            <div className="text-foreground pl-2 break-words">{dialogRow.model}</div>
                                        </div>

                                        <div>
                                            <div className="font-semibold text-foreground mb-1">Processor:</div>
                                            <div className="text-foreground pl-2 break-words">{dialogRow.processor}</div>
                                        </div>

                                        <div>
                                            <div className="font-semibold text-foreground mb-1">
                                                RAM {dialogRow.ram_ddr ? `(${dialogRow.ram_ddr})` : ''}:
                                            </div>
                                            <div className="text-foreground pl-2 break-words">
                                                {dialogRow.ram} ({dialogRow.ram_capacities ?? dialogRow.ram_gb + ' GB'})
                                            </div>
                                        </div>

                                        <div>
                                            <div className="font-semibold text-foreground mb-1">
                                                Disk {dialogRow.disk_type ? `(${dialogRow.disk_type})` : ''}:
                                            </div>
                                            <div className="text-foreground pl-2 break-words">
                                                {dialogRow.disk} ({dialogRow.disk_capacities ?? dialogRow.disk_gb + ' GB'})
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </DialogDescription>
                    </DialogHeader>
                </DialogContent>
            </Dialog>
            <div className="flex justify-center items-center mt-2">
                <Pagination>
                    <PaginationContent className="flex-wrap gap-1">
                        <PaginationItem>
                            <PaginationPrevious
                                href="#"
                                onClick={(e: React.MouseEvent) => { e.preventDefault(); if (page > 1) setPage(page - 1); }}
                                className={page === 1 ? "pointer-events-none opacity-50" : ""}
                            />
                        </PaginationItem>
                        {[...Array(totalPages)].map((_, i) => (
                            <PaginationItem key={i} className="hidden sm:inline-block">
                                <PaginationLink
                                    href="#"
                                    isActive={page === i + 1}
                                    onClick={(e: React.MouseEvent) => { e.preventDefault(); setPage(i + 1); }}
                                >
                                    {i + 1}
                                </PaginationLink>
                            </PaginationItem>
                        ))}
                        {/* Mobile: Show current page */}
                        <PaginationItem className="sm:hidden">
                            <span className="px-3 py-2 text-sm">
                                Page {page} of {totalPages || 1}
                            </span>
                        </PaginationItem>
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
