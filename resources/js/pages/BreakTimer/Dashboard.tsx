import { Head, usePage, router } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import PaginationNav, { PaginationLink } from '@/components/pagination-nav';
import { usePageMeta, useFlashMessage, usePageLoading } from '@/hooks';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { PageHeader } from '@/components/PageHeader';
import { DatePicker } from '@/components/ui/date-picker';
import { dashboard as dashboardRoute } from '@/routes/break-timer';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { Users, Activity, CheckCircle, AlertTriangle, Clock, ChevronsUpDown, Check, Pause, Play, PlayCircle, StopCircle } from 'lucide-react';
import { useState, useMemo, useEffect, useRef } from 'react';

interface SessionUser {
    id: number;
    first_name: string;
    last_name: string;
}

interface PauseResumeEvent {
    action: 'pause' | 'resume';
    occurred_at: string;
    reason: string | null;
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
    last_pause_reason: string | null;
    pause_resume_events: PauseResumeEvent[];
}

interface Stats {
    total_sessions: number;
    active_now: number;
    completed: number;
    overage: number;
    avg_overage_seconds: number;
}

interface Filters {
    date: string;
    search: string;
    status: string;
    type: string;
    user_id: string;
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
    stats: Stats;
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

function overageColorClass(seconds: number): string {
    if (seconds >= 60) return 'text-red-500';
    if (seconds > 30) return 'text-orange-500';
    return 'text-yellow-500';
}

function statusBadgeVariant(status: string, overageSeconds = 0): { variant: 'default' | 'secondary' | 'destructive' | 'outline'; className?: string } {
    switch (status) {
        case 'active': return { variant: 'default' };
        case 'paused': return { variant: 'secondary' };
        case 'completed': return { variant: 'default' };
        case 'overage':
            if (overageSeconds >= 60) return { variant: 'destructive' };
            if (overageSeconds > 30) return { variant: 'outline', className: 'border-orange-500 bg-orange-500/15 text-orange-600 dark:text-orange-400' };
            return { variant: 'outline', className: 'border-yellow-500 bg-yellow-500/15 text-yellow-600 dark:text-yellow-400' };
        default: return { variant: 'secondary' };
    }
}

export default function BreakTimerDashboard() {
    const { sessions, stats, filters, users } = usePage<PageProps>().props;

    const { title, breadcrumbs } = usePageMeta({
        title: 'Break Dashboard',
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Break Timer', href: '/break-timer' },
            { title: 'Dashboard' },
        ],
    });
    useFlashMessage();
    const isLoading = usePageLoading();

    const [date, setDate] = useState(filters.date);
    const [status, setStatus] = useState(filters.status);
    const [type, setType] = useState(filters.type);
    const [userId, setUserId] = useState(filters.user_id);
    const [showUserSearch, setShowUserSearch] = useState(false);
    const [userSearchQuery, setUserSearchQuery] = useState('');

    // Auto-refresh every 30 seconds for live monitoring
    const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);
    useEffect(() => {
        pollRef.current = setInterval(() => {
            router.reload({ only: ['sessions', 'stats'] });
        }, 30000);
        return () => {
            if (pollRef.current) clearInterval(pollRef.current);
        };
    }, []);

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
            dashboardRoute().url,
            { date, status: status || undefined, type: type || undefined, user_id: userId || undefined },
            { preserveState: true, preserveScroll: true },
        );
    }

    function resetFilters() {
        setDate(new Date().toISOString().split('T')[0]);
        setStatus('');
        setType('');
        setUserId('');
        setUserSearchQuery('');
        router.get(dashboardRoute().url, {}, { preserveState: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isLoading} />

                <PageHeader title={title} description="Live break session monitoring" />

                {/* Stats Cards */}
                <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
                    <Card>
                        <CardContent className="flex items-center gap-2.5 p-2.5">
                            <Users className="text-muted-foreground h-7 w-7" />
                            <div>
                                <p className="text-muted-foreground text-xs">Total</p>
                                <p className="text-xl font-bold leading-tight">{stats.total_sessions}</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-2.5 p-2.5">
                            <Activity className="h-7 w-7 text-green-500" />
                            <div>
                                <p className="text-muted-foreground text-xs">Active Now</p>
                                <p className="text-xl font-bold leading-tight">{stats.active_now}</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-2.5 p-2.5">
                            <CheckCircle className="h-7 w-7 text-blue-500" />
                            <div>
                                <p className="text-muted-foreground text-xs">Completed</p>
                                <p className="text-xl font-bold leading-tight">{stats.completed}</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-2.5 p-2.5">
                            <AlertTriangle className="h-7 w-7 text-red-500" />
                            <div>
                                <p className="text-muted-foreground text-xs">Overage</p>
                                <p className="text-xl font-bold leading-tight">{stats.overage}</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-2.5 p-2.5">
                            <Clock className="text-muted-foreground h-7 w-7" />
                            <div>
                                <p className="text-muted-foreground text-xs">Avg Overage</p>
                                <p className="text-xl font-bold leading-tight">{formatTime(stats.avg_overage_seconds)}</p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <div className="flex flex-col gap-4">
                    <div className="flex flex-col sm:flex-row gap-4 justify-between items-start sm:items-center">
                        <div className="w-full sm:w-auto flex-1 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                            <div className="flex-1">
                                <DatePicker
                                    value={date}
                                    onChange={(v) => setDate(v)}
                                    placeholder="Select date"
                                />
                            </div>
                            <div className="flex-1">
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
                            <Select value={status} onValueChange={setStatus}>
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="active">Active</SelectItem>
                                    <SelectItem value="paused">Paused</SelectItem>
                                    <SelectItem value="completed">Completed</SelectItem>
                                    <SelectItem value="overage">Overage</SelectItem>
                                </SelectContent>
                            </Select>
                            <Select value={type} onValueChange={setType}>
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="1st_break">1st Break</SelectItem>
                                    <SelectItem value="2nd_break">2nd Break</SelectItem>
                                    <SelectItem value="lunch">Lunch</SelectItem>
                                    <SelectItem value="combined">Combined</SelectItem>
                                </SelectContent>
                            </Select>
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
                                    <TableHead>Agent</TableHead>
                                    <TableHead>Station</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Timeline</TableHead>
                                    <TableHead>Overage</TableHead>
                                    <TableHead>Paused / Resumed</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {sessions.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={7} className="h-24 text-center text-muted-foreground">
                                            No sessions found.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    sessions.data.map((session) => (
                                        <TableRow key={session.id}>
                                            <TableCell className="font-medium">
                                                {session.user
                                                    ? `${session.user.first_name} ${session.user.last_name}`
                                                    : '—'}
                                            </TableCell>
                                            <TableCell>{session.station || '—'}</TableCell>
                                            <TableCell>{formatBreakType(session.type)}</TableCell>
                                            <TableCell>
                                                <Badge {...statusBadgeVariant(session.status, session.overage_seconds)}>
                                                    {session.status}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                <div className="flex flex-col gap-0.5">
                                                    <span className="flex items-center gap-1 text-xs">
                                                        <PlayCircle className="h-3 w-3 text-green-500" />
                                                        {new Date(session.started_at).toLocaleTimeString()}
                                                    </span>
                                                    {session.ended_at && (
                                                        <span className="flex items-center gap-1 text-xs">
                                                            <StopCircle className="h-3 w-3 text-red-500" />
                                                            {new Date(session.ended_at).toLocaleTimeString()}
                                                        </span>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {session.overage_seconds > 0 ? (
                                                    <span className={overageColorClass(session.overage_seconds)}>
                                                        +{formatTime(session.overage_seconds)}
                                                    </span>
                                                ) : (
                                                    '—'
                                                )}
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                {session.pause_resume_events.length > 0 ? (
                                                    <TooltipProvider>
                                                        <Tooltip>
                                                            <TooltipTrigger asChild>
                                                                <div className="flex flex-col gap-0.5 cursor-default">
                                                                    {session.pause_resume_events.map((ev, i) => (
                                                                        <span key={i} className="flex items-center gap-1 text-xs">
                                                                            {ev.action === 'pause' ? (
                                                                                <Pause className="h-3 w-3 text-amber-500" />
                                                                            ) : (
                                                                                <Play className="h-3 w-3 text-green-500" />
                                                                            )}
                                                                            {new Date(ev.occurred_at).toLocaleTimeString()}
                                                                        </span>
                                                                    ))}
                                                                </div>
                                                            </TooltipTrigger>
                                                            <TooltipContent side="left" className="max-w-xs">
                                                                <div className="space-y-1 text-xs">
                                                                    {session.pause_resume_events.map((ev, i) => (
                                                                        <div key={i}>
                                                                            <span className="font-medium capitalize">{ev.action}</span>{' '}
                                                                            at {new Date(ev.occurred_at).toLocaleTimeString()}
                                                                            {ev.reason && (
                                                                                <span className="text-muted-foreground"> — {ev.reason}</span>
                                                                            )}
                                                                        </div>
                                                                    ))}
                                                                    {session.total_paused_seconds > 0 && (
                                                                        <div className="border-t pt-1 mt-1">
                                                                            Total paused: {formatTime(session.total_paused_seconds)}
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            </TooltipContent>
                                                        </Tooltip>
                                                    </TooltipProvider>
                                                ) : (
                                                    '—'
                                                )}
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
                            No sessions found.
                        </div>
                    ) : (
                        sessions.data.map((session) => (
                            <div key={session.id} className="bg-card border rounded-lg p-4 shadow-sm space-y-3">
                                <div className="flex items-center justify-between">
                                    <span className="font-medium">
                                        {session.user
                                            ? `${session.user.first_name} ${session.user.last_name}`
                                            : '—'}
                                    </span>
                                    <Badge {...statusBadgeVariant(session.status, session.overage_seconds)}>
                                        {session.status}
                                    </Badge>
                                </div>
                                <div className="text-muted-foreground grid grid-cols-2 gap-1 text-sm">
                                    <span>Type: {formatBreakType(session.type)}</span>
                                    <span>Station: {session.station || '—'}</span>
                                </div>
                                <div className="flex flex-col gap-0.5 text-sm">
                                    <span className="flex items-center gap-1">
                                        <PlayCircle className="h-3 w-3 text-green-500" />
                                        Started: {new Date(session.started_at).toLocaleTimeString()}
                                    </span>
                                    {session.ended_at && (
                                        <span className="flex items-center gap-1">
                                            <StopCircle className="h-3 w-3 text-red-500" />
                                            Ended: {new Date(session.ended_at).toLocaleTimeString()}
                                        </span>
                                    )}
                                </div>
                                {session.overage_seconds > 0 && (
                                    <span className={`text-sm ${overageColorClass(session.overage_seconds)}`}>Overage: +{formatTime(session.overage_seconds)}</span>
                                )}
                                {session.pause_resume_events.length > 0 && (
                                    <div className="space-y-0.5 text-xs">
                                        <span className="font-medium text-muted-foreground">Pause/Resume:</span>
                                        {session.pause_resume_events.map((ev, i) => (
                                            <div key={i} className="flex items-center gap-1">
                                                {ev.action === 'pause' ? (
                                                    <Pause className="h-3 w-3 text-amber-500" />
                                                ) : (
                                                    <Play className="h-3 w-3 text-green-500" />
                                                )}
                                                <span className="capitalize">{ev.action}</span>{' '}
                                                {new Date(ev.occurred_at).toLocaleTimeString()}
                                                {ev.reason && <span className="text-muted-foreground"> — {ev.reason}</span>}
                                            </div>
                                        ))}
                                        {session.total_paused_seconds > 0 && (
                                            <div className="text-muted-foreground">Total: {formatTime(session.total_paused_seconds)}</div>
                                        )}
                                    </div>
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
