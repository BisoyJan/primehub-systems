import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { usePageMeta } from '@/hooks';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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
import { Trophy, Flame, Medal, ArrowLeft } from 'lucide-react';
import { useRole } from '@/hooks/useAuthorization';

interface BadgeTier {
    days: number;
    label: string;
    tier: 'starter' | 'bronze' | 'silver' | 'gold' | 'platinum';
}

interface LeaderboardRow {
    user_id: number;
    name: string;
    campaign: string | null;
    current_streak: number;
    longest_streak: number;
    badge: BadgeTier | null;
}

interface PageProps {
    leaderboard: LeaderboardRow[];
    limit: number;
    badges: BadgeTier[];
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

export default function LeaderboardPage({ leaderboard, limit }: PageProps) {
    const { hasRole } = useRole();
    const canViewDetail = !hasRole('Agent');
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

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">
                            <Trophy className="mr-2 inline h-6 w-6 text-yellow-500" />
                            Streak Leaderboard
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Top employees by consecutive tardy-free workdays. Updated every 6 hours.
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
                            <SelectTrigger className="w-[120px]">
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
                                        {canViewDetail && <TableHead className="w-24 text-right"></TableHead>}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {leaderboard.map((row, idx) => {
                                        const rank = idx + 1;
                                        return (
                                            <TableRow key={row.user_id}>
                                                <TableCell className="font-bold">
                                                    <span
                                                        className={
                                                            rankAccent[rank] ?? 'text-muted-foreground'
                                                        }
                                                    >
                                                        {rank <= 3 ? (
                                                            <Medal className="inline h-4 w-4" />
                                                        ) : null}{' '}
                                                        #{rank}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="font-medium">{row.name}</TableCell>
                                                <TableCell className="text-muted-foreground text-sm">{row.campaign ?? '—'}</TableCell>
                                                <TableCell className="text-right font-bold text-orange-600 dark:text-orange-400">
                                                    <Flame className="mr-1 inline h-3.5 w-3.5" />
                                                    {row.current_streak}
                                                </TableCell>
                                                <TableCell className="text-right text-muted-foreground">
                                                    {row.longest_streak}
                                                </TableCell>
                                                <TableCell>
                                                    {row.badge && (
                                                        <Badge
                                                            className={`${tierStyles[row.badge.tier]} text-xs`}
                                                        >
                                                            {row.badge.label}
                                                        </Badge>
                                                    )}
                                                </TableCell>
                                                {canViewDetail && (
                                                    <TableCell className="text-right">
                                                        <Button size="sm" variant="ghost" asChild>
                                                            <Link
                                                                href={`/attendance-points/${row.user_id}/streak`}
                                                            >
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
                                    <div
                                        key={row.user_id}
                                        className="rounded-lg border bg-card p-4 shadow-sm"
                                    >
                                        <div className="mb-2 flex items-center justify-between">
                                            <span
                                                className={`font-bold ${rankAccent[rank] ?? 'text-muted-foreground'}`}
                                            >
                                                {rank <= 3 ? (
                                                    <Medal className="inline h-4 w-4" />
                                                ) : null}{' '}
                                                #{rank}
                                            </span>
                                        </div>
                                        <p className="font-medium">{row.name}</p>
                                        {row.campaign && (
                                            <p className="text-muted-foreground text-xs">{row.campaign}</p>
                                        )}
                                        <div className="mt-2 flex items-center justify-between">
                                            <div className="flex items-center gap-1 text-orange-600 dark:text-orange-400">
                                                <Flame className="h-4 w-4" />
                                                <span className="text-2xl font-bold">
                                                    {row.current_streak}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    days
                                                </span>
                                            </div>
                                            {row.badge && (
                                                <Badge
                                                    className={`${tierStyles[row.badge.tier]} text-xs`}
                                                >
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
            </div>
        </AppLayout>
    );
}
