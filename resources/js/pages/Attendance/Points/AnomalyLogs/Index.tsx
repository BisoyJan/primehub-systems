import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { useFlashMessage, usePageMeta, usePageLoading } from '@/hooks';
import { PageHeader } from '@/components/PageHeader';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import PaginationNav, { PaginationLink } from '@/components/pagination-nav';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { formatDateTime } from '@/lib/utils';
import { AlertTriangle, CheckCircle2, PlayCircle, RefreshCw, RotateCcw, Trash2 } from 'lucide-react';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { DeleteConfirmDialog } from '@/components/DeleteConfirmDialog';

const INDEX_URL = '/attendance-points/management/anomaly-logs';
const RUN_URL = '/attendance-points/management/anomaly-logs/run-audit';
const CLEAR_URL = '/attendance-points/management/anomaly-logs/clear';

interface UserMini {
    id: number;
    first_name: string;
    last_name: string;
    email?: string | null;
}

interface PointMini {
    id: number;
    user_id: number;
    shift_date: string;
    point_type: string;
}

interface AnomalyLog {
    id: number;
    batch_id: string;
    trigger: string;
    type: string;
    expected: string | null;
    actual: string | null;
    repaired: boolean;
    context: Record<string, unknown> | null;
    created_at: string;
    user: UserMini | null;
    attendance_point: PointMini | null;
}

interface Paginator<T> {
    data: T[];
    links: PaginationLink[];
    from: number | null;
    to: number | null;
    total: number;
    current_page: number;
    last_page: number;
}

interface Props {
    logs: Paginator<AnomalyLog>;
    filters: {
        type?: string;
        trigger?: string;
        repaired?: string;
        user_id?: string;
        batch_id?: string;
    };
    stats: {
        total: number;
        unrepaired: number;
        last_24h: number;
        by_type: Record<string, number>;
    };
    types: string[];
    triggers: string[];
}

const TRIGGER_LABEL: Record<string, string> = {
    scheduled: 'Daily Audit (8:23 AM)',
    manual_run: 'Manual Run (Admin)',
    excuse: 'Point Excused',
    unexcuse: 'Point Unexcused',
    manual_point_create: 'Manual Point Created',
    manual_point_update: 'Manual Point Updated',
    manual_point_delete: 'Manual Point Deleted',
    manual_write: 'Attendance Write',
};

const TYPE_TONE: Record<string, string> = {
    STALE_PENDING_GBRO: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    STALE_PENDING_SRO: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
    ORPHAN_GBRO_DATE: 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
    EXCUSED_HAS_GBRO_DATE: 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
    GBRO_ELIGIBILITY_MISMATCH: 'bg-violet-100 text-violet-800 dark:bg-violet-900/40 dark:text-violet-200',
    EXPIRES_AT_OVERFLOW: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
};

const TYPE_DESCRIPTION: Record<string, string> = {
    STALE_PENDING_GBRO: 'GBRO expiry date has already passed but the point was never marked as expired.',
    STALE_PENDING_SRO: 'SRO expiry date has already passed but the point was never marked as expired.',
    ORPHAN_GBRO_DATE: 'Point has a GBRO expiry date set but is not eligible for GBRO — the date should not exist.',
    EXCUSED_HAS_GBRO_DATE: 'Point is excused but still carries a GBRO expiry date. Excused points must never be GBRO-expired.',
    GBRO_ELIGIBILITY_MISMATCH: "Point's eligible_for_gbro flag does not match what its violation type actually allows.",
    EXPIRES_AT_OVERFLOW: 'More than 2 active points have GBRO expiry dates. Only the top-2 most-recent eligible points should hold a date.',
};

export default function GbroAnomalyLogIndex({ logs, filters, stats, types, triggers }: Props) {
    const { title, breadcrumbs } = usePageMeta({
        title: 'GBRO Anomaly Logs',
        breadcrumbs: [
            { title: 'Home', href: '/' },
            { title: 'Attendance Points', href: '/attendance-points' },
            { title: 'GBRO Anomaly Logs' },
        ],
    });
    useFlashMessage();
    const isLoading = usePageLoading();

    const [type, setType] = React.useState(filters.type ?? '');
    const [trigger, setTrigger] = React.useState(filters.trigger ?? '');
    const [repaired, setRepaired] = React.useState(filters.repaired ?? '');
    const [userId, setUserId] = React.useState(filters.user_id ?? '');
    const [batchId, setBatchId] = React.useState(filters.batch_id ?? '');

    // Derive active tab from the repaired filter
    const activeTab = repaired === '0' ? 'pending' : repaired === '1' ? 'repaired' : 'all';

    const switchTab = (tab: string) => {
        const repairedVal = tab === 'pending' ? '0' : tab === 'repaired' ? '1' : '';
        setRepaired(repairedVal);
        router.get(
            INDEX_URL,
            {
                type: type || undefined,
                trigger: trigger || undefined,
                repaired: repairedVal || undefined,
                user_id: userId || undefined,
                batch_id: batchId || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const applyFilters = () => {
        router.get(
            INDEX_URL,
            {
                type: type || undefined,
                trigger: trigger || undefined,
                repaired: repaired || undefined,
                user_id: userId || undefined,
                batch_id: batchId || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const resetFilters = () => {
        setType('');
        setTrigger('');
        setRepaired('');
        setUserId('');
        setBatchId('');
        router.get(INDEX_URL, {}, { preserveState: false, preserveScroll: true });
    };

    const runAudit = (dryRun: boolean) => {
        router.post(
            RUN_URL,
            { dry_run: dryRun, user_id: userId || undefined },
            { preserveScroll: true },
        );
    };

    const clearLogs = (scope: 'repaired' | 'all') => {
        router.delete(CLEAR_URL, { data: { scope }, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            {isLoading && <LoadingOverlay />}

            <div className="relative flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
            <PageHeader
                title={title}
                description="Detected GBRO/SRO drift across attendance points. Auto-fires on excuse / unexcuse, manual point writes, and the daily 8:23 AM audit."
                actions={
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <Button variant="outline" onClick={() => runAudit(true)} disabled={isLoading}>
                            <PlayCircle className="mr-2 h-4 w-4" /> Dry-run audit
                        </Button>
                        <Button onClick={() => runAudit(false)} disabled={isLoading}>
                            <RotateCcw className="mr-2 h-4 w-4" /> Run audit & repair
                        </Button>
                    </div>
                }
            />

            {/* Stats */}
            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm text-muted-foreground">Total logged</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p className="text-2xl font-semibold">{stats.total}</p>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm text-muted-foreground">Unrepaired</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p className="text-2xl font-semibold text-amber-600 dark:text-amber-400">
                            {stats.unrepaired}
                        </p>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm text-muted-foreground">Last 24h</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p className="text-2xl font-semibold">{stats.last_24h}</p>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm text-muted-foreground">Distinct types</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p className="text-2xl font-semibold">{Object.keys(stats.by_type).length}</p>
                    </CardContent>
                </Card>
            </div>

            {/* Tabs + Clear actions */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <Tabs value={activeTab} onValueChange={switchTab}>
                    <TabsList>
                        <TabsTrigger value="all">All ({stats.total})</TabsTrigger>
                        <TabsTrigger value="pending" className="text-amber-600 data-[state=active]:text-amber-600">
                            Pending ({stats.unrepaired})
                        </TabsTrigger>
                        <TabsTrigger value="repaired" className="text-green-600 data-[state=active]:text-green-600">
                            Repaired ({stats.total - stats.unrepaired})
                        </TabsTrigger>
                    </TabsList>
                </Tabs>

                <div className="flex gap-2">
                    <DeleteConfirmDialog
                        title="Clear repaired logs"
                        description="This will permanently delete all repaired anomaly log entries. Pending (unrepaired) logs will be kept. This cannot be undone."
                        triggerLabel="Clear repaired"
                        trigger={
                            <Button variant="outline" size="sm" disabled={stats.total - stats.unrepaired === 0}>
                                <Trash2 className="mr-2 h-4 w-4" /> Clear repaired
                            </Button>
                        }
                        onConfirm={() => clearLogs('repaired')}
                    />
                    <DeleteConfirmDialog
                        title="Clear all logs"
                        description="This will permanently delete every anomaly log entry including pending ones. This cannot be undone."
                        triggerLabel="Clear all"
                        trigger={
                            <Button variant="destructive" size="sm" disabled={stats.total === 0}>
                                <Trash2 className="mr-2 h-4 w-4" /> Clear all
                            </Button>
                        }
                        onConfirm={() => clearLogs('all')}
                    />
                </div>
            </div>

            {/* Filters */}
            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Filters</CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <Label className="text-xs">Type</Label>
                            <Select value={type || 'all'} onValueChange={(v) => setType(v === 'all' ? '' : v)}>
                                <SelectTrigger>
                                    <SelectValue placeholder="All types" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All types</SelectItem>
                                    {types.map((t) => (
                                        <SelectItem key={t} value={t}>
                                            {t}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label className="text-xs">Trigger</Label>
                            <Select
                                value={trigger || 'all'}
                                onValueChange={(v) => setTrigger(v === 'all' ? '' : v)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All triggers" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All triggers</SelectItem>
                                    {triggers.map((t) => (
                                        <SelectItem key={t} value={t}>
                                            {t}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label className="text-xs">User ID</Label>
                            <Input
                                inputMode="numeric"
                                value={userId}
                                onChange={(e) => setUserId(e.target.value)}
                                placeholder="e.g. 42"
                            />
                        </div>
                        <div>
                            <Label className="text-xs">Batch ID</Label>
                            <Input
                                value={batchId}
                                onChange={(e) => setBatchId(e.target.value)}
                                placeholder="audit_..."
                            />
                        </div>
                    </div>
                    <div className="mt-3 flex flex-col gap-2 sm:flex-row">
                        <Button onClick={applyFilters} className="flex-1 sm:flex-none">
                            <RefreshCw className="mr-2 h-4 w-4" /> Apply
                        </Button>
                        <Button variant="outline" onClick={resetFilters} className="flex-1 sm:flex-none">
                            Reset
                        </Button>
                    </div>
                </CardContent>
            </Card>

            {/* Desktop table */}
            <Card className="hidden md:block">
                <CardContent className="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>When</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Trigger</TableHead>
                                <TableHead>Employee</TableHead>
                                <TableHead>Point</TableHead>
                                <TableHead>Expected</TableHead>
                                <TableHead>Actual</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Batch</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {logs.data.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={9} className="py-10 text-center text-muted-foreground">
                                        No anomalies recorded for the current filters.
                                    </TableCell>
                                </TableRow>
                            )}
                            {logs.data.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell className="whitespace-nowrap">
                                        {formatDateTime(row.created_at)}
                                    </TableCell>
                                    <TableCell>
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                <span
                                                    className={`inline-block cursor-default rounded px-2 py-0.5 text-xs font-medium ${TYPE_TONE[row.type] ?? 'bg-muted text-foreground'}`}
                                                >
                                                    {row.type}
                                                </span>
                                            </TooltipTrigger>
                                            {TYPE_DESCRIPTION[row.type] && (
                                                <TooltipContent className="max-w-xs text-xs">
                                                    {TYPE_DESCRIPTION[row.type]}
                                                </TooltipContent>
                                            )}
                                        </Tooltip>
                                    </TableCell>
                                    <TableCell>
                                        <span className="flex flex-col gap-0.5">
                                            <span className="text-xs font-medium">{TRIGGER_LABEL[row.trigger] ?? row.trigger}</span>
                                            <span className="font-mono text-[10px] text-muted-foreground">{row.trigger}</span>
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        {row.user
                                            ? `${row.user.first_name} ${row.user.last_name}`
                                            : '—'}
                                    </TableCell>
                                    <TableCell>
                                        {row.attendance_point ? (
                                            <Link
                                                href={`/attendance-points/${row.attendance_point.user_id}`}
                                                className="text-primary hover:underline"
                                            >
                                                #{row.attendance_point.id}
                                            </Link>
                                        ) : (
                                            '—'
                                        )}
                                    </TableCell>
                                    <TableCell className="w-36 max-w-36 text-xs text-muted-foreground">
                                        {row.expected ? (
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <span className="block truncate cursor-default">{row.expected}</span>
                                                </TooltipTrigger>
                                                <TooltipContent className="max-w-sm break-all font-mono text-xs">{row.expected}</TooltipContent>
                                            </Tooltip>
                                        ) : '—'}
                                    </TableCell>
                                    <TableCell className="w-36 max-w-36 text-xs text-muted-foreground">
                                        {row.actual ? (
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <span className="block truncate cursor-default">{row.actual}</span>
                                                </TooltipTrigger>
                                                <TooltipContent className="max-w-sm break-all font-mono text-xs">{row.actual}</TooltipContent>
                                            </Tooltip>
                                        ) : '—'}
                                    </TableCell>
                                    <TableCell>
                                        {row.repaired ? (
                                            <span className="inline-flex items-center gap-1 text-green-700 dark:text-green-400">
                                                <CheckCircle2 className="h-4 w-4" /> Repaired
                                            </span>
                                        ) : (
                                            <span className="inline-flex items-center gap-1 text-amber-700 dark:text-amber-400">
                                                <AlertTriangle className="h-4 w-4" /> Pending
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs text-muted-foreground">
                                        {row.batch_id}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            {/* Mobile cards */}
            <div className="space-y-3 md:hidden">
                {logs.data.length === 0 && (
                    <Card>
                        <CardContent className="py-8 text-center text-muted-foreground">
                            No anomalies recorded.
                        </CardContent>
                    </Card>
                )}
                {logs.data.map((row) => (
                    <Card key={row.id}>
                        <CardContent className="space-y-2 p-4">
                            <div className="flex items-start justify-between gap-2">
                                <span className="flex flex-col gap-1">
                                    <span
                                        className={`inline-block rounded px-2 py-0.5 text-xs font-medium ${TYPE_TONE[row.type] ?? 'bg-muted text-foreground'}`}
                                    >
                                        {row.type}
                                    </span>
                                    {TYPE_DESCRIPTION[row.type] && (
                                        <span className="text-[10px] leading-tight text-muted-foreground">
                                            {TYPE_DESCRIPTION[row.type]}
                                        </span>
                                    )}
                                </span>
                                {row.repaired ? (
                                    <CheckCircle2 className="h-5 w-5 shrink-0 text-green-600" />
                                ) : (
                                    <AlertTriangle className="h-5 w-5 shrink-0 text-amber-600" />
                                )}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {formatDateTime(row.created_at)} • <span className="font-mono">{row.trigger}</span>
                            </p>
                            {row.user && (
                                <p className="text-sm">
                                    <span className="text-muted-foreground">Employee:</span>{' '}
                                    {row.user.first_name} {row.user.last_name}
                                </p>
                            )}
                            {row.expected && (
                                <p className="text-xs">
                                    <span className="text-muted-foreground">Expected:</span> {row.expected}
                                </p>
                            )}
                            {row.actual && (
                                <p className="text-xs">
                                    <span className="text-muted-foreground">Actual:</span> {row.actual}
                                </p>
                            )}
                            <p className="font-mono text-[10px] text-muted-foreground">{row.batch_id}</p>
                        </CardContent>
                    </Card>
                ))}
            </div>

            <div className="flex justify-center">
                <PaginationNav links={logs.links} />
            </div>
            </div>
        </AppLayout>
    );
}
