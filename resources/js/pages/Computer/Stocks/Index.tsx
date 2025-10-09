import { useCallback, useEffect, useMemo, useState } from 'react';
import { Head, useForm, usePage, router } from '@inertiajs/react';
import type { Page as InertiaPage } from '@inertiajs/core';
import { toast } from 'sonner';
import { Plus, Trash, Edit, RefreshCw, Search } from 'lucide-react'; // Added Search icon

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

import { dashboard } from '@/routes';
import {
    index as stocksIndex,
    store as stocksStore,
    update as stocksUpdate,
    destroy as stocksDestroy,
} from '@/routes/stocks';

const breadcrumbs = [{ title: 'Stocks', href: dashboard().url }];

type SpecType = 'ram' | 'disk' | 'processor';

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
    const page = usePage<PageProps>();
    const props = page.props;

    const initialStocks = (props.stocks?.data ?? []) as StockRow[];
    const initialLinks = (props.stocks?.links ?? []) as PaginationLink[];
    const initialFilter = (props.filterType as SpecType) ?? ('all' as const);

    const [rows, setRows] = useState<StockRow[]>(initialStocks);
    const [links, setLinks] = useState<PaginationLink[]>(initialLinks);
    const [loading, setLoading] = useState(false);
    const [filterType, setFilterType] = useState<SpecType | 'all'>(initialFilter);
    const [pollMs, setPollMs] = useState<number | null>(null);

    // --- Search State ---
    const [searchQuery, setSearchQuery] = useState('');
    const [debouncedSearchQuery, setDebouncedSearchQuery] = useState('');
    // --- End Search State ---

    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<StockRow | null>(null);

    // Quick adjust dialog state
    const [adjustOpen, setAdjustOpen] = useState(false);
    const [adjustRow, setAdjustRow] = useState<StockRow | null>(null);
    const [adjustDelta, setAdjustDelta] = useState<string>('-1');

    // Delete confirm dialog state
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleteRow, setDeleteRow] = useState<StockRow | null>(null);

    const { data, setData, reset, processing, errors } = useForm({
        type: 'ram' as SpecType,
        stockable_id: '',
        quantity: 0,
        reserved: 0,
        location: '',
        notes: '',
    });

    const typedErrors = errors as Record<string, string | string[] | undefined>;

    useEffect(() => {
        if (!props.flash?.message) return;
        if (props.flash.type === 'error') toast.error(props.flash.message);
        else toast.success(props.flash.message);
    }, [props.flash?.message, props.flash?.type]);

    // --- Debounce search input ---
    useEffect(() => {
        const timer = setTimeout(() => {
            setDebouncedSearchQuery(searchQuery);
        }, 500); // Wait 500ms after user stops typing

        return () => clearTimeout(timer);
    }, [searchQuery]);
    // --- End Debounce ---

    const fetchIndex = useCallback((pageUrl?: string) => {
        setLoading(true);

        const params: Record<string, string | number | string[]> = {};
        if (filterType !== 'all') params.type = filterType;
        if (debouncedSearchQuery) params.search = debouncedSearchQuery; // Always send search param

        const url = pageUrl ?? stocksIndex().url;

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
    }, [filterType, debouncedSearchQuery]); // Add debounced query as a dependency

    useEffect(() => {
        // This effect triggers a data fetch whenever the filter or debounced search changes.
        // Changing the search term will reset the view to the first page of results.
        fetchIndex();
    }, [filterType, debouncedSearchQuery]); // Remove fetchIndex from here

    useEffect(() => {
        if (!pollMs || pollMs <= 0) return;
        const t = window.setInterval(() => fetchIndex(), pollMs);
        return () => clearInterval(t);
    }, [pollMs, fetchIndex]);

    function mapTypeFromStockable(stockableType: string): SpecType {
        if (!stockableType) return 'ram';
        const base = stockableType.split('\\').pop?.() ?? stockableType;
        if (/Ram/i.test(base)) return 'ram';
        if (/Disk/i.test(base)) return 'disk';
        if (/Processor/i.test(base)) return 'processor';
        return 'ram';
    }

    function getLabel(row: StockRow) {
        if (row.stockable?.label) return row.stockable.label;
        const name = row.stockable_type?.split('\\').pop?.() ?? row.stockable_type;
        return `${name} #${row.stockable_id}`;
    }

    // --- Other functions (openCreate, openEdit, submit, etc.) remain the same ---
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

        const onFinish = () => fetchIndex();

        if (editing) {
            router.put(stocksUpdate(editing.id).url, payload, {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Stock updated');
                    setOpen(false);
                    fetchIndex();
                },
                onError: () => toast.error('Validation error'),
            });
        } else {
            router.post(stocksStore().url, payload, {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Stock created');
                    setOpen(false);
                    fetchIndex();
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
                fetchIndex();
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
                fetchIndex();
            },
            onError: () => toast.error('Delete failed'),
        });
    }

    const rowCountLabel = useMemo(
        () => `${rows.length} row${rows.length !== 1 ? 's' : ''}`,
        [rows]
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Stocks" />

            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3">
                <div className="flex items-center gap-3 mb-4">
                    <h2 className="text-xl font-semibold">Stock Management</h2>

                    <div className="ml-auto flex items-center gap-2">
                        {/* --- Search Input Field --- */}
                        <div className="relative">
                            <Search className="absolute left-2.5 top-3 h-4 w-4 text-muted-foreground" />
                            <Input
                                type="search"
                                placeholder="Search location, notes, model..."
                                className="pl-8 sm:w-[300px]"
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                            />
                        </div>
                        {/* --- End Search Input --- */}

                        <Select
                            value={filterType}
                            onValueChange={(v: string) => setFilterType(v as SpecType | 'all')}
                        >
                            <SelectTrigger className="w-36">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All types</SelectItem>
                                <SelectItem value="ram">RAM</SelectItem>
                                <SelectItem value="disk">Disk</SelectItem>
                                <SelectItem value="processor">Processor</SelectItem>
                            </SelectContent>
                        </Select>

                        <Button onClick={() => fetchIndex()}>
                            <RefreshCw size={16} />
                        </Button>
                        <Button
                            onClick={() => setPollMs(pollMs ? null : 15000)}
                            variant="outline"
                        >
                            {pollMs ? 'Stop Poll' : 'Poll'}
                        </Button>
                        <Button onClick={openCreate}>
                            <Plus size={16} /> Add
                        </Button>
                    </div>
                </div>

                {/* --- The rest of the JSX remains the same --- */}
                <div className="shadow rounded-md overflow-hidden">
                    <div className="p-3 border-b flex items-center justify-between">
                        <div className="text-sm text-gray-600">
                            {loading ? 'Loading...' : rowCountLabel}
                        </div>
                    </div>

                    <div className="overflow-x-auto p-3">
                        <Table>
                            <TableCaption>
                                Stock items for RAM, Disk and Processor specs
                            </TableCaption>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>ID</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Manufacturer / Brand</TableHead>
                                    <TableHead>Model / Series</TableHead>
                                    <TableHead>Quantity</TableHead>
                                    <TableHead>Reserved</TableHead>
                                    <TableHead>Location</TableHead>
                                    <TableHead>Notes</TableHead>
                                    <TableHead>Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell>{row.id}</TableCell>
                                        <TableCell>{mapTypeFromStockable(row.stockable_type)}</TableCell>
                                        <TableCell>
                                            {row.stockable?.manufacturer ?? row.stockable?.brand ?? '-'}
                                        </TableCell>
                                        <TableCell>
                                            {row.stockable?.model_number ??
                                                row.stockable?.model ??
                                                row.stockable?.series ??
                                                '-'}
                                        </TableCell>
                                        <TableCell>{row.quantity}</TableCell>
                                        <TableCell>{row.reserved}</TableCell>
                                        <TableCell>{row.location ?? '-'}</TableCell>
                                        <TableCell>{row.notes ?? '-'}</TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <Button className="bg-green-600 hover:bg-green-700 text-white" onClick={() => openEdit(row)}>
                                                    <Edit size={14} />
                                                </Button>
                                                <Button variant="ghost" onClick={() => openQuickAdjust(row)}>
                                                    <Plus size={14} />
                                                </Button>
                                                <Button variant="destructive" onClick={() => openDeleteConfirm(row)}>
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

                <div className="flex justify-center mt-4">
                    {links && links.length > 0 && <PaginationNav links={links} />}
                </div>
            </div>

            {/* All Dialogs (Create/Edit, Quick Adjust, Delete) remain the same */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
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

                    <DialogFooter className="flex gap-2">
                        <Button onClick={() => setOpen(false)} variant="outline">
                            Cancel
                        </Button>
                        <Button onClick={submit} disabled={processing}>
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
                <DialogContent>
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

                    <DialogFooter className="flex gap-2">
                        <Button variant="outline" onClick={() => setAdjustOpen(false)}>
                            Cancel
                        </Button>
                        <Button onClick={submitQuickAdjust}>Apply</Button>
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
                <DialogContent>
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

                    <DialogFooter className="flex gap-2">
                        <Button variant="outline" onClick={() => setDeleteOpen(false)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={submitDelete}>
                            Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

        </AppLayout>
    );
}
