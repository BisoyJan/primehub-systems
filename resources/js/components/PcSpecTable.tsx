import { DataTable, DataTableColumn } from '@/components/ui/data-table';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Pagination,
    PaginationContent,
    PaginationItem,
    PaginationLink,
    PaginationNext,
    PaginationPrevious,
} from '@/components/ui/pagination';
import React from 'react';
import { Input } from './ui/input';

export interface PcSpec {
    id: number;
    pc_number?: string;
    memory_type?: string | null;
    ram_gb?: number;
    disk_gb?: number;
    processor?: string;
    processor_manufacturer?: string | null;
    available_ports?: string | null;
    issue?: string | null;
    notes?: string | null;
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
    { accessor: 'pc_number', header: 'PC Number' },
    { accessor: 'processor', header: 'Processor' },
    { accessor: 'ram_gb', header: 'RAM (GB)' },
    { accessor: 'disk_gb', header: 'Disk (GB)' },
];

export default function PcSpecTable({
    pcSpecs,
    selectedId,
    selectedIds = [],
    multiSelect = false,
    maxSelections,
    onSelect,
    usedPcSpecIds,
}: PcSpecTableProps) {
    const [dialogOpen, setDialogOpen] = React.useState(false);
    const [dialogRow, setDialogRow] = React.useState<PcSpec | null>(null);
    const [search, setSearch] = React.useState('');
    const [filterRam, setFilterRam] = React.useState('');
    const [filterDisk, setFilterDisk] = React.useState('');
    const [page, setPage] = React.useState(1);
    const [hasAutoNavigated, setHasAutoNavigated] = React.useState(false);
    const pageSize = 7;

    const filteredSpecs = pcSpecs.filter((spec) => {
        // Exclude already assigned PC specs
        const isNotUsed = !usedPcSpecIds || !usedPcSpecIds.includes(spec.id);

        const matchesSearch =
            (spec.pc_number?.toLowerCase().includes(search.toLowerCase()) ??
                false) ||
            (spec.processor?.toLowerCase().includes(search.toLowerCase()) ??
                false);
        const matchesRam = filterRam
            ? String(spec.ram_gb ?? '').includes(filterRam)
            : true;
        const matchesDisk = filterDisk
            ? String(spec.disk_gb ?? '').includes(filterDisk)
            : true;

        return isNotUsed && matchesSearch && matchesRam && matchesDisk;
    });

    // Auto-navigate to the page containing the selected PC spec on initial load
    React.useEffect(() => {
        if (hasAutoNavigated) return;

        const currentSelectedId = multiSelect ? selectedIds[0] : selectedId;
        if (!currentSelectedId) return;

        const selectedIndex = filteredSpecs.findIndex(
            (spec) => String(spec.id) === currentSelectedId,
        );
        if (selectedIndex !== -1) {
            const targetPage = Math.floor(selectedIndex / pageSize) + 1;
            if (targetPage !== page) {
                setPage(targetPage);
            }
            setHasAutoNavigated(true);
        }
    }, [
        filteredSpecs,
        selectedId,
        selectedIds,
        multiSelect,
        hasAutoNavigated,
        page,
        pageSize,
    ]);

    const paginatedSpecs = filteredSpecs.slice(
        (page - 1) * pageSize,
        page * pageSize,
    );
    const totalPages = Math.ceil(filteredSpecs.length / pageSize);

    // Add Details column at the end
    const tableColumns: DataTableColumn<PcSpec>[] = [
        ...columns,
        {
            accessor: 'view',
            header: 'Details',
            cell: (value, row) => (
                <button
                    type="button"
                    className="text-sm text-blue-600 underline"
                    onClick={() => {
                        setDialogRow(row);
                        setDialogOpen(true);
                    }}
                >
                    View Details
                </button>
            ),
        },
    ];

    return (
        <div className="space-y-2">
            {/* Filter Inputs - responsive layout */}
            <div className="mb-2 flex flex-col gap-2 sm:flex-row">
                <Input
                    type="search"
                    value={search}
                    onChange={(e) => {
                        setSearch(e.target.value);
                        setPage(1);
                    }}
                    placeholder="Search PC#, model, processor..."
                    className="w-full rounded border px-2 py-1 sm:w-56"
                />
                <Input
                    type="search"
                    value={filterRam}
                    onChange={(e) => {
                        setFilterRam(e.target.value);
                        setPage(1);
                    }}
                    placeholder="Filter RAM..."
                    className="w-full rounded border px-2 py-1 sm:w-32"
                />
                <Input
                    type="search"
                    value={filterDisk}
                    onChange={(e) => {
                        setFilterDisk(e.target.value);
                        setPage(1);
                    }}
                    placeholder="Filter Disk..."
                    className="w-full rounded border px-2 py-1 sm:w-32"
                />
            </div>
            {multiSelect && maxSelections && (
                <div className="mb-2 rounded border border-blue-200 bg-blue-50 p-2 text-sm text-blue-800">
                    Selected: {selectedIds.length} / {maxSelections}
                    {selectedIds.length >= maxSelections && (
                        <span className="ml-2 font-medium">(Max reached)</span>
                    )}
                </div>
            )}

            {/* Desktop Table View - hidden on mobile */}
            <div className="hidden md:block">
                <DataTable
                    columns={tableColumns}
                    data={paginatedSpecs}
                    radio={!multiSelect}
                    checkbox={multiSelect}
                    rowSelected={(p) =>
                        multiSelect
                            ? selectedIds.includes(String(p.id))
                            : selectedId === String(p.id)
                    }
                    onRowSelect={(p) => onSelect(String(p.id))}
                />
            </div>

            {/* Mobile Card View */}
            <div className="space-y-3 md:hidden">
                {paginatedSpecs.length === 0 ? (
                    <div className="rounded-lg border bg-card py-8 text-center text-gray-500">
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
                                className={`cursor-pointer space-y-3 rounded-lg border p-4 shadow-sm transition-all ${
                                    isSelected
                                        ? 'border-blue-500 bg-blue-50 ring-2 ring-blue-200 dark:bg-blue-950 dark:ring-blue-800'
                                        : 'bg-card hover:bg-gray-50 dark:hover:bg-gray-800'
                                }`}
                            >
                                <div className="flex items-start gap-3">
                                    {multiSelect ? (
                                        <input
                                            type="checkbox"
                                            checked={isSelected}
                                            onChange={() =>
                                                onSelect(String(spec.id))
                                            }
                                            className="mt-1 h-4 w-4 rounded border-gray-300"
                                            onClick={(e) => e.stopPropagation()}
                                            aria-label={`Select ${spec.pc_number || spec.id}`}
                                        />
                                    ) : (
                                        <input
                                            type="radio"
                                            checked={isSelected}
                                            onChange={() =>
                                                onSelect(String(spec.id))
                                            }
                                            className="mt-1 h-4 w-4"
                                            onClick={(e) => e.stopPropagation()}
                                            aria-label={`Select ${spec.pc_number || spec.id}`}
                                        />
                                    )}
                                    <div className="flex-1">
                                        <div
                                            className={`mb-1 text-base font-semibold ${isSelected ? 'text-blue-900 dark:text-blue-100' : ''}`}
                                        >
                                            {spec.pc_number && (
                                                <span className="text-primary">
                                                    {spec.pc_number}
                                                </span>
                                            )}
                                        </div>
                                        <div className="space-y-1.5 text-sm">
                                            <div className="flex justify-between">
                                                <span
                                                    className={
                                                        isSelected
                                                            ? 'text-blue-700 dark:text-blue-300'
                                                            : 'text-muted-foreground'
                                                    }
                                                >
                                                    Processor:
                                                </span>
                                                <span
                                                    className={`max-w-[60%] text-right font-medium wrap-break-word ${isSelected ? 'text-blue-900 dark:text-blue-100' : ''}`}
                                                >
                                                    {spec.processor}
                                                </span>
                                            </div>
                                            <div className="flex justify-between">
                                                <span
                                                    className={
                                                        isSelected
                                                            ? 'text-blue-700 dark:text-blue-300'
                                                            : 'text-muted-foreground'
                                                    }
                                                >
                                                    RAM:
                                                </span>
                                                <span
                                                    className={`font-medium ${isSelected ? 'text-blue-900 dark:text-blue-100' : ''}`}
                                                >
                                                    {spec.ram_gb
                                                        ? `${spec.ram_gb} GB`
                                                        : '—'}
                                                </span>
                                            </div>
                                            <div className="flex justify-between">
                                                <span
                                                    className={
                                                        isSelected
                                                            ? 'text-blue-700 dark:text-blue-300'
                                                            : 'text-muted-foreground'
                                                    }
                                                >
                                                    Disk:
                                                </span>
                                                <span
                                                    className={`font-medium ${isSelected ? 'text-blue-900 dark:text-blue-100' : ''}`}
                                                >
                                                    {spec.disk_gb
                                                        ? `${spec.disk_gb} GB`
                                                        : '—'}
                                                </span>
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            className={`mt-2 text-sm underline ${
                                                isSelected
                                                    ? 'text-blue-700 hover:text-blue-800 dark:text-blue-300 dark:hover:text-blue-200'
                                                    : 'text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300'
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
                <DialogContent className="w-full max-w-[95vw] sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle className="text-lg font-semibold">
                            {dialogRow?.pc_number ||
                                (dialogRow
                                    ? `PC #${dialogRow.id}`
                                    : 'PC Details')}
                        </DialogTitle>
                        <DialogDescription>
                            {dialogRow
                                ? `${dialogRow.pc_number || `PC #${dialogRow.id}`}`
                                : 'No PC selected'}
                        </DialogDescription>
                    </DialogHeader>
                    {dialogRow && (
                        <div className="max-h-[60vh] space-y-4 overflow-y-auto pr-1 text-sm">
                            <div className="grid grid-cols-2 gap-x-6 gap-y-3 rounded-lg border p-4">
                                {dialogRow.memory_type && (
                                    <div>
                                        <span className="text-xs text-muted-foreground">
                                            Memory Type
                                        </span>
                                        <p className="font-medium">
                                            {dialogRow.memory_type}
                                        </p>
                                    </div>
                                )}
                                <div>
                                    <span className="text-xs text-muted-foreground">
                                        RAM
                                    </span>
                                    <p className="font-medium">
                                        {dialogRow.ram_gb
                                            ? `${dialogRow.ram_gb} GB`
                                            : 'N/A'}
                                    </p>
                                </div>
                                <div>
                                    <span className="text-xs text-muted-foreground">
                                        Disk
                                    </span>
                                    <p className="font-medium">
                                        {dialogRow.disk_gb
                                            ? `${dialogRow.disk_gb} GB`
                                            : 'N/A'}
                                    </p>
                                </div>
                                {dialogRow.available_ports && (
                                    <div className="col-span-2">
                                        <span className="text-xs text-muted-foreground">
                                            Available Ports
                                        </span>
                                        <p className="font-medium">
                                            {dialogRow.available_ports}
                                        </p>
                                    </div>
                                )}
                            </div>

                            {(dialogRow.processor ||
                                dialogRow.processor_manufacturer) && (
                                <div className="space-y-2">
                                    <h4 className="text-sm font-semibold">
                                        Processor
                                    </h4>
                                    <div className="space-y-1 rounded-lg border p-4">
                                        {dialogRow.processor_manufacturer && (
                                            <div>
                                                <span className="text-xs text-muted-foreground">
                                                    Manufacturer
                                                </span>
                                                <p className="font-medium">
                                                    {
                                                        dialogRow.processor_manufacturer
                                                    }
                                                </p>
                                            </div>
                                        )}
                                        {dialogRow.processor && (
                                            <div>
                                                <span className="text-xs text-muted-foreground">
                                                    Model
                                                </span>
                                                <p className="font-medium">
                                                    {dialogRow.processor}
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )}

                            {dialogRow.issue && (
                                <div className="rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-900/20">
                                    <span className="text-xs text-muted-foreground">
                                        Issue
                                    </span>
                                    <p className="font-medium whitespace-pre-wrap text-red-600 dark:text-red-400">
                                        {dialogRow.issue}
                                    </p>
                                </div>
                            )}

                            {dialogRow.notes && (
                                <div className="rounded-lg border p-4">
                                    <span className="text-xs text-muted-foreground">
                                        Notes
                                    </span>
                                    <p className="font-medium whitespace-pre-wrap">
                                        {dialogRow.notes}
                                    </p>
                                </div>
                            )}
                        </div>
                    )}
                    <div className="mt-2 flex justify-end">
                        <button
                            type="button"
                            onClick={() => setDialogOpen(false)}
                            className="inline-flex w-full items-center justify-center rounded-md border border-input bg-background px-4 py-2 text-sm font-medium shadow-sm hover:bg-accent hover:text-accent-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            Close
                        </button>
                    </div>
                </DialogContent>
            </Dialog>
            <div className="mt-2 flex items-center justify-center">
                <Pagination>
                    <PaginationContent className="flex-wrap gap-1">
                        <PaginationItem>
                            <PaginationPrevious
                                href="#"
                                onClick={(e: React.MouseEvent) => {
                                    e.preventDefault();
                                    if (page > 1) setPage(page - 1);
                                }}
                                className={
                                    page === 1
                                        ? 'pointer-events-none opacity-50'
                                        : ''
                                }
                            />
                        </PaginationItem>
                        {[...Array(totalPages)].map((_, i) => (
                            <PaginationItem
                                key={i}
                                className="hidden sm:inline-block"
                            >
                                <PaginationLink
                                    href="#"
                                    isActive={page === i + 1}
                                    onClick={(e: React.MouseEvent) => {
                                        e.preventDefault();
                                        setPage(i + 1);
                                    }}
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
                                onClick={(e: React.MouseEvent) => {
                                    e.preventDefault();
                                    if (page < totalPages) setPage(page + 1);
                                }}
                                className={
                                    page === totalPages || totalPages === 0
                                        ? 'pointer-events-none opacity-50'
                                        : ''
                                }
                            />
                        </PaginationItem>
                    </PaginationContent>
                </Pagination>
            </div>
        </div>
    );
}
