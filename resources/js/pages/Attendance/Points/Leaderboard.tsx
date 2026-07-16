import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import { usePageMeta } from '@/hooks';
import { useFlashMessage } from '@/hooks/use-flash-message';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Trophy, Flame, Medal, ArrowLeft, HelpCircle, UserX, RotateCcw, UserPlus, Check, X } from 'lucide-react';
import { useRole } from '@/hooks/useAuthorization';
import leaderboardRoutes from '@/routes/attendance-points/leaderboard';

interface BadgeTier {
    days: number;
    label: string;
    tier: 'starter' | 'bronze' | 'silver' | 'gold' | 'platinum';
}

interface LeaderboardRow {
    user_id: number;
    name: string;
    avatar_url: string | null;
    campaign: string | null;
    current_streak: number;
    longest_streak: number;
    badge: BadgeTier | null;
}

interface ExcludedRow {
    id: number;
    user_id: number;
    name: string;
    campaign: string | null;
    reason: string | null;
    excluded_by_name: string | null;
    excluded_at: string;
}

interface EligibleUser {
    id: number;
    name: string;
    role: string | null;
    campaign: string | null;
}

interface PageProps {
    leaderboard: LeaderboardRow[];
    limit: number;
    badges: BadgeTier[];
    canManage: boolean;
    excluded: ExcludedRow[];
    eligibleUsers: EligibleUser[];
}

const tierStyles: Record<BadgeTier['tier'], string> = {
    starter: 'bg-slate-200 text-slate-800 dark:bg-slate-700 dark:text-slate-100',
    bronze: 'bg-amber-200 text-amber-900 dark:bg-amber-900 dark:text-amber-100',
    silver: 'bg-zinc-300 text-zinc-900 dark:bg-zinc-600 dark:text-zinc-50',
    gold: 'bg-yellow-300 text-yellow-900 dark:bg-yellow-700 dark:text-yellow-50',
    platinum: 'bg-gradient-to-r from-indigo-500 to-purple-500 text-white',
};

const rankAccent: Record<number, string> = {
    1: 'text-yellow-500',
    2: 'text-zinc-400',
    3: 'text-amber-700',
};

function HowItWorks() {
    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button variant="ghost" size="icon" aria-label="How the leaderboard works" className="h-8 w-8">
                    <HelpCircle className="h-4 w-4 text-muted-foreground" />
                </Button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-96 text-sm">
                <h4 className="mb-2 font-semibold">How the Streak Leaderboard works</h4>
                <ul className="list-disc space-y-1.5 pl-4 text-muted-foreground">
                    <li>
                        <strong>Current streak</strong> = calendar days since the employee&rsquo;s
                        most recent <em>unexcused</em> attendance point (tardy, undertime,
                        absence). If they have none, it counts from their account creation
                        date.
                    </li>
                    <li>
                        <strong>Excused points are ignored</strong> &mdash; an admin-forgiven tardy
                        does not break the streak.
                    </li>
                    <li>
                        Attendance records are <strong>not required</strong>. A brand-new agent
                        with zero violations starts at day 1 and grows daily.
                    </li>
                    <li>
                        Employees on <strong>approved leave today</strong> (LOA / ML / VL) are
                        hidden in real time.
                    </li>
                    <li>
                        Admins can <strong>exclude</strong> specific users (e.g. resigned,
                        special case) &mdash; their streak data is preserved and can be restored.
                    </li>
                    <li>
                        Streaks are <strong>cached for 6 hours</strong>; new points or
                        exclusion toggles invalidate the cache immediately.
                    </li>
                    <li>
                        <strong>Badges</strong>: 7d Week Warrior &middot; 30d Month Master &middot;
                        90d Quarter Champion &middot; 180d Half-Year Hero &middot; 365d Year-Round
                        Legend.
                    </li>
                </ul>
            </PopoverContent>
        </Popover>
    );
}

export default function LeaderboardPage({ leaderboard, limit, canManage, excluded, eligibleUsers }: PageProps) {
    const { hasRole } = useRole();
    const canViewDetail = !hasRole('Agent');
    useFlashMessage();

    const { title, breadcrumbs } = usePageMeta({
        title: 'Tardy-Free Streak Leaderboard',
        breadcrumbs: [
            { title: 'Dashboard', href: '/' },
            { title: 'Attendance Points', href: '/attendance-points' },
            { title: 'Leaderboard' },
        ],
    });

    const handleLimit = (value: string) => {
        router.visit(`/attendance-points/leaderboard?limit=${value}`, {
            preserveScroll: true,
        });
    };

    const [submitting, setSubmitting] = useState(false);

    // Multi-select picker — admins choose any active employees to exclude in one go.
    const [pickerOpen, setPickerOpen] = useState(false);
    const [pickedIds, setPickedIds] = useState<Set<number>>(new Set());
    const [pickerReason, setPickerReason] = useState('');

    const openPicker = () => {
        setPickedIds(new Set());
        setPickerReason('');
        setPickerOpen(true);
    };

    const togglePicked = (id: number) => {
        setPickedIds((prev) => {
            const next = new Set(prev);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });
    };

    const submitPicker = () => {
        if (pickedIds.size === 0) return;
        setSubmitting(true);
        router.post(
            leaderboardRoutes.excludeBatch().url,
            {
                user_ids: Array.from(pickedIds),
                reason: pickerReason.trim() || null,
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    setSubmitting(false);
                    setPickerOpen(false);
                    setPickedIds(new Set());
                },
            },
        );
    };

    const selectedUsers = eligibleUsers.filter((u) => pickedIds.has(u.id));

    const restore = (row: ExcludedRow) => {
        router.delete(leaderboardRoutes.restore(row.user_id).url, {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="flex items-center text-2xl font-bold tracking-tight">
                            <Trophy className="mr-2 inline h-6 w-6 text-yellow-500" />
                            Streak Leaderboard
                            <HowItWorks />
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Top employees by consecutive tardy-free days. Updated every 6 hours.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button variant="outline" asChild>
                            <Link href="/attendance-points">
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back
                            </Link>
                        </Button>
                        <Select value={String(limit)} onValueChange={handleLimit}>
                            <SelectTrigger className="w-30">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="10">Top 10</SelectItem>
                                <SelectItem value="25">Top 25</SelectItem>
                                <SelectItem value="50">Top 50</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Current Standings</CardTitle>
                        <CardDescription>
                            {leaderboard.length === 0
                                ? 'No active streaks yet — check back once employees accrue clean days.'
                                : `Showing top ${leaderboard.length} streak${leaderboard.length === 1 ? '' : 's'}.`}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {/* Desktop table */}
                        <div className="hidden md:block">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-16">Rank</TableHead>
                                        <TableHead>Employee</TableHead>
                                        <TableHead>Campaign</TableHead>
                                        <TableHead className="text-right">Current</TableHead>
                                        <TableHead className="text-right">Longest</TableHead>
                                        <TableHead>Badge</TableHead>
                                        {canViewDetail && (
                                            <TableHead className="w-32 text-right">Actions</TableHead>
                                        )}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {leaderboard.map((row, idx) => {
                                        const rank = idx + 1;
                                        return (
                                            <TableRow key={row.user_id}>
                                                <TableCell className="font-bold">
                                                    <span className={rankAccent[rank] ?? 'text-muted-foreground'}>
                                                        {rank <= 3 ? <Medal className="inline h-4 w-4" /> : null}{' '}
                                                        #{rank}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="font-medium">
                                                    <div className="flex items-center gap-3">
                                                        <Avatar className="h-11 w-11 shrink-0">
                                                            <AvatarImage loading="lazy" src={row.avatar_url ?? undefined} alt={row.name} />
                                                            <AvatarFallback className="text-sm">
                                                                {row.name.slice(0, 2).toUpperCase()}
                                                            </AvatarFallback>
                                                        </Avatar>
                                                        <span>{row.name}</span>
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-muted-foreground text-sm">
                                                    {row.campaign ?? '—'}
                                                </TableCell>
                                                <TableCell className="text-right font-bold text-orange-600 dark:text-orange-400">
                                                    <Flame className="mr-1 inline h-3.5 w-3.5" />
                                                    {row.current_streak}
                                                </TableCell>
                                                <TableCell className="text-right text-muted-foreground">
                                                    {row.longest_streak}
                                                </TableCell>
                                                <TableCell>
                                                    {row.badge && (
                                                        <Badge className={`${tierStyles[row.badge.tier]} text-xs`}>
                                                            {row.badge.label}
                                                        </Badge>
                                                    )}
                                                </TableCell>
                                                {canViewDetail && (
                                                    <TableCell className="text-right">
                                                        <Button size="sm" variant="ghost" asChild>
                                                            <Link href={`/attendance-points/${row.user_id}/streak`}>
                                                                View
                                                            </Link>
                                                        </Button>
                                                    </TableCell>
                                                )}
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>

                        {/* Mobile cards */}
                        <div className="space-y-3 md:hidden">
                            {leaderboard.map((row, idx) => {
                                const rank = idx + 1;
                                return (
                                    <div key={row.user_id} className="rounded-lg border bg-card p-4 shadow-sm">
                                        <div className="mb-2 flex items-center justify-between">
                                            <span className={`font-bold ${rankAccent[rank] ?? 'text-muted-foreground'}`}>
                                                {rank <= 3 ? <Medal className="inline h-4 w-4" /> : null} #{rank}
                                            </span>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <Avatar className="h-12 w-12 shrink-0">
                                                <AvatarImage loading="lazy" src={row.avatar_url ?? undefined} alt={row.name} />
                                                <AvatarFallback className="text-sm">
                                                    {row.name.slice(0, 2).toUpperCase()}
                                                </AvatarFallback>
                                            </Avatar>
                                            <div>
                                                <p className="font-medium">{row.name}</p>
                                                {row.campaign && (
                                                    <p className="text-muted-foreground text-xs">{row.campaign}</p>
                                                )}
                                            </div>
                                        </div>
                                        <div className="mt-2 flex items-center justify-between">
                                            <div className="flex items-center gap-1 text-orange-600 dark:text-orange-400">
                                                <Flame className="h-4 w-4" />
                                                <span className="text-2xl font-bold">{row.current_streak}</span>
                                                <span className="text-muted-foreground text-xs">days</span>
                                            </div>
                                            {row.badge && (
                                                <Badge className={`${tierStyles[row.badge.tier]} text-xs`}>
                                                    {row.badge.label}
                                                </Badge>
                                            )}
                                        </div>
                                        {canViewDetail && (
                                            <Button size="sm" variant="outline" className="mt-3 w-full" asChild>
                                                <Link href={`/attendance-points/${row.user_id}/streak`}>
                                                    View detail
                                                </Link>
                                            </Button>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>

                {canManage && (
                    <Card>
                        <CardHeader>
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <CardTitle className="flex items-center gap-2">
                                        <UserX className="h-5 w-5 text-red-600" />
                                        Excluded Employees
                                    </CardTitle>
                                    <CardDescription>
                                        {excluded.length === 0
                                            ? 'No employees are currently excluded from the leaderboard.'
                                            : `${excluded.length} employee${excluded.length === 1 ? '' : 's'} hidden from public rankings. Restore at any time.`}
                                    </CardDescription>
                                </div>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={openPicker}
                                    disabled={eligibleUsers.length === 0}
                                    title={eligibleUsers.length === 0 ? 'All active users are already excluded.' : undefined}
                                >
                                    <UserPlus className="mr-1 h-4 w-4" />
                                    Exclude Employee
                                </Button>
                            </div>
                        </CardHeader>
                        {excluded.length > 0 && (
                            <CardContent>
                                <div className="hidden md:block">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Employee</TableHead>
                                                <TableHead>Campaign</TableHead>
                                                <TableHead>Reason</TableHead>
                                                <TableHead>Excluded By</TableHead>
                                                <TableHead className="text-right">Actions</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {excluded.map((row) => (
                                                <TableRow key={row.id}>
                                                    <TableCell className="font-medium">{row.name}</TableCell>
                                                    <TableCell className="text-muted-foreground text-sm">
                                                        {row.campaign ?? '—'}
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground text-sm">
                                                        {row.reason ?? '—'}
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground text-sm">
                                                        {row.excluded_by_name ?? '—'}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() => restore(row)}
                                                        >
                                                            <RotateCcw className="mr-1 h-3.5 w-3.5" />
                                                            Restore
                                                        </Button>
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>

                                <div className="space-y-3 md:hidden">
                                    {excluded.map((row) => (
                                        <div key={row.id} className="rounded-lg border bg-card p-4 shadow-sm">
                                            <p className="font-medium">{row.name}</p>
                                            {row.campaign && (
                                                <p className="text-muted-foreground text-xs">{row.campaign}</p>
                                            )}
                                            {row.reason && (
                                                <p className="mt-2 text-sm">
                                                    <span className="text-muted-foreground">Reason:</span> {row.reason}
                                                </p>
                                            )}
                                            {row.excluded_by_name && (
                                                <p className="text-muted-foreground mt-1 text-xs">
                                                    By {row.excluded_by_name}
                                                </p>
                                            )}
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                className="mt-3 w-full"
                                                onClick={() => restore(row)}
                                            >
                                                <RotateCcw className="mr-1 h-3.5 w-3.5" />
                                                Restore
                                            </Button>
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        )}
                    </Card>
                )}
            </div>

            {canManage && (
                <Dialog open={pickerOpen} onOpenChange={(open) => !open && setPickerOpen(false)}>
                    <DialogContent className="max-w-lg">
                        <DialogHeader>
                            <DialogTitle>Exclude an employee</DialogTitle>
                            <DialogDescription>
                                Search and select one or more active employees to hide from the leaderboard.
                                Their streak data is preserved and can be restored at any time.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-3 py-2">
                            <div className="rounded-md border">
                                <Command>
                                    <CommandInput placeholder="Search by name…" />
                                    <CommandList>
                                        <CommandEmpty>No employees found.</CommandEmpty>
                                        <CommandGroup>
                                            {eligibleUsers.map((u) => {
                                                const checked = pickedIds.has(u.id);
                                                return (
                                                    <CommandItem
                                                        key={u.id}
                                                        value={`${u.name} ${u.role ?? ''} ${u.campaign ?? ''}`}
                                                        onSelect={() => togglePicked(u.id)}
                                                    >
                                                        <div className="flex w-full items-center justify-between gap-2">
                                                            <div className="flex min-w-0 flex-1 items-center gap-2">
                                                                <div
                                                                    className={`flex h-4 w-4 shrink-0 items-center justify-center rounded border ${checked
                                                                        ? 'border-green-600 bg-green-600 text-white'
                                                                        : 'border-muted-foreground/40'
                                                                        }`}
                                                                    aria-hidden
                                                                >
                                                                    {checked && <Check className="h-3 w-3" />}
                                                                </div>
                                                                <div className="min-w-0">
                                                                    <p className="truncate text-sm font-medium">{u.name}</p>
                                                                    <p className="text-muted-foreground truncate text-xs">
                                                                        {[u.role, u.campaign].filter(Boolean).join(' · ') || '—'}
                                                                    </p>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </CommandItem>
                                                );
                                            })}
                                        </CommandGroup>
                                    </CommandList>
                                </Command>
                            </div>

                            {selectedUsers.length > 0 && (
                                <div className="rounded-md border border-orange-200 bg-orange-50 p-3 dark:border-orange-900 dark:bg-orange-950">
                                    <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-orange-900 dark:text-orange-100">
                                        Selected ({selectedUsers.length})
                                    </p>
                                    <div className="flex flex-wrap gap-1.5">
                                        {selectedUsers.map((u) => (
                                            <Badge
                                                key={u.id}
                                                variant="secondary"
                                                className="flex items-center gap-1 pr-1"
                                            >
                                                {u.name}
                                                <button
                                                    type="button"
                                                    onClick={() => togglePicked(u.id)}
                                                    className="ml-0.5 rounded-sm p-0.5 hover:bg-muted-foreground/20"
                                                    aria-label={`Remove ${u.name}`}
                                                >
                                                    <X className="h-3 w-3" />
                                                </button>
                                            </Badge>
                                        ))}
                                    </div>
                                </div>
                            )}

                            <div className="space-y-2">
                                <Label htmlFor="picker-reason">Reason (optional, applied to all selected)</Label>
                                <Textarea
                                    id="picker-reason"
                                    placeholder="e.g. Resigned, on extended leave, special case…"
                                    value={pickerReason}
                                    onChange={(e) => setPickerReason(e.target.value)}
                                    maxLength={255}
                                    rows={2}
                                />
                            </div>
                        </div>
                        <DialogFooter>
                            <Button variant="outline" onClick={() => setPickerOpen(false)} disabled={submitting}>
                                Cancel
                            </Button>
                            <Button onClick={submitPicker} disabled={submitting || pickedIds.size === 0}>
                                {submitting
                                    ? 'Excluding…'
                                    : pickedIds.size === 0
                                        ? 'Exclude Selected'
                                        : `Exclude ${pickedIds.size} Employee${pickedIds.size === 1 ? '' : 's'}`}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            )}
        </AppLayout>
    );
}
