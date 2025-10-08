import { useEffect, useMemo, useState } from 'react';
import { Head, Link, useForm, usePage, router } from '@inertiajs/react';
import { toast } from 'sonner';
import { Plus, Trash, Edit, RefreshCw } from 'lucide-react';

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

import stocksAdjust from "@/actions/App/Http/Controllers/StockController";
import { dashboard } from "@/routes";
import {
    index as stocksIndex,
    store as stocksStore,
    update as stocksUpdate,
    destroy as stocksDestroy,
} from '@/routes/stocks';

const breadcrumbs = [{ title: "RamSpecs", href: dashboard().url }];

type SpecType = 'ram' | 'disk' | 'processor';

type StockRow = {
    id: number;
    stockable_type: string;
    stockable_id: number;
    quantity: number;
    reserved: number;
    location?: string | null;
    notes?: string | null;
    stockable?: { id?: number; label?: string } | null;
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
    const [queryIds, setQueryIds] = useState<string>('');
    const [pollMs, setPollMs] = useState<number | null>(null);

    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<StockRow | null>(null);

    const { data, setData, reset, processing, errors } = useForm({
        type: 'ram' as SpecType,
        stockable_id: '',
        quantity: 0,
        reserved: 0,
        location: '',
        notes: '',
    });

    // flash toast
    useEffect(() => {
        if (!props.flash?.message) return;
        if (props.flash.type === 'error') toast.error(props.flash.message);
        else toast.success(props.flash.message);
    }, [props.flash?.message, props.flash?.type]);

    // sync when Inertia provides new props
    useEffect(() => {
        setRows(initialStocks);
        setLinks(initialLinks);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [page.props]);

    function buildIndexParams() {
        const params: any = {};
        if (filterType !== 'all') params.type = filterType;
        const ids = queryIds.split(',').map(s => s.trim()).filter(Boolean);
        if (ids.length) params['ids[]'] = ids;
        return params;
    }

    function fetchIndex(pageUrl?: string) {
        setLoading(true);
        const params = buildIndexParams();
        const url = pageUrl ?? stocksIndex().url;
        router.get(url, params, {
            preserveState: true,
            replace: true,
            onSuccess: (page) => {
                const p = page.props as any;
                setRows(p.stocks?.data ?? []);
                setLinks(p.stocks?.links ?? []);
            },
            onFinish: () => setLoading(false),
        });
    }

    useEffect(() => {
        fetchIndex();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [filterType, queryIds]);

    useEffect(() => {
        if (!pollMs || pollMs <= 0) return;
        const t = window.setInterval(fetchIndex, pollMs);
        return () => clearInterval(t);
    }, [pollMs, filterType, queryIds]);

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

    function confirmDelete(row: StockRow) {
        if (!confirm('Delete this stock row?')) return;
        router.delete(stocksDestroy(row.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Deleted');
                fetchIndex();
            },
            onError: () => toast.error('Delete failed'),
        });
    }

    function quickAdjust(row: StockRow) {
        const deltaStr = prompt('Delta quantity (use negative to decrement)', '-1');
        if (!deltaStr) return;
        const delta = Number(deltaStr);
        if (Number.isNaN(delta)) {
            toast.error('Invalid number');
            return;
        }
        router.post(stocksAdjust().url, {
            type: mapTypeFromStockable(row.stockable_type),
            stockable_id: row.stockable_id,
            delta,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Adjusted');
                fetchIndex();
            },
            onError: () => toast.error('Adjust failed'),
        });
    }

    // Pagination click handler expecting shadcn links
    function onPageLinkClick(link: PaginationLink) {
        if (!link || !link.url) return;
        // Inertia visit using the provided link URL; preserve current filters
        fetchIndex(link.url);
    }

    const rowCountLabel = useMemo(() => `${rows.length} row${rows.length !== 1 ? 's' : ''}`, [rows]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Stocks" />

            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3">
                <div className="flex items-center gap-3 mb-4">
                    <h2 className="text-xl font-semibold">Stock Management</h2>

                    <div className="ml-auto flex items-center gap-2">
                        <Select value={filterType} onValueChange={(v) => setFilterType(v as any)}>
                            <SelectTrigger className="w-36"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All types</SelectItem>
                                <SelectItem value="ram">RAM</SelectItem>
                                <SelectItem value="disk">Disk</SelectItem>
                                <SelectItem value="processor">Processor</SelectItem>
                            </SelectContent>
                        </Select>

                        <Input placeholder="ids, e.g. 1,2,3" value={queryIds} onChange={(e) => setQueryIds(e.target.value)} className="w-52" />
                        <Button onClick={() => fetchIndex()}><RefreshCw size={16} /></Button>
                        <Button onClick={() => { setPollMs(pollMs ? null : 15000); }} variant="outline">{pollMs ? 'Stop Poll' : 'Poll'}</Button>
                        <Button onClick={openCreate}><Plus size={16} /> Add</Button>
                    </div>
                </div>

                <div className="shadow rounded-md overflow-hidden">
                    <div className="p-3 border-b flex items-center justify-between">
                        <div className="text-sm text-gray-600">{loading ? 'Loading...' : rowCountLabel}</div>
                        <div className="text-sm text-gray-500">Use Wayfinder routes for server-side filtering</div>
                    </div>

                    <div className="overflow-x-auto p-3">
                        <Table>
                            <TableCaption>Stock items for RAM, Disk and Processor specs</TableCaption>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>ID</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Spec</TableHead>
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
                                            <Link href={stocksIndex().url + `?ids[]=${row.stockable_id}&type=${mapTypeFromStockable(row.stockable_type)}`} className="text-blue-600 hover:underline">
                                                {getLabel(row)}
                                            </Link>
                                        </TableCell>
                                        <TableCell>{row.quantity}</TableCell>
                                        <TableCell>{row.reserved}</TableCell>
                                        <TableCell>{row.location ?? '-'}</TableCell>
                                        <TableCell>{row.notes ?? '-'}</TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <Button variant="ghost" onClick={() => openEdit(row)}><Edit size={14} /></Button>
                                                <Button variant="ghost" onClick={() => quickAdjust(row)}><Plus size={14} /></Button>
                                                <Button variant="ghost" onClick={() => confirmDelete(row)}><Trash size={14} /></Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}

                                {rows.length === 0 && !loading && (
                                    <TableRow>
                                        <TableCell colSpan={8} className="py-8 text-center text-gray-500">No stocks</TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                            <TableFooter>
                                <TableRow>
                                    <TableCell colSpan={8} className="text-center font-medium">
                                        Stock List
                                    </TableCell>
                                </TableRow>
                            </TableFooter>
                        </Table>
                    </div>
                </div>

                <div className="flex justify-center mt-4">
                    {links && links.length > 0 && (
                        <PaginationNav links={links} onLinkClick={(link) => onPageLinkClick(link)} />
                    )}
                </div>
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <h3 className="text-lg font-semibold">{editing ? 'Edit Stock' : 'Create Stock'}</h3>
                    </DialogHeader>

                    <div className="grid grid-cols-1 gap-3 py-2">
                        <div>
                            <Label>Type</Label>
                            <Select value={data.type} onValueChange={(v) => setData('type', v as SpecType)}>
                                <SelectTrigger className="w-full"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="ram">RAM</SelectItem>
                                    <SelectItem value="disk">Disk</SelectItem>
                                    <SelectItem value="processor">Processor</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label>Spec ID</Label>
                            <Input value={data.stockable_id} onChange={(e) => setData('stockable_id', e.target.value)} placeholder="Spec id (integer)" />
                            {errors.stockable_id && <p className="text-red-600 text-sm mt-1">{(errors as any).stockable_id}</p>}
                        </div>

                        <div>
                            <Label>Quantity</Label>
                            <Input type="number" value={String(data.quantity ?? '')} onChange={(e) => setData('quantity', Number(e.target.value))} />
                            {errors.quantity && <p className="text-red-600 text-sm mt-1">{(errors as any).quantity}</p>}
                        </div>

                        <div>
                            <Label>Reserved</Label>
                            <Input type="number" value={String(data.reserved ?? '')} onChange={(e) => setData('reserved', Number(e.target.value))} />
                            {errors.reserved && <p className="text-red-600 text-sm mt-1">{(errors as any).reserved}</p>}
                        </div>

                        <div>
                            <Label>Location</Label>
                            <Input value={data.location ?? ''} onChange={(e) => setData('location', e.target.value)} />
                        </div>

                        <div>
                            <Label>Notes</Label>
                            <Input value={data.notes ?? ''} onChange={(e) => setData('notes', e.target.value)} />
                        </div>
                    </div>

                    <DialogFooter className="flex gap-2">
                        <Button onClick={() => setOpen(false)} variant="outline">Cancel</Button>
                        <Button onClick={submit} disabled={processing}>{editing ? 'Save' : 'Create'}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
