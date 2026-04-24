import { Head, usePage, router } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import PaginationNav, { PaginationLink } from '@/components/pagination-nav';
import { usePageMeta, useFlashMessage, usePageLoading } from '@/hooks';
import { usePermission } from '@/hooks/use-permission';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { PageHeader } from '@/components/PageHeader';
import { DatePicker } from '@/components/ui/date-picker';
import { dashboard as dashboardRoute } from '@/routes/break-timer';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { Users, Activity, CheckCircle, AlertTriangle, Clock, ChevronsUpDown, Check, Pause, Play, PlayCircle, StopCircle, Square, RotateCcw, History } from 'lucide-react';
import { SessionTimelineDialog } from './components/SessionTimelineDialog';
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
    campaign: string | null;
    station: string | null;
    type: string;
    status: string;
    duration_seconds: number;
    started_at: string;
    ended_at: string | null;
    expected_end_at: string | null;
    remaining_seconds: number | null;
    overage_seconds: number;
    live_overage_seconds: number;
    is_overbreak_now: boolean;
    total_paused_seconds: number;
    last_pause_reason: string | null;
    pause_resume_events: PauseResumeEvent[];
}

interface Stats {
    total_sessions: number;
    active_now: number;
    currently_overbreak: number;
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
    campaign_id: string;
}

interface UserOption {
    id: number;
    first_name: string;
    last_name: string;
}

interface CampaignOption {
    id: number;
    name: string;
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
    campaigns: CampaignOption[];
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

function formatClockTime(value: string | null): string {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleTimeString([], {
        hour: 'numeric',
        minute: '2-digit',
    });
}

function getDisplayedOverageSeconds(session: SessionData): number {
    return Math.max(session.overage_seconds, session.live_overage_seconds);
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
    const { sessions, stats, filters, users, campaigns } = usePage<PageProps>().props;
    const { can } = usePermission();
    const canForceEnd = can('break_timer.force_end');
    const canRestore = can('break_timer.restore');
    const canActOnSessions = canForceEnd || canRestore;

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
    const [campaignId, setCampaignId] = useState(filters.campaign_id);
    const [showUserSearch, setShowUserSearch] = useState(false);
    const [userSearchQuery, setUserSearchQuery] = useState('');

    const [actionDialog, setActionDialog] = useState<{ kind: 'force_end' | 'restore'; session: SessionData } | null>(null);
    const [actionReason, setActionReason] = useState('');
    const [isSubmittingAction, setIsSubmittingAction] = useState(false);
    const [timelineSessionId, setTimelineSessionId] = useState<number | null>(null);

    function openForceEnd(session: SessionData) {
        setActionReason('');
        setActionDialog({ kind: 'force_end', session });
    }

    function openRestore(session: SessionData) {
        setActionReason('');
        setActionDialog({ kind: 'restore', session });
    }

    function closeActionDialog() {
        if (isSubmittingAction) return;
        setActionDialog(null);
        setActionReason('');
    }

    function submitAction() {
        if (!actionDialog) return;
        const reason = actionReason.trim();
        if (reason.length < 3) return;

        const url = actionDialog.kind === 'force_end'
            ? `/break-timer/${actionDialog.session.id}/force-end`
            : `/break-timer/${actionDialog.session.id}/restore`;

        setIsSubmittingAction(true);
        router.post(url, { reason }, {
            preserveScroll: true,
            onFinish: () => {
                setIsSubmittingAction(false);
                setActionDialog(null);
                setActionReason('');
            },
        });
    }

    // Auto-refresh every 30 seconds for live monitoring
    const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const isPollingRef = useRef(false);
    useEffect(() => {
        pollRef.current = setInterval(() => {
            if (isPollingRef.current) return;
            isPollingRef.current = true;
            router.reload({
                only: ['sessions', 'stats'],
                onFinish: () => { isPollingRef.current = false; },
            });
        }, 15000);
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
            { date, status: status || undefined, type: type || undefined, user_id: userId || undefined, campaign_id: campaignId || undefined },
            { preserveState: true, preserveScroll: true },
        );
    }

    function resetFilters() {
        setDate(new Date().toISOString().split('T')[0]);
        setStatus('');
        setType('');
        setUserId('');
        setCampaignId('');
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
                <div className="grid grid-cols-2 gap-3 md:grid-cols-6">
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
                            <AlertTriangle className="h-7 w-7 text-red-600" />
                            <div>
                                <p className="text-muted-foreground text-xs">Currently Overbreak</p>
                                <p className="text-xl font-bold leading-tight text-red-600 dark:text-red-400">{stats.currently_overbreak}</p>
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
                        <div className="w-full sm:w-auto flex-1 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
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
                            <Select value={campaignId || 'all'} onValueChange={(v) => setCampaignId(v === 'all' ? '' : v)}>
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="All Campaigns" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Campaigns</SelectItem>
                                    {campaigns.map((c) => (
                                        <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                                    ))}
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
                                    <TableHead>Campaign</TableHead>
                                    <TableHead>Station</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Timeline</TableHead>
                                    <TableHead>Expected End</TableHead>
                                    <TableHead>Overage</TableHead>
                                    <TableHead>Paused / Resumed</TableHead>
                                    {canActOnSessions && <TableHead className="text-right">Actions</TableHead>}
                                    {!canActOnSessions && <TableHead className="text-right">Timeline</TableHead>}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {sessions.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={canActOnSessions ? 10 : 9} className="h-24 text-center text-muted-foreground">
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
                                            <TableCell>{session.campaign || '—'}</TableCell>
                                            <TableCell>{session.station || '—'}</TableCell>
                                            <TableCell>{formatBreakType(session.type)}</TableCell>
                                            <TableCell>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <Badge {...statusBadgeVariant(session.status, session.overage_seconds)}>
                                                        {session.status}
                                                    </Badge>
                                                    {session.is_overbreak_now && (
                                                        <Badge variant="destructive">Overbreak</Badge>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                <div className="flex flex-col gap-0.5">
                                                    <span className="flex items-center gap-1 text-xs">
                                                        <PlayCircle className="h-3 w-3 text-green-500" />
                                                        {formatClockTime(session.started_at)}
                                                    </span>
                                                    {session.ended_at && (
                                                        <span className="flex items-center gap-1 text-xs">
                                                            <StopCircle className="h-3 w-3 text-red-500" />
                                                            {formatClockTime(session.ended_at)}
                                                        </span>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <span className={session.is_overbreak_now ? 'font-medium text-red-600 dark:text-red-400' : 'text-sm text-muted-foreground'}>
                                                    {formatClockTime(session.expected_end_at)}
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                {getDisplayedOverageSeconds(session) > 0 ? (
                                                    <div className="flex flex-col gap-1">
                                                        <span className={overageColorClass(getDisplayedOverageSeconds(session))}>
                                                            +{formatTime(getDisplayedOverageSeconds(session))}
                                                        </span>
                                                        {session.is_overbreak_now && (
                                                            <span className="text-xs font-medium uppercase tracking-wide text-red-600 dark:text-red-400">
                                                                Live
                                                            </span>
                                                        )}
                                                    </div>
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
                                                                            {formatClockTime(ev.occurred_at)}
                                                                        </span>
                                                                    ))}
                                                                </div>
                                                            </TooltipTrigger>
                                                            <TooltipContent side="left" className="max-w-xs">
                                                                <div className="space-y-1 text-xs">
                                                                    {session.pause_resume_events.map((ev, i) => (
                                                                        <div key={i}>
                                                                            <span className="font-medium capitalize">{ev.action}</span>{' '}
                                                                            at {formatClockTime(ev.occurred_at)}
                                                                            {ev.reason && (
                                                                                <span className="text-muted-foreground"> — {ev.reason}</span>
                                                                            )}
                                                                        </div>
                                                                    ))}
                                                                    {session.total_paused_seconds > 0 && (
                                                                        <div className="mt-1 border-t pt-1">
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
                                            {canActOnSessions ? (
                                                <TableCell className="text-right">
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() => setTimelineSessionId(session.id)}
                                                        className="mr-1"
                                                        title="View timeline"
                                                    >
                                                        <History className="h-3 w-3" />
                                                    </Button>
                                                    {canForceEnd && (session.status === 'active' || session.status === 'paused') && (
                                                        <Button
                                                            size="sm"
                                                            variant="destructive"
                                                            onClick={() => openForceEnd(session)}
                                                            className="mr-2 gap-1"
                                                        >
                                                            <Square className="h-3 w-3" />
                                                            Force End
                                                        </Button>
                                                    )}
                                                    {canRestore
                                                        && ['completed', 'overage', 'reset', 'auto_ended'].includes(session.status)
                                                        && (session.remaining_seconds ?? 0) >= 30 && (
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() => openRestore(session)}
                                                                className="gap-1"
                                                            >
                                                                <RotateCcw className="h-3 w-3" />
                                                                Restore
                                                            </Button>
                                                        )}
                                                </TableCell>
                                            ) : (
                                                <TableCell className="text-right">
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() => setTimelineSessionId(session.id)}
                                                        title="View timeline"
                                                    >
                                                        <History className="h-4 w-4" />
                                                    </Button>
                                                </TableCell>
                                            )}
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
                        <div className="border rounded-lg bg-card py-12 text-center text-muted-foreground">
                            No sessions found.
                        </div>
                    ) : (
                        sessions.data.map((session) => (
                            <div key={session.id} className="bg-card border rounded-lg p-4 shadow-sm space-y-3">
                                <div className="flex items-center justify-between gap-2">
                                    <span className="font-medium">
                                        {session.user
                                            ? `${session.user.first_name} ${session.user.last_name}`
                                            : '—'}
                                    </span>
                                    <div className="flex flex-wrap items-center justify-end gap-2">
                                        <Badge {...statusBadgeVariant(session.status, session.overage_seconds)}>
                                            {session.status}
                                        </Badge>
                                        {session.is_overbreak_now && (
                                            <Badge variant="destructive">Overbreak</Badge>
                                        )}
                                    </div>
                                </div>
                                <div className="text-muted-foreground grid grid-cols-2 gap-1 text-sm">
                                    <span>Type: {formatBreakType(session.type)}</span>
                                    <span>Station: {session.station || '—'}</span>
                                    <span>Campaign: {session.campaign || '—'}</span>
                                    <span>Expected End: {formatClockTime(session.expected_end_at)}</span>
                                </div>
                                <div className="flex flex-col gap-0.5 text-sm">
                                    <span className="flex items-center gap-1">
                                        <PlayCircle className="h-3 w-3 text-green-500" />
                                        Started: {formatClockTime(session.started_at)}
                                    </span>
                                    {session.ended_at && (
                                        <span className="flex items-center gap-1">
                                            <StopCircle className="h-3 w-3 text-red-500" />
                                            Ended: {formatClockTime(session.ended_at)}
                                        </span>
                                    )}
                                </div>
                                {getDisplayedOverageSeconds(session) > 0 && (
                                    <div className="flex items-center gap-2 text-sm">
                                        <span className={overageColorClass(getDisplayedOverageSeconds(session))}>
                                            Overage: +{formatTime(getDisplayedOverageSeconds(session))}
                                        </span>
                                        {session.is_overbreak_now && (
                                            <span className="font-medium uppercase tracking-wide text-red-600 dark:text-red-400">
                                                Live
                                            </span>
                                        )}
                                    </div>
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
                                                {formatClockTime(ev.occurred_at)}
                                                {ev.reason && <span className="text-muted-foreground"> — {ev.reason}</span>}
                                            </div>
                                        ))}
                                        {session.total_paused_seconds > 0 && (
                                            <div className="text-muted-foreground">Total: {formatTime(session.total_paused_seconds)}</div>
                                        )}
                                    </div>
                                )}
                                <div className="flex flex-wrap gap-2 pt-2 border-t">
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => setTimelineSessionId(session.id)}
                                        className="flex-1 gap-1"
                                    >
                                        <History className="h-3 w-3" />
                                        Timeline
                                    </Button>
                                    {canForceEnd && (session.status === 'active' || session.status === 'paused') && (
                                        <Button
                                            size="sm"
                                            variant="destructive"
                                            onClick={() => openForceEnd(session)}
                                            className="flex-1 gap-1"
                                        >
                                            <Square className="h-3 w-3" />
                                            Force End
                                        </Button>
                                    )}
                                    {canRestore
                                        && ['completed', 'overage', 'reset', 'auto_ended'].includes(session.status)
                                        && (session.remaining_seconds ?? 0) >= 30 && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => openRestore(session)}
                                                className="flex-1 gap-1"
                                            >
                                                <RotateCcw className="h-3 w-3" />
                                                Restore
                                            </Button>
                                        )}
                                </div>
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

            <Dialog open={!!actionDialog} onOpenChange={(open) => { if (!open) closeActionDialog(); }}>
                <DialogContent className="max-w-[90vw] sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {actionDialog?.kind === 'force_end' ? 'Force End Break Session' : 'Restore Break Session'}
                        </DialogTitle>
                        <DialogDescription>
                            {actionDialog?.kind === 'force_end'
                                ? 'End this session on behalf of the agent (e.g., they forgot to end before their shift ended). This action is logged.'
                                : 'Restore this previously ended session. The agent will get back the remaining time. This action is logged.'}
                        </DialogDescription>
                    </DialogHeader>

                    {actionDialog && (
                        <div className="space-y-3 text-sm">
                            <div className="rounded-md bg-muted p-3 space-y-1">
                                <div><span className="text-muted-foreground">Agent:</span> {actionDialog.session.user ? `${actionDialog.session.user.first_name} ${actionDialog.session.user.last_name}` : '—'}</div>
                                <div><span className="text-muted-foreground">Type:</span> {formatBreakType(actionDialog.session.type)}</div>
                                <div><span className="text-muted-foreground">Status:</span> {actionDialog.session.status}</div>
                                {actionDialog.kind === 'restore' && (
                                    <div><span className="text-muted-foreground">Remaining to restore:</span> {formatTime(actionDialog.session.remaining_seconds ?? 0)}</div>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="action-reason">
                                    Reason <span className="text-red-500">*</span>
                                </Label>
                                <Textarea
                                    id="action-reason"
                                    value={actionReason}
                                    onChange={(e) => setActionReason(e.target.value)}
                                    placeholder={actionDialog.kind === 'force_end'
                                        ? 'e.g., Agent logged off without ending break.'
                                        : 'e.g., Break was accidentally ended; agent had ~10 min remaining.'}
                                    maxLength={500}
                                    rows={3}
                                    disabled={isSubmittingAction}
                                />
                                <p className="text-xs text-muted-foreground">{actionReason.length}/500</p>
                            </div>
                        </div>
                    )}

                    <DialogFooter className="gap-2">
                        <Button variant="outline" onClick={closeActionDialog} disabled={isSubmittingAction}>
                            Cancel
                        </Button>
                        <Button
                            variant={actionDialog?.kind === 'force_end' ? 'destructive' : 'default'}
                            onClick={submitAction}
                            disabled={isSubmittingAction || actionReason.trim().length < 3}
                        >
                            {isSubmittingAction
                                ? 'Submitting...'
                                : actionDialog?.kind === 'force_end' ? 'Force End' : 'Restore'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <SessionTimelineDialog
                open={timelineSessionId !== null}
                onOpenChange={(open) => !open && setTimelineSessionId(null)}
                sessionId={timelineSessionId}
            />
        </AppLayout>
    );
}
