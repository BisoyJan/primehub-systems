import { Head, Link, router } from '@inertiajs/react';
import { useState, useMemo, useEffect, useRef, useCallback } from 'react';
import { ArrowLeft, ArrowRight, Clock, RefreshCw, Play, Pause, Search } from 'lucide-react';
import { format, formatDistanceToNow, isValid, parseISO } from 'date-fns';

import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui/select';
import { DatePicker } from '@/components/ui/date-picker';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import PaginationNav, { PaginationLink } from '@/components/pagination-nav';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { useFlashMessage, usePageLoading, usePageMeta, useDebounce } from '@/hooks';
import {
    history as pcTransfersHistoryRoute,
    index as pcTransfersIndexRoute,
} from '@/routes/pc-transfers';
import { index as stationsIndexRoute } from '@/routes/stations';

type Transfer = {
    id: number;
    from_station: string | null;
    to_station: string | null;
    pc_spec: string | null;
    user: string | null;
    transfer_type: string;
    notes: string | null;
    created_at: string;
};

type TransfersPayload = {
    data: Transfer[];
    links: PaginationLink[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
};

type HistoryFilters = {
    search: string;
    type: string;
    date_from: string;
    date_to: string;
};

type PageProps = {
    transfers: TransfersPayload;
    filters: HistoryFilters;
};

const TRANSFER_TYPES = [
    { value: 'all', label: 'All types' },
    { value: 'assign', label: 'Assign / Move' },
    { value: 'swap', label: 'Swap' },
    { value: 'remove', label: 'Remove' },
];

/** "Wednesday, October 29, 2026" over "1:16 AM · 2 hours ago"; falls back to the raw value if unparsable. */
function TransferTimestamp({ value, compact = false }: { value: string; compact?: boolean }) {
    const date = parseISO(value);
    if (!isValid(date)) {
        return <span>{value}</span>;
    }

    return (
        <div className={compact ? 'text-xs' : 'text-sm'} title={format(date, 'yyyy-MM-dd HH:mm:ss')}>
            <div className="font-medium leading-tight">{format(date, 'EEEE, MMMM d, yyyy')}</div>
            <div className="text-xs text-muted-foreground">
                {format(date, 'h:mm a')}
                <span className="mx-1">·</span>
                {formatDistanceToNow(date, { addSuffix: true })}
            </div>
        </div>
    );
}

export default function History({ transfers, filters }: PageProps) {
    const { title, breadcrumbs } = usePageMeta({
        title: 'PC Transfer History',
        breadcrumbs: [
            { title: 'Stations', href: stationsIndexRoute().url },
            { title: 'PC Transfer', href: pcTransfersIndexRoute().url },
            { title: 'History', href: pcTransfersHistoryRoute().url },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const [lastRefresh, setLastRefresh] = useState<Date>(new Date());
    const [autoRefreshEnabled, setAutoRefreshEnabled] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');
    const [type, setType] = useState(filters.type || 'all');
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');
    const debouncedSearch = useDebounce(search, 400);

    const hasActiveFilters = Boolean(search.trim()) || type !== 'all' || Boolean(dateFrom) || Boolean(dateTo);

    const buildParams = useCallback((overrides: Partial<HistoryFilters> = {}) => {
        const values = { search: debouncedSearch, type, date_from: dateFrom, date_to: dateTo, ...overrides };
        const params: Record<string, string> = {};
        if (values.search.trim()) params.search = values.search.trim();
        if (values.type && values.type !== 'all') params.type = values.type;
        if (values.date_from) params.date_from = values.date_from;
        if (values.date_to) params.date_to = values.date_to;
        return params;
    }, [debouncedSearch, type, dateFrom, dateTo]);

    const fetchHistory = useCallback((params: Record<string, string>) => {
        router.get(pcTransfersHistoryRoute().url, params, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            only: ['transfers', 'filters'],
            onSuccess: () => setLastRefresh(new Date()),
        });
    }, []);

    // Auto-apply filters; skip the initial mount and identical re-runs.
    const lastAppliedRef = useRef<string | null>(null);
    useEffect(() => {
        const params = buildParams();
        const serialized = new URLSearchParams(params).toString();
        if (lastAppliedRef.current === null) {
            lastAppliedRef.current = serialized;
            return;
        }
        if (lastAppliedRef.current === serialized) {
            return;
        }
        lastAppliedRef.current = serialized;
        fetchHistory(params);
    }, [buildParams, fetchHistory]);

    const handleClearFilters = () => {
        setSearch('');
        setType('all');
        setDateFrom('');
        setDateTo('');
    };

    const summary = useMemo(() => {
        const showingStart = transfers.data.length > 0
            ? transfers.meta.per_page * (transfers.meta.current_page - 1) + 1
            : 0;
        const showingEnd = transfers.data.length > 0
            ? showingStart + transfers.data.length - 1
            : 0;
        return transfers.data.length > 0
            ? `Showing ${showingStart}-${showingEnd} of ${transfers.meta.total} transfers`
            : 'No transfers to display';
    }, [transfers]);

    const handlePaginate = (pageNumber: number) => {
        fetchHistory({ ...buildParams(), page: String(pageNumber) });
    };

    const handleRefresh = () => {
        fetchHistory(buildParams());
    };

    // Auto-refresh every 30 seconds
    const isPollingRef = useRef(false);
    useEffect(() => {
        if (!autoRefreshEnabled) return;
        const interval = setInterval(() => {
            if (isPollingRef.current) return;
            isPollingRef.current = true;
            router.get(pcTransfersHistoryRoute().url, buildParams(), {
                preserveScroll: true,
                preserveState: true,
                replace: true,
                only: ['transfers'],
                onSuccess: () => setLastRefresh(new Date()),
                onFinish: () => { isPollingRef.current = false; },
            });
        }, 30000);

        return () => clearInterval(interval);
    }, [autoRefreshEnabled, buildParams]);

    function getTransferTypeVariant(type: string): 'default' | 'secondary' | 'destructive' | 'outline' {
        switch (type) {
            case 'assign':
                return 'default';
            case 'swap':
                return 'secondary';
            case 'remove':
                return 'destructive';
            default:
                return 'outline';
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isPageLoading} />

                <PageHeader
                    title="PC Transfer History"
                    description="Complete log of assignments, swaps, and removals"
                >
                    <div className="flex flex-wrap items-center gap-2">
                        <Button variant="ghost" size="icon" onClick={handleRefresh} title="Refresh">
                            <RefreshCw className="h-4 w-4" />
                        </Button>
                        <Button
                            variant={autoRefreshEnabled ? "default" : "ghost"}
                            size="icon"
                            onClick={() => setAutoRefreshEnabled(!autoRefreshEnabled)}
                            title={autoRefreshEnabled ? "Disable auto-refresh" : "Enable auto-refresh (30s)"}
                        >
                            {autoRefreshEnabled ? <Pause className="h-4 w-4" /> : <Play className="h-4 w-4" />}
                        </Button>
                        <Link href={pcTransfersIndexRoute().url}>
                            <Button variant="outline" size="sm">
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                <span className="hidden sm:inline">Back to Transfers</span>
                                <span className="sm:hidden">Back</span>
                            </Button>
                        </Link>
                    </div>
                </PageHeader>

                <div className="rounded-lg border bg-card p-3 space-y-3">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="space-y-1.5">
                            <Label className="text-xs text-muted-foreground">Search</Label>
                            <div className="relative">
                                <Search className="absolute left-2.5 top-3 h-4 w-4 text-muted-foreground" />
                                <Input
                                    type="search"
                                    placeholder="PC number, station, user..."
                                    className="pl-8"
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                />
                            </div>
                        </div>
                        <div className="space-y-1.5">
                            <Label className="text-xs text-muted-foreground">Type</Label>
                            <Select value={type} onValueChange={setType}>
                                <SelectTrigger className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {TRANSFER_TYPES.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label className="text-xs text-muted-foreground">From date</Label>
                            <DatePicker value={dateFrom} onChange={setDateFrom} maxDate={dateTo || undefined} placeholder="Any" />
                        </div>
                        <div className="space-y-1.5">
                            <Label className="text-xs text-muted-foreground">To date</Label>
                            <DatePicker value={dateTo} onChange={setDateTo} minDate={dateFrom || undefined} defaultMonth={dateFrom || undefined} placeholder="Any" />
                        </div>
                    </div>
                    {hasActiveFilters && (
                        <div className="flex justify-end">
                            <Button variant="ghost" size="sm" onClick={handleClearFilters}>
                                Reset filters
                            </Button>
                        </div>
                    )}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Clock className="h-5 w-5" />
                            Transfer History
                        </CardTitle>
                        <CardDescription>
                            Complete log of all PC transfers between stations
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {/* Desktop Table View */}
                        <div className="hidden md:block overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Date & Time</TableHead>
                                        <TableHead>PC</TableHead>
                                        <TableHead>From Station</TableHead>
                                        <TableHead>To Station</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead>User</TableHead>
                                        <TableHead>Notes</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {transfers.data.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={7} className="text-center py-8 text-muted-foreground">
                                                No transfer history found
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        transfers.data.map((transfer) => (
                                            <TableRow key={transfer.id}>
                                                <TableCell className="whitespace-nowrap">
                                                    <TransferTimestamp value={transfer.created_at} />
                                                </TableCell>
                                                <TableCell>
                                                    <span className="text-blue-600 font-medium">
                                                        {transfer.pc_spec || '-'}
                                                    </span>
                                                </TableCell>
                                                <TableCell>
                                                    {transfer.from_station ? (
                                                        <span className="text-orange-600">
                                                            {transfer.from_station}
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">-</span>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {transfer.to_station ? (
                                                        <span className="text-green-600">
                                                            {transfer.to_station}
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">-</span>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant={getTransferTypeVariant(transfer.transfer_type)}>
                                                        {transfer.transfer_type}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>{transfer.user || '-'}</TableCell>
                                                <TableCell>
                                                    {transfer.notes ? (
                                                        <span className="text-sm text-muted-foreground">
                                                            {transfer.notes}
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">-</span>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </div>

                        {/* Mobile Card View */}
                        <div className="md:hidden space-y-4">
                            {transfers.data.length === 0 ? (
                                <div className="text-center py-8 text-muted-foreground">
                                    No transfer history found
                                </div>
                            ) : (
                                transfers.data.map((transfer) => (
                                    <div key={transfer.id} className="bg-card border rounded-lg p-4 shadow-sm space-y-3">
                                        <div className="flex justify-between items-start">
                                            <div>
                                                <span className="text-blue-600 font-semibold">
                                                    {transfer.pc_spec || 'Unknown PC'}
                                                </span>
                                                <div className="mt-1">
                                                    <TransferTimestamp value={transfer.created_at} compact />
                                                </div>
                                            </div>
                                            <Badge variant={getTransferTypeVariant(transfer.transfer_type)}>
                                                {transfer.transfer_type}
                                            </Badge>
                                        </div>

                                        <div className="flex items-center gap-2 text-sm">
                                            <div className="flex-1">
                                                <span className="text-muted-foreground">From: </span>
                                                {transfer.from_station ? (
                                                    <span className="text-orange-600 font-medium">{transfer.from_station}</span>
                                                ) : (
                                                    <span className="text-muted-foreground">-</span>
                                                )}
                                            </div>
                                            <ArrowRight className="h-4 w-4 text-muted-foreground" />
                                            <div className="flex-1 text-right">
                                                <span className="text-muted-foreground">To: </span>
                                                {transfer.to_station ? (
                                                    <span className="text-green-600 font-medium">{transfer.to_station}</span>
                                                ) : (
                                                    <span className="text-muted-foreground">-</span>
                                                )}
                                            </div>
                                        </div>

                                        <div className="text-sm space-y-1 pt-2 border-t">
                                            <div className="flex justify-between">
                                                <span className="text-muted-foreground">By:</span>
                                                <span>{transfer.user || '-'}</span>
                                            </div>
                                            {transfer.notes && (
                                                <div>
                                                    <span className="text-muted-foreground">Notes: </span>
                                                    <span className="text-sm">{transfer.notes}</span>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>

                        <div className="mt-4 flex flex-col gap-2 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
                            <span>{summary}</span>
                            <span className="text-xs">Last updated: {lastRefresh.toLocaleTimeString()}</span>
                        </div>
                    </CardContent>
                </Card>

                {transfers.links && transfers.links.length > 0 && (
                    <div className="flex justify-center mt-4">
                        <PaginationNav
                            links={transfers.links}
                            onPageChange={handlePaginate}
                            only={['transfers']}
                        />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
