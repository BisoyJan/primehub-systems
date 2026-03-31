import { Head, usePage, router } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import PaginationNav, { PaginationLink } from '@/components/pagination-nav';
import { usePageMeta, useFlashMessage, usePageLoading } from '@/hooks';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { PageHeader } from '@/components/PageHeader';
import { DatePicker } from '@/components/ui/date-picker';
import { reports as reportsRoute } from '@/routes/break-timer';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { useState, useMemo } from 'react';
import { AlertTriangle, BarChart3, Check, ChevronsUpDown, Download, FileText, RotateCcw } from 'lucide-react';
import { start as startExport } from '@/routes/break-timer/reports/export';

interface SessionUser {
    id: number;
    first_name: string;
    last_name: string;
}

interface SessionData {
    id: number;
    session_id: string;
    user: SessionUser | null;
    station: string | null;
    type: string;
    status: string;
    duration_seconds: number;
    started_at: string;
    ended_at: string | null;
    remaining_seconds: number | null;
    overage_seconds: number;
    total_paused_seconds: number;
    shift_date: string;
    last_pause_reason: string | null;
    reset_approval: string | null;
}

interface Summary {
    total_sessions: number;
    total_overage: number;
    avg_overage_seconds: number;
    total_resets: number;
}

interface Filters {
    start_date: string;
    end_date: string;
    search: string;
    user_id: string;
    type: string;
    status: string;
}

interface UserOption {
    id: number;
    first_name: string;
    last_name: string;
}

interface Sessions {
    data: SessionData[];
    links: PaginationLink[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}

interface PageProps extends Record<string, unknown> {
    sessions: Sessions;
    summary: Summary;
    filters: Filters;
    users: UserOption[];
}

function formatTime(totalSeconds: number): string {
    const safe = Math.abs(Math.floor(totalSeconds));
    const mins = Math.floor(safe / 60);
    const secs = safe % 60;
    return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
}

function formatBreakType(type: string): string {
    return type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function statusBadgeVariant(status: string) {
    switch (status) {
        case 'active': return 'default' as const;
        case 'paused': return 'secondary' as const;
        case 'completed': return 'default' as const;
        case 'overage': return 'destructive' as const;
        default: return 'secondary' as const;
    }
}

export default function BreakTimerReports() {
    const { sessions, summary, filters, users } = usePage<PageProps>().props;

    const { title, breadcrumbs } = usePageMeta({
        title: 'Break Reports',
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Break Timer', href: '/break-timer' },
            { title: 'Reports' },
        ],
    });
    useFlashMessage();
    const isLoading = usePageLoading();

    const [startDate, setStartDate] = useState(filters.start_date);
    const [endDate, setEndDate] = useState(filters.end_date);
    const [userId, setUserId] = useState(filters.user_id);
    const [type, setType] = useState(filters.type || '');
    const [status, setStatus] = useState(filters.status || '');
    const [showUserSearch, setShowUserSearch] = useState(false);
    const [userSearchQuery, setUserSearchQuery] = useState('');

    const selectedUserName = useMemo(() => {
        if (!userId) return '';
        const user = users.find((u) => String(u.id) === userId);
        return user ? `${user.first_name} ${user.last_name}` : '';
    }, [userId, users]);

    const filteredUsers = useMemo(() => {
        if (!userSearchQuery) return users;
        const q = userSearchQuery.toLowerCase();
        return users.filter(
            (u) => `${u.first_name} ${u.last_name}`.toLowerCase().includes(q),
        );
    }, [users, userSearchQuery]);

    function applyFilters() {
        router.get(
            reportsRoute().url,
            {
                start_date: startDate,
                end_date: endDate,
                user_id: userId || undefined,
                type: type || undefined,
                status: status || undefined,
            },
            { preserveState: true, preserveScroll: true },
        );
    }

    function resetFilters() {
        const now = new Date();
        const start = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split('T')[0];
        const end = now.toISOString().split('T')[0];
        setStartDate(start);
        setEndDate(end);
        setUserId('');
        setType('');
        setStatus('');
        setUserSearchQuery('');
        router.get(reportsRoute().url, {}, { preserveState: true });
    }

    // Export state
    const [isExporting, setIsExporting] = useState(false);
    const [exportStatus, setExportStatus] = useState('');

    function handleExport() {
        setIsExporting(true);
        setExportStatus('Generating export...');

        fetch(startExport().url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/octet-stream',
            },
            body: JSON.stringify({
                start_date: startDate,
                end_date: endDate,
                user_id: userId || null,
                type: type || null,
                status: status || null,
            }),
        })
            .then(async (res) => {
                if (!res.ok) {
                    const text = await res.text();
                    try {
                        const data = JSON.parse(text);
                        setExportStatus(data.message || data.errors?.start_date?.[0] || 'Export failed. Check your filters.');
                    } catch {
                        setExportStatus('Export failed. Please try again.');
                    }
                    setTimeout(() => setIsExporting(false), 3000);
                    return;
                }

                const blob = await res.blob();
                const disposition = res.headers.get('content-disposition');
                let filename = 'break_timer_export.xlsx';
                if (disposition) {
                    const match = disposition.match(/filename="?(.+?)"?(?:;|$)/);
                    if (match) filename = match[1];
                }

                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                URL.revokeObjectURL(url);
                document.body.removeChild(a);

                setExportStatus('Export complete!');
                setTimeout(() => setIsExporting(false), 1500);
            })
            .catch(() => {
                setExportStatus('Export failed.');
                setTimeout(() => setIsExporting(false), 2000);
            });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isLoading} />

                <div className="flex items-center justify-between gap-3">
                    <PageHeader title={title} description="Break session reports and analytics" />
                    <Button onClick={handleExport} disabled={isExporting} variant="outline" size="sm">
                        <Download className="mr-2 h-4 w-4" />
                        {isExporting ? 'Exporting...' : 'Export Excel'}
                    </Button>
                </div>

                {isExporting && (
                    <div className="space-y-1">
                        <p className="text-muted-foreground text-xs">{exportStatus}</p>
                    </div>
                )}

                {/* Summary Stats */}
                <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <Card>
                        <CardContent className="flex items-center gap-2.5 p-2.5">
                            <FileText className="text-muted-foreground h-7 w-7" />
                            <div>
                                <p className="text-muted-foreground text-xs">Total Sessions</p>
                                <p className="text-xl font-bold leading-tight">{summary.total_sessions}</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-2.5 p-2.5">
                            <AlertTriangle className="h-7 w-7 text-red-500" />
                            <div>
                                <p className="text-muted-foreground text-xs">Overage</p>
                                <p className="text-xl font-bold leading-tight">{summary.total_overage}</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-2.5 p-2.5">
                            <BarChart3 className="h-7 w-7 text-orange-500" />
                            <div>
                                <p className="text-muted-foreground text-xs">Avg Overage</p>
                                <p className="text-xl font-bold leading-tight">{formatTime(summary.avg_overage_seconds)}</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-2.5 p-2.5">
                            <RotateCcw className="text-muted-foreground h-7 w-7" />
                            <div>
                                <p className="text-muted-foreground text-xs">Resets</p>
                                <p className="text-xl font-bold leading-tight">{summary.total_resets}</p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <div className="flex flex-col gap-4">
                    <div className="flex flex-col sm:flex-row gap-4 justify-between items-start sm:items-center">
                        <div className="w-full sm:w-auto flex-1 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                            <div className="space-y-1">
                                <Label className="text-xs">From</Label>
                                <DatePicker
                                    value={startDate}
                                    onChange={(v) => {
                                        setStartDate(v);
                                        if (v && endDate && v > endDate) setEndDate(v);
                                    }}
                                    placeholder="Start date"
                                />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs">To</Label>
                                <DatePicker
                                    value={endDate}
                                    onChange={(v) => {
                                        setEndDate(v);
                                        if (v && startDate && v < startDate) setStartDate(v);
                                    }}
                                    placeholder="End date"
                                    minDate={startDate}
                                />
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs">Employee</Label>
                                <Popover open={showUserSearch} onOpenChange={setShowUserSearch}>
                                    <PopoverTrigger asChild>
                                        <Button
                                            variant="outline"
                                            role="combobox"
                                            aria-expanded={showUserSearch}
                                            className="w-full justify-between font-normal"
                                        >
                                            <span className="truncate">
                                                {selectedUserName || 'All Employees'}
                                            </span>
                                            <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                        </Button>
                                    </PopoverTrigger>
                                    <PopoverContent className="w-full p-0" align="start">
                                        <Command shouldFilter={false}>
                                            <CommandInput
                                                placeholder="Search employee..."
                                                value={userSearchQuery}
                                                onValueChange={setUserSearchQuery}
                                            />
                                            <CommandList>
                                                <CommandEmpty>No employee found.</CommandEmpty>
                                                <CommandGroup>
                                                    <CommandItem
                                                        value="all"
                                                        onSelect={() => {
                                                            setUserId('');
                                                            setShowUserSearch(false);
                                                        }}
                                                        className="cursor-pointer"
                                                    >
                                                        <Check
                                                            className={`mr-2 h-4 w-4 ${!userId ? 'opacity-100' : 'opacity-0'}`}
                                                        />
                                                        All Employees
                                                    </CommandItem>
                                                    {filteredUsers.map((user) => (
                                                        <CommandItem
                                                            key={user.id}
                                                            value={String(user.id)}
                                                            onSelect={() => {
                                                                setUserId(String(user.id));
                                                                setShowUserSearch(false);
                                                            }}
                                                            className="cursor-pointer"
                                                        >
                                                            <Check
                                                                className={`mr-2 h-4 w-4 ${userId === String(user.id) ? 'opacity-100' : 'opacity-0'}`}
                                                            />
                                                            {user.first_name} {user.last_name}
                                                        </CommandItem>
                                                    ))}
                                                </CommandGroup>
                                            </CommandList>
                                        </Command>
                                    </PopoverContent>
                                </Popover>
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs">Type</Label>
                                <Select value={type} onValueChange={setType}>
                                    <SelectTrigger className="w-full">
                                        <SelectValue placeholder="All Types" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="1st_break">1st Break</SelectItem>
                                        <SelectItem value="2nd_break">2nd Break</SelectItem>
                                        <SelectItem value="lunch">Lunch</SelectItem>
                                        <SelectItem value="combined">Combined</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs">Status</Label>
                                <Select value={status} onValueChange={setStatus}>
                                    <SelectTrigger className="w-full">
                                        <SelectValue placeholder="All Statuses" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="active">Active</SelectItem>
                                        <SelectItem value="paused">Paused</SelectItem>
                                        <SelectItem value="completed">Completed</SelectItem>
                                        <SelectItem value="overage">Overage</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="flex flex-wrap gap-2 w-full sm:w-auto">
                            <Button onClick={applyFilters} className="flex-1 sm:flex-none">Filter</Button>
                            <Button variant="outline" onClick={resetFilters} className="flex-1 sm:flex-none">Reset</Button>
                        </div>
                    </div>
                </div>

                {/* Desktop Table */}
                <div className="hidden md:block shadow rounded-md overflow-hidden">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow className="bg-muted/50">
                                    <TableHead>Date</TableHead>
                                    <TableHead>Agent</TableHead>
                                    <TableHead>Station</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Started</TableHead>
                                    <TableHead>Ended</TableHead>
                                    <TableHead>Overage</TableHead>
                                    <TableHead>Reset</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {sessions.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={9} className="h-24 text-center text-muted-foreground">
                                            <div className="space-y-1">
                                                <p>No sessions found for this period.</p>
                                                <p className="text-xs">Try adjusting the date range or removing filters.</p>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    sessions.data.map((session) => (
                                        <TableRow key={session.id}>
                                            <TableCell className="text-sm">{session.shift_date}</TableCell>
                                            <TableCell className="font-medium">
                                                {session.user
                                                    ? `${session.user.first_name} ${session.user.last_name}`
                                                    : '—'}
                                            </TableCell>
                                            <TableCell>{session.station || '—'}</TableCell>
                                            <TableCell>{formatBreakType(session.type)}</TableCell>
                                            <TableCell>
                                                <Badge variant={statusBadgeVariant(session.status)}>
                                                    {session.status}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                {new Date(session.started_at).toLocaleTimeString()}
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                {session.ended_at
                                                    ? new Date(session.ended_at).toLocaleTimeString()
                                                    : '—'}
                                            </TableCell>
                                            <TableCell>
                                                {session.overage_seconds > 0 ? (
                                                    <span className="text-red-500">+{formatTime(session.overage_seconds)}</span>
                                                ) : (
                                                    '—'
                                                )}
                                            </TableCell>
                                            <TableCell className="max-w-[150px] text-xs">
                                                {session.reset_approval ? (
                                                    <TooltipProvider>
                                                        <Tooltip>
                                                            <TooltipTrigger asChild>
                                                                <span className="block truncate cursor-help">{session.reset_approval}</span>
                                                            </TooltipTrigger>
                                                            <TooltipContent className="max-w-xs">
                                                                <p>{session.reset_approval}</p>
                                                            </TooltipContent>
                                                        </Tooltip>
                                                    </TooltipProvider>
                                                ) : '—'}
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </div>

                {/* Mobile Card View */}
                <div className="md:hidden space-y-4">
                    {sessions.data.length === 0 ? (
                        <div className="py-12 text-center text-muted-foreground border rounded-lg bg-card">
                            <p>No sessions found for this period.</p>
                            <p className="text-xs mt-1">Try adjusting the date range or removing filters.</p>
                        </div>
                    ) : (
                        sessions.data.map((session) => (
                            <div key={session.id} className="bg-card border rounded-lg p-4 shadow-sm space-y-3">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <span className="font-medium">
                                            {session.user
                                                ? `${session.user.first_name} ${session.user.last_name}`
                                                : '—'}
                                        </span>
                                        <p className="text-muted-foreground text-xs">{session.shift_date}</p>
                                    </div>
                                    <Badge variant={statusBadgeVariant(session.status)}>
                                        {session.status}
                                    </Badge>
                                </div>
                                <div className="text-muted-foreground grid grid-cols-2 gap-1 text-sm">
                                    <span>Type: {formatBreakType(session.type)}</span>
                                    <span>Station: {session.station || '—'}</span>
                                    <span>Started: {new Date(session.started_at).toLocaleTimeString()}</span>
                                    <span>
                                        Ended: {session.ended_at ? new Date(session.ended_at).toLocaleTimeString() : '—'}
                                    </span>
                                </div>
                                {session.overage_seconds > 0 && (
                                    <span className="text-sm text-red-500">
                                        Overage: +{formatTime(session.overage_seconds)}
                                    </span>
                                )}
                                {session.reset_approval && (
                                    <p className="text-muted-foreground text-xs">Reset: {session.reset_approval}</p>
                                )}
                            </div>
                        ))
                    )}
                </div>

                {sessions.links && (
                    <div className="flex justify-center">
                        <PaginationNav links={sessions.links} />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
