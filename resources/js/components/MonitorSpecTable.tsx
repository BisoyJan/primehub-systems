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
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from "@/components/ui/select";

export interface MonitorSpec {
    id: number;
    brand: string;
    model: string;
    screen_size: number;
    resolution: string;
    panel_type: string;
    ports?: string[];
    notes?: string;
}

interface MonitorSpecTableProps {
    monitorSpecs: MonitorSpec[];
    selectedMonitors: Array<{ id: number; quantity: number }>;
    monitorType: 'single' | 'dual';
    onSelect: (monitor: MonitorSpec) => void;
    onQuantityChange: (monitorId: number, quantity: number) => void;
    onDeselect: (monitorId: number) => void;
}

export default function MonitorSpecTable({
    monitorSpecs,
    selectedMonitors,
    monitorType,
    onSelect,
    onQuantityChange,
    onDeselect
}: MonitorSpecTableProps) {
    const [dialogOpen, setDialogOpen] = React.useState(false);
    const [dialogRow, setDialogRow] = React.useState<MonitorSpec | null>(null);
    const [search, setSearch] = React.useState("");
    const [filterBrand, setFilterBrand] = React.useState("");
    const [filterPanelType, setFilterPanelType] = React.useState("");
    const [page, setPage] = React.useState(1);
    const pageSize = 7;

    const maxQty = monitorType === 'dual' ? 2 : 1;
    const totalSelected = selectedMonitors.reduce((sum, m) => sum + m.quantity, 0);
    const canSelectMore = totalSelected < maxQty;

    const filteredSpecs = monitorSpecs.filter(spec => {
        const matchesSearch =
            spec.brand.toLowerCase().includes(search.toLowerCase()) ||
            spec.model.toLowerCase().includes(search.toLowerCase()) ||
            spec.resolution.toLowerCase().includes(search.toLowerCase());
        const matchesBrand = filterBrand ? spec.brand.toLowerCase().includes(filterBrand.toLowerCase()) : true;
        const matchesPanelType = filterPanelType && filterPanelType !== 'all' ? spec.panel_type === filterPanelType : true;

        return matchesSearch && matchesBrand && matchesPanelType;
    });

    const paginatedSpecs = filteredSpecs.slice((page - 1) * pageSize, page * pageSize);
    const totalPages = Math.ceil(filteredSpecs.length / pageSize);

    const columns: DataTableColumn<MonitorSpec>[] = [
        { accessor: "brand", header: "Brand" },
        { accessor: "model", header: "Model" },
        { 
            accessor: "screen_size", 
            header: "Size", 
            cell: (value) => `${value}"`
        },
        { accessor: "resolution", header: "Resolution" },
        { accessor: "panel_type", header: "Panel Type" },
        {
            accessor: "id" as keyof MonitorSpec,
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

    // Add quantity column for dual monitors
    if (monitorType === 'dual') {
        columns.splice(5, 0, {
            accessor: "model" as keyof MonitorSpec,
            header: "Qty",
            cell: (value, row) => {
                const selected = selectedMonitors.find(m => m.id === row.id);
                if (!selected) return null;

                const currentQty = selected.quantity;
                const otherMonitorsQty = selectedMonitors
                    .filter(m => m.id !== row.id)
                    .reduce((sum, m) => sum + m.quantity, 0);

                return (
                    <select
                        value={currentQty}
                        onChange={(e) => {
                            const newQty = parseInt(e.target.value);
                            if (otherMonitorsQty + newQty <= maxQty) {
                                onQuantityChange(row.id, newQty);
                            }
                        }}
                        onClick={(e) => e.stopPropagation()}
                        className="border border-gray-300 rounded px-2 py-1 text-sm"
                    >
                        <option value="1">1</option>
                        <option value="2" disabled={otherMonitorsQty + 2 > maxQty}>2</option>
                    </select>
                );
            }
        });
    }

    return (
        <div className="space-y-2">
            {/* Filter Inputs - responsive layout */}
            <div className="flex flex-col sm:flex-row gap-2 mb-2">
                <Input
                    type="search"
                    value={search}
                    onChange={e => { setSearch(e.target.value); setPage(1); }}
                    placeholder="Search brand, model, resolution..."
                    className="border rounded px-2 py-1 w-full sm:flex-1"
                />
                <Input
                    type="search"
                    value={filterBrand}
                    onChange={e => { setFilterBrand(e.target.value); setPage(1); }}
                    placeholder="Filter Brand..."
                    className="border rounded px-2 py-1 w-full sm:w-32"
                />
                <Select value={filterPanelType} onValueChange={(val) => { setFilterPanelType(val); setPage(1); }}>
                    <SelectTrigger className="w-full sm:w-32">
                        <SelectValue placeholder="Panel Type" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Panels</SelectItem>
                        <SelectItem value="IPS">IPS</SelectItem>
                        <SelectItem value="VA">VA</SelectItem>
                        <SelectItem value="TN">TN</SelectItem>
                        <SelectItem value="OLED">OLED</SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div className="mb-2 p-2 bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-700 rounded text-sm text-blue-800 dark:text-blue-200">
                Selected: {totalSelected} / {maxQty} monitor{maxQty > 1 ? 's' : ''}
                {!canSelectMore && <span className="ml-2 font-medium">(Max reached)</span>}
            </div>

            {/* Desktop Table View - hidden on mobile */}
            <div className="hidden md:block">
                <DataTable
                    columns={columns}
                    data={paginatedSpecs}
                    checkbox={true}
                    rowSelected={m => selectedMonitors.some(s => s.id === m.id)}
                    onRowSelect={(m) => {
                        const isSelected = selectedMonitors.some(s => s.id === m.id);
                        if (isSelected) {
                            onDeselect(m.id);
                        } else if (canSelectMore) {
                            onSelect(m);
                        }
                    }}
                    rowDisabled={m => {
                        const isSelected = selectedMonitors.some(s => s.id === m.id);
                        return !isSelected && !canSelectMore;
                    }}
                />
            </div>

            {/* Mobile Card View */}
            <div className="md:hidden space-y-3">
                {paginatedSpecs.length === 0 ? (
                    <div className="py-8 text-center text-gray-500 border rounded-lg bg-card">
                        No monitors found
                    </div>
                ) : (
                    paginatedSpecs.map((monitor) => {
                        const selected = selectedMonitors.find(s => s.id === monitor.id);
                        const isSelected = !!selected;
                        const isDisabled = !isSelected && !canSelectMore;

                        return (
                            <div
                                key={monitor.id}
                                onClick={() => {
                                    if (isDisabled) return;
                                    if (isSelected) {
                                        onDeselect(monitor.id);
                                    } else {
                                        onSelect(monitor);
                                    }
                                }}
                                className={`border rounded-lg p-4 shadow-sm space-y-3 transition-all ${
                                    isDisabled 
                                        ? 'opacity-50 cursor-not-allowed bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400'
                                        : isSelected
                                            ? 'bg-blue-50 dark:bg-blue-900/30 border-blue-500 dark:border-blue-600 ring-2 ring-blue-200 dark:ring-blue-700 cursor-pointer'
                                            : 'bg-card hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer'
                                }`}
                            >
                                <div className="flex items-start gap-3">
                                    <input
                                        type="checkbox"
                                        checked={isSelected}
                                        disabled={isDisabled}
                                        onChange={() => {
                                            if (isSelected) {
                                                onDeselect(monitor.id);
                                            } else {
                                                onSelect(monitor);
                                            }
                                        }}
                                        className="mt-1 h-4 w-4 rounded border-gray-300"
                                        onClick={(e) => e.stopPropagation()}
                                    />
                                    <div className="flex-1">
                                        <div className={`font-semibold text-base mb-2 ${isSelected ? 'text-blue-900 dark:text-blue-100' : ''}`}>
                                            {monitor.brand} {monitor.model}
                                        </div>
                                        <div className="space-y-1.5 text-sm">
                                            <div className="flex justify-between">
                                                <span className={isSelected ? 'text-blue-700 dark:text-blue-300' : 'text-muted-foreground'}>Size:</span>
                                                <span className={`font-medium ${isSelected ? 'text-blue-900 dark:text-blue-100' : ''}`}>
                                                    {monitor.screen_size}"
                                                </span>
                                            </div>
                                            <div className="flex justify-between">
                                                <span className={isSelected ? 'text-blue-700 dark:text-blue-300' : 'text-muted-foreground'}>Resolution:</span>
                                                <span className={`font-medium ${isSelected ? 'text-blue-900 dark:text-blue-100' : ''}`}>
                                                    {monitor.resolution}
                                                </span>
                                            </div>
                                            <div className="flex justify-between">
                                                <span className={isSelected ? 'text-blue-700 dark:text-blue-300' : 'text-muted-foreground'}>Panel:</span>
                                                <span className={`font-medium ${isSelected ? 'text-blue-900 dark:text-blue-100' : ''}`}>
                                                    {monitor.panel_type}
                                                </span>
                                            </div>
                                            {isSelected && monitorType === 'dual' && selected && (
                                                <div className="flex justify-between items-center pt-2">
                                                    <span className="text-blue-700 dark:text-blue-300">Quantity:</span>
                                                    <select
                                                        value={selected.quantity}
                                                        onChange={(e) => {
                                                            e.stopPropagation();
                                                            const newQty = parseInt(e.target.value);
                                                            const otherMonitorsQty = selectedMonitors
                                                                .filter(m => m.id !== monitor.id)
                                                                .reduce((sum, m) => sum + m.quantity, 0);
                                                            
                                                            if (otherMonitorsQty + newQty <= maxQty) {
                                                                onQuantityChange(monitor.id, newQty);
                                                            }
                                                        }}
                                                        onClick={(e) => e.stopPropagation()}
                                                        className="border border-gray-300 rounded px-2 py-1 text-sm"
                                                    >
                                                        <option value="1">1</option>
                                                        <option value="2">2</option>
                                                    </select>
                                                </div>
                                            )}
                                        </div>
                                        <button
                                            type="button"
                                            className={`underline text-sm mt-2 ${isSelected
                                                    ? 'text-blue-700 dark:text-blue-300 hover:text-blue-800 dark:hover:text-blue-200'
                                                    : 'text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300'
                                                }`}
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                setDialogRow(monitor);
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
                        <DialogTitle>Monitor Details</DialogTitle>
                        <DialogDescription>
                            {dialogRow && (
                                <div className="space-y-4 text-left mt-4">
                                    <div className="space-y-3">
                                        <div>
                                            <div className="font-semibold text-foreground mb-1">Brand:</div>
                                            <div className="text-foreground pl-2 break-words">{dialogRow.brand}</div>
                                        </div>

                                        <div>
                                            <div className="font-semibold text-foreground mb-1">Model:</div>
                                            <div className="text-foreground pl-2 break-words">{dialogRow.model}</div>
                                        </div>

                                        <div>
                                            <div className="font-semibold text-foreground mb-1">Screen Size:</div>
                                            <div className="text-foreground pl-2">{dialogRow.screen_size}"</div>
                                        </div>

                                        <div>
                                            <div className="font-semibold text-foreground mb-1">Resolution:</div>
                                            <div className="text-foreground pl-2">{dialogRow.resolution}</div>
                                        </div>

                                        <div>
                                            <div className="font-semibold text-foreground mb-1">Panel Type:</div>
                                            <div className="text-foreground pl-2">{dialogRow.panel_type}</div>
                                        </div>

                                        {dialogRow.ports && dialogRow.ports.length > 0 && (
                                            <div>
                                                <div className="font-semibold text-foreground mb-1">Ports:</div>
                                                <div className="text-foreground pl-2">
                                                    {dialogRow.ports.join(', ')}
                                                </div>
                                            </div>
                                        )}

                                        {dialogRow.notes && (
                                            <div>
                                                <div className="font-semibold text-foreground mb-1">Notes:</div>
                                                <div className="text-foreground pl-2 break-words">{dialogRow.notes}</div>
                                            </div>
                                        )}
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
