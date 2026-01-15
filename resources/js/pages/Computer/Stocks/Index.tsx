import { useEffect, useState, useRef, useCallback } from 'react';
import { Head, useForm, usePage, router } from '@inertiajs/react';
import type { Page as InertiaPage } from '@inertiajs/core';
import { toast } from 'sonner';
import { Plus, Trash, Edit, RefreshCw, Search, Filter } from 'lucide-react';

import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui/select';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogFooter,
} from '@/components/ui/dialog';
import {
    Table,
    TableBody,
    TableCaption,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import PaginationNav, { PaginationLink } from '@/components/pagination-nav';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { usePageMeta, useFlashMessage, usePageLoading } from '@/hooks';
import {
    index as stocksIndex,
    store as stocksStore,
    update as stocksUpdate,
    destroy as stocksDestroy,
} from '@/routes/stocks';

type SpecType = 'ram' | 'disk' | 'processor' | 'monitor';

type StockRow = {
    id: number;
    stockable_type: string;
    stockable_id: number;
    quantity: number;
    reserved: number;
    location?: string | null;
    notes?: string | null;
    stockable?: {
        id?: number;
        label?: string;
        brand?: string;
        series?: string;
        manufacturer?: string | null;
        model_number?: string | null;
        model?: string | null;
    } | null;
    created_at?: string | null;
    updated_at?: string | null;
};

type StocksPayload = {
    data: StockRow[];
    links: PaginationLink[];
    meta?: {
        current_page?: number;
        last_page?: number;
        per_page?: number;
        total?: number;
    };
};

type PageProps = {
    stocks?: StocksPayload;
    filterType?: string;
    flash?: { message?: string; type?: string };
};

export default function Index() {
    const { stocks, filterType: filterTypeProp } = usePage<PageProps>().props;

    const initialStocks = (stocks?.data ?? []) as StockRow[];
    const initialLinks = (stocks?.links ?? []) as PaginationLink[];
    const initialFilter = (filterTypeProp as SpecType | undefined) ?? 'all';

    const [rows, setRows] = useState<StockRow[]>(initialStocks);
    const [links, setLinks] = useState<PaginationLink[]>(initialLinks);
    const [loading, setLoading] = useState(false);
    const [filterType, setFilterType] = useState<SpecType | 'all'>(initialFilter);
    const [pollMs, setPollMs] = useState<number | null>(null);

    const [searchQuery, setSearchQuery] = useState('');
    const [debouncedSearchQuery, setDebouncedSearchQuery] = useState('');

    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<StockRow | null>(null);

    const [adjustOpen, setAdjustOpen] = useState(false);
    const [adjustRow, setAdjustRow] = useState<StockRow | null>(null);
    const [adjustDelta, setAdjustDelta] = useState<string>('-1');

    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleteRow, setDeleteRow] = useState<StockRow | null>(null);
    const [lastRefresh, setLastRefresh] = useState(new Date());

    const { data, setData, reset, processing, errors } = useForm({
        type: 'ram' as SpecType,
        stockable_id: '',
        quantity: 0,
        reserved: 0,
        location: '',
        notes: '',
    });

    const typedErrors = errors as Record<string, string | string[] | undefined>;
    const isInitialMount = useRef(true);

    useFlashMessage();
    const isPageLoading = usePageLoading();
    const { title, breadcrumbs } = usePageMeta({
        title: 'Stocks',
        breadcrumbs: [{ title: 'Stocks', href: stocksIndex().url }],
    });

    const resolveUrl = useCallback((pageUrl?: string) => {
        if (!pageUrl) return stocksIndex().url;
        try {
            const parsed = new URL(pageUrl, window.location.origin);
            return `${parsed.pathname}${parsed.search}` || stocksIndex().url;
        } catch {
            return pageUrl;
        }
    }, []);

    useEffect(() => {
        const timer = setTimeout(() => {
            setDebouncedSearchQuery(searchQuery);
        }, 500);
        return () => clearTimeout(timer);
    }, [searchQuery]);

    const fetchIndex = useCallback((pageUrl?: string) => {
        setLoading(true);

        const params: Record<string, string | number | string[]> = {};
        if (filterType !== 'all') params.type = filterType;
        if (debouncedSearchQuery) params.search = debouncedSearchQuery;

        const url = resolveUrl(pageUrl);

        router.get(url, params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: (resp: InertiaPage<PageProps>) => {
                const p = resp.props;
                setRows(p.stocks?.data ?? []);
                setLinks(p.stocks?.links ?? []);
            },
            onFinish: () => setLoading(false),
        });
    }, [filterType, debouncedSearchQuery, resolveUrl]);

    useEffect(() => {
        if (isInitialMount.current) {
            isInitialMount.current = false;
            return;
        }
        fetchIndex();
    }, [filterType, debouncedSearchQuery, fetchIndex]);

    useEffect(() => {
        if (!pollMs || pollMs <= 0) return;
        const intervalId = window.setInterval(() => fetchIndex(window.location.href), pollMs);
        return () => clearInterval(intervalId);
    }, [pollMs, fetchIndex]);

    const refreshCurrentPage = useCallback(() => {
        fetchIndex(window.location.href);
    }, [fetchIndex]);

    const handleManualRefresh = () => {
        setLastRefresh(new Date());
        refreshCurrentPage();
    };

    function mapTypeFromStockable(stockableType: string): SpecType {
        if (!stockableType) return 'ram';
        const base = stockableType.split('\\').pop?.() ?? stockableType;
        if (/Ram/i.test(base)) return 'ram';
        if (/Disk/i.test(base)) return 'disk';
        if (/Processor/i.test(base)) return 'processor';
        if (/Monitor/i.test(base)) return 'monitor';
        return 'ram';
    }

    function getLabel(row: StockRow) {
        if (row.stockable?.label) return row.stockable.label;
        const name = row.stockable_type?.split('\\').pop?.() ?? row.stockable_type;
        return `${name} #${row.stockable_id}`;
    }

    function openCreate() {
        setEditing(null);
        reset('type', 'stockable_id', 'quantity', 'reserved', 'location', 'notes');
        setData('type', 'ram');
        setData('stockable_id', '');
        setData('quantity', 0);
        setData('reserved', 0);
        setData('location', '');
        setData('notes', '');
        setOpen(true);
    }

    function openEdit(row: StockRow) {
        setEditing(row);
        setData('type', mapTypeFromStockable(row.stockable_type));
        setData('stockable_id', String(row.stockable_id));
        setData('quantity', row.quantity);
        setData('reserved', row.reserved);
        setData('location', row.location ?? '');
        setData('notes', row.notes ?? '');
        setOpen(true);
    }

    function submit() {
        if (!data.stockable_id) {
            toast.error('Please provide a spec id');
            return;
        }

        const payload = {
            type: data.type,
            stockable_id: Number(data.stockable_id),
            quantity: Number(data.quantity ?? 0),
            reserved: Number(data.reserved ?? 0),
            location: data.location || null,
            notes: data.notes || null,
        };

        const handleSuccess = () => {
            setOpen(false);
            refreshCurrentPage();
        };

        if (editing) {
            router.put(stocksUpdate(editing.id).url, payload, {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Stock updated');
                    handleSuccess();
                },
                onError: () => toast.error('Validation error'),
            });
        } else {
            router.post(stocksStore().url, payload, {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Stock created');
                    handleSuccess();
                },
                onError: () => toast.error('Validation error'),
            });
        }
    }

    function openQuickAdjust(row: StockRow) {
        setAdjustRow(row);
        setAdjustDelta('1');
        setAdjustOpen(true);
    }

    function submitQuickAdjust() {
        if (!adjustRow) return;
        const delta = Number(adjustDelta);
        if (Number.isNaN(delta)) {
            toast.error('Invalid number');
            return;
        }
        router.post('/stocks/adjust', {
            type: mapTypeFromStockable(adjustRow.stockable_type),
            stockable_id: adjustRow.stockable_id,
            delta,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setAdjustOpen(false);
                setAdjustRow(null);
                refreshCurrentPage();
            },
            onError: () => toast.error('Adjust failed'),
        });
    }

    function openDeleteConfirm(row: StockRow) {
        setDeleteRow(row);
        setDeleteOpen(true);
    }

    function submitDelete() {
        if (!deleteRow) return;
        router.delete(stocksDestroy(deleteRow.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Deleted');
                setDeleteOpen(false);
                setDeleteRow(null);
                refreshCurrentPage();
            },
            onError: () => toast.error('Delete failed'),
        });
    }

    const overlayMessage = processing
        ? (editing ? 'Updating stock...' : 'Saving stock...')
        : undefined;
    const showOverlay = isPageLoading || loading || processing;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={showOverlay} message={overlayMessage} />
                <PageHeader
                    title="Stock Management"
                    description="Monitor inventory levels, reservations, and locations for all specs"
                />

                <div className="flex flex-col gap-4">
                    <div className="flex flex-col sm:flex-row gap-4 justify-between items-start sm:items-center">
                        <div className="w-full sm:w-auto flex-1 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            <div className="relative">
                                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search location, notes, model..."
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                    className="pl-8"
                                />
                            </div>

                            <Select
                                value={filterType}
                                onValueChange={(v: string) => setFilterType(v as SpecType | 'all')}
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All types</SelectItem>
                                    <SelectItem value="ram">RAM</SelectItem>
                                    <SelectItem value="disk">Disk</SelectItem>
                                    <SelectItem value="processor">Processor</SelectItem>
                                    <SelectItem value="monitor">Monitor</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="flex gap-2 w-full sm:w-auto">
                            <Button onClick={refreshCurrentPage} className="flex-1 sm:flex-none">
                                <Filter className="mr-2 h-4 w-4" />
                                Filter
                            </Button>
                            <Button variant="ghost" size="icon" onClick={handleManualRefresh} title="Refresh">
                                <RefreshCw className="h-4 w-4" />
                            </Button>
                            <Button
                                onClick={() => setPollMs(pollMs ? null : 15000)}
                                variant="outline"
                                className="flex-1 sm:flex-none"
                            >
                                {pollMs ? 'Stop Poll' : 'Poll'}
                            </Button>
                            <Button onClick={openCreate} className="flex-1 sm:flex-none">
                                <Plus className="mr-2 h-4 w-4" />
                                Add Stock
                            </Button>
                        </div>
                    </div>

                    <div className="flex justify-between items-center text-sm">
                        <div className="text-muted-foreground">
                            Showing {rows.length} records
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Last updated: {lastRefresh.toLocaleTimeString()}
                        </div>
                    </div>
                </div>

                {/* Desktop Table */}
                <div className="hidden md:block shadow rounded-md overflow-hidden">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableCaption>
                                Stock items for RAM, Disk, Processor and Monitor specs
                            </TableCaption>
                            <TableHeader>
                                <TableRow className="bg-muted/50">
                                    <TableHead className="hidden lg:table-cell">ID</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Manufacturer / Brand</TableHead>
                                    <TableHead>Model / Series</TableHead>
                                    <TableHead>Quantity</TableHead>
                                    <TableHead className="hidden xl:table-cell">Reserved</TableHead>
                                    <TableHead className="hidden xl:table-cell">Location</TableHead>
                                    <TableHead className="hidden xl:table-cell">Notes</TableHead>
                                    <TableHead>Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell className="hidden lg:table-cell">{row.id}</TableCell>
                                        <TableCell>
                                            {mapTypeFromStockable(row.stockable_type).charAt(0).toUpperCase() +
                                                mapTypeFromStockable(row.stockable_type).slice(1)}
                                        </TableCell>
                                        <TableCell>
                                            {row.stockable?.manufacturer ?? row.stockable?.brand ?? '-'}
                                        </TableCell>
                                        <TableCell>
                                            {row.stockable?.model_number ??
                                                row.stockable?.model ??
                                                row.stockable?.series ??
                                                '-'}
                                        </TableCell>
                                        <TableCell>
                                            {row.quantity}
                                            {row.quantity < 10 && (
                                                <span className="ml-2 px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200">
                                                    Low Stock
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="hidden xl:table-cell">{row.reserved}</TableCell>
                                        <TableCell className="hidden xl:table-cell">{row.location ?? '-'}</TableCell>
                                        <TableCell className="hidden xl:table-cell">{row.notes ?? '-'}</TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <Button className="bg-green-600 hover:bg-green-700 text-white" onClick={() => openEdit(row)}>
                                                    <Edit size={14} />
                                                </Button>
                                                <Button variant="ghost" onClick={() => openQuickAdjust(row)}>
                                                    <Plus size={14} />
                                                </Button>
                                                <Button variant="destructive"
                                                    className="bg-red-600 hover:bg-red-700 text-white" onClick={() => openDeleteConfirm(row)}>
                                                    <Trash size={14} />
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}

                                {rows.length === 0 && !loading && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={9}
                                            className="py-8 text-center text-gray-500"
                                        >
                                            No stock items found
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                            <TableFooter>
                                <TableRow>
                                    <TableCell colSpan={10} className="text-center font-medium">
                                        Stock List
                                    </TableCell>
                                </TableRow>
                            </TableFooter>
                        </Table>
                    </div>
                </div>

                {/* Mobile Card View */}
                <div className="md:hidden space-y-4">
                    {rows.map((row) => (
                        <div key={row.id} className="bg-card border rounded-lg p-4 shadow-sm space-y-3">
                            <div className="flex justify-between items-start">
                                <div>
                                    <div className="text-xs text-muted-foreground">Type</div>
                                    <div className="font-semibold text-lg">
                                        {mapTypeFromStockable(row.stockable_type).charAt(0).toUpperCase() +
                                            mapTypeFromStockable(row.stockable_type).slice(1)}
                                    </div>
                                </div>
                                <div className="text-right">
                                    <div className="text-xs text-muted-foreground">Quantity</div>
                                    <div className="flex items-center gap-2 justify-end">
                                        <div className="font-medium text-lg">{row.quantity}</div>
                                        {row.quantity < 10 && (
                                            <span className="px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200">
                                                Low
                                            </span>
                                        )}
                                    </div>
                                </div>
                            </div>

                            <div className="space-y-2 text-sm">
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Manufacturer:</span>
                                    <span className="font-medium break-words text-right max-w-[60%]">
                                        {row.stockable?.manufacturer ?? row.stockable?.brand ?? '-'}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Model:</span>
                                    <span className="font-medium break-words text-right max-w-[60%]">
                                        {row.stockable?.model_number ??
                                            row.stockable?.model ??
                                            row.stockable?.series ??
                                            '-'}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Reserved:</span>
                                    <span className="font-medium">{row.reserved}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Location:</span>
                                    <span className="font-medium">{row.location ?? '-'}</span>
                                </div>
                                {row.notes && (
                                    <div className="pt-2 border-t">
                                        <div className="text-xs text-muted-foreground mb-1">Notes:</div>
                                        <div className="text-sm">{row.notes}</div>
                                    </div>
                                )}
                            </div>

                            <div className="flex gap-2 pt-2 border-t">
                                <Button className="bg-green-600 hover:bg-green-700 text-white flex-1" onClick={() => openEdit(row)}>
                                    <Edit size={14} className="mr-1" /> Edit
                                </Button>
                                <Button variant="ghost" onClick={() => openQuickAdjust(row)} className="flex-1">
                                    <Plus size={14} className="mr-1" /> Adjust
                                </Button>
                                <Button variant="destructive"
                                    className="bg-red-600 hover:bg-red-700 text-white flex-1" onClick={() => openDeleteConfirm(row)}>
                                    <Trash size={14} className="mr-1" /> Delete
                                </Button>
                            </div>
                        </div>
                    ))}

                    {rows.length === 0 && !loading && (
                        <div className="py-12 text-center text-gray-500 border rounded-lg bg-card">
                            No stock items found
                        </div>
                    )}
                </div>

                <div className="flex justify-center mt-4">
                    {links && links.length > 0 && <PaginationNav links={links} />}
                </div>
            </div>

            {/* Dialogs - made responsive */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-[90vw] sm:max-w-lg">
                    <DialogHeader>
                        <h3 className="text-lg font-semibold">
                            {editing ? 'Edit Stock' : 'Create Stock'}
                        </h3>
                    </DialogHeader>

                    <div className="grid grid-cols-1 gap-3 py-2">
                        <div>
                            <Label>Type</Label>
                            <Select
                                value={data.type}
                                onValueChange={(v: string) => setData('type', v as SpecType)}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="ram">RAM</SelectItem>
                                    <SelectItem value="disk">Disk</SelectItem>
                                    <SelectItem value="processor">Processor</SelectItem>
                                    <SelectItem value="monitor">Monitor</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label>Spec ID</Label>
                            <Input
                                value={data.stockable_id}
                                onChange={(e) => setData('stockable_id', e.target.value)}
                                placeholder="Spec id (integer)"
                            />
                            {typedErrors.stockable_id && (
                                <p className="text-red-600 text-sm mt-1">
                                    {typedErrors.stockable_id}
                                </p>
                            )}
                        </div>

                        <div>
                            <Label>Quantity</Label>
                            <Input
                                type="number"
                                value={String(data.quantity ?? '')}
                                onChange={(e) => setData('quantity', Number(e.target.value))}
                            />
                            {typedErrors.quantity && (
                                <p className="text-red-600 text-sm mt-1">
                                    {typedErrors.quantity}
                                </p>
                            )}
                        </div>

                        <div>
                            <Label>Reserved</Label>
                            <Input
                                type="number"
                                value={String(data.reserved ?? '')}
                                onChange={(e) => setData('reserved', Number(e.target.value))}
                            />
                            {typedErrors.reserved && (
                                <p className="text-red-600 text-sm mt-1">
                                    {typedErrors.reserved}
                                </p>
                            )}
                        </div>

                        <div>
                            <Label>Location</Label>
                            <Input
                                value={data.location ?? ''}
                                onChange={(e) => setData('location', e.target.value)}
                            />
                        </div>

                        <div>
                            <Label>Notes</Label>
                            <Input
                                value={data.notes ?? ''}
                                onChange={(e) => setData('notes', e.target.value)}
                            />
                        </div>
                    </div>

                    <DialogFooter className="flex flex-col sm:flex-row gap-2">
                        <Button onClick={() => setOpen(false)} variant="outline" className="w-full sm:w-auto">
                            Cancel
                        </Button>
                        <Button onClick={submit} disabled={processing} className="w-full sm:w-auto">
                            {editing ? 'Save' : 'Create'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={adjustOpen}
                onOpenChange={(o) => {
                    setAdjustOpen(o);
                    if (!o) {
                        setAdjustRow(null);
                        setAdjustDelta('1');
                    }
                }}
            >
                <DialogContent className="max-w-[90vw] sm:max-w-lg">
                    <DialogHeader>
                        <h3 className="text-lg font-semibold">Adjust Quantity</h3>
                        {adjustRow && (
                            <p className="text-sm text-muted-foreground">
                                {getLabel(adjustRow)} (current qty: {adjustRow.quantity})
                            </p>
                        )}
                    </DialogHeader>

                    <div className="grid gap-3 py-2">
                        <div>
                            <Label>Delta</Label>
                            <Input
                                type="number"
                                value={adjustDelta}
                                onChange={(e) => setAdjustDelta(e.target.value)}
                                placeholder="Use negative to decrement"
                            />
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Example: -1 to decrement, 2 to increment.
                        </div>
                    </div>

                    <DialogFooter className="flex flex-col sm:flex-row gap-2">
                        <Button variant="outline" onClick={() => setAdjustOpen(false)} className="w-full sm:w-auto">
                            Cancel
                        </Button>
                        <Button onClick={submitQuickAdjust} className="w-full sm:w-auto">Apply</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={deleteOpen}
                onOpenChange={(o) => {
                    setDeleteOpen(o);
                    if (!o) {
                        setDeleteRow(null);
                    }
                }}
            >
                <DialogContent className="max-w-[90vw] sm:max-w-lg">
                    <DialogHeader>
                        <h3 className="text-lg font-semibold">Confirm Delete</h3>
                        {deleteRow && (
                            <p className="text-sm text-muted-foreground">
                                This will delete stock #{deleteRow.id} — {getLabel(deleteRow)}.
                            </p>
                        )}
                    </DialogHeader>

                    <div className="py-2 text-sm">
                        This action cannot be undone.
                    </div>

                    <DialogFooter className="flex flex-col sm:flex-row gap-2">
                        <Button variant="outline" onClick={() => setDeleteOpen(false)} className="w-full sm:w-auto">
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={submitDelete} className="w-full sm:w-auto">
                            Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

        </AppLayout>
    );
}
