import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { usePageMeta } from '@/hooks';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { Button } from '@/components/ui/button';
import { Award, Calendar, Flame, Trophy, ArrowLeft, Target, History } from 'lucide-react';

interface BadgeTier {
    days: number;
    label: string;
    tier: 'starter' | 'bronze' | 'silver' | 'gold' | 'platinum';
}

interface NextBadge extends BadgeTier {
    days_remaining: number;
}

interface StreakSummary {
    current_streak: number;
    longest_streak: number;
    last_violation_date: string | null;
    streak_start_date: string | null;
    total_workdays_evaluated: number;
    badge: BadgeTier | null;
    next_badge: NextBadge | null;
}

interface UserSummary {
    id: number;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    role: string;
}

interface PageProps {
    user: UserSummary;
    streak: StreakSummary;
    badges: BadgeTier[];
}

const tierStyles: Record<BadgeTier['tier'], string> = {
    starter: 'bg-slate-200 text-slate-800 dark:bg-slate-700 dark:text-slate-100',
    bronze: 'bg-amber-200 text-amber-900 dark:bg-amber-900 dark:text-amber-100',
    silver: 'bg-zinc-300 text-zinc-900 dark:bg-zinc-600 dark:text-zinc-50',
    gold: 'bg-yellow-300 text-yellow-900 dark:bg-yellow-700 dark:text-yellow-50',
    platinum: 'bg-gradient-to-r from-indigo-500 to-purple-500 text-white',
};

function formatDate(value: string | null): string {
    if (!value) return '—';
    return new Date(value).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function fullName(u: UserSummary): string {
    return [u.first_name, u.middle_name ? `${u.middle_name}.` : null, u.last_name]
        .filter(Boolean)
        .join(' ');
}

export default function StreakPage({ user, streak, badges }: PageProps) {
    const name = fullName(user);
    const { title, breadcrumbs } = usePageMeta({
        title: `${name} — Tardy-Free Streak`,
        breadcrumbs: [
            { title: 'Dashboard', href: '/' },
            { title: 'Attendance Points', href: '/attendance-points' },
            { title: name, href: `/attendance-points/${user.id}` },
            { title: 'Streak' },
        ],
    });

    const progressPct = streak.next_badge
        ? Math.min(
            100,
            Math.round(
                ((streak.next_badge.days - streak.next_badge.days_remaining) /
                    streak.next_badge.days) *
                100,
            ),
        )
        : 100;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Tardy-Free Streak</h1>
                        <p className="text-muted-foreground text-sm">
                            Consecutive workdays without a non-excused attendance violation.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href={`/attendance-points/${user.id}`}>
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back to Points
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href="/attendance-points/leaderboard">
                                <Trophy className="mr-2 h-4 w-4" />
                                Leaderboard
                            </Link>
                        </Button>
                    </div>
                </div>

                {/* Headline stats */}
                <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <Card className="border-orange-200 dark:border-orange-900">
                        <CardHeader className="pb-2">
                            <CardDescription className="flex items-center gap-2">
                                <Flame className="h-4 w-4 text-orange-500" />
                                Current streak
                            </CardDescription>
                            <CardTitle className="text-4xl font-bold text-orange-600 dark:text-orange-400">
                                {streak.current_streak}
                                <span className="text-base font-normal text-muted-foreground"> days</span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-xs text-muted-foreground">
                                {streak.streak_start_date
                                    ? `Started ${formatDate(streak.streak_start_date)}`
                                    : 'No active streak yet — check in tomorrow!'}
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription className="flex items-center gap-2">
                                <Trophy className="h-4 w-4 text-yellow-500" />
                                Longest streak
                            </CardDescription>
                            <CardTitle className="text-4xl font-bold">
                                {streak.longest_streak}
                                <span className="text-base font-normal text-muted-foreground"> days</span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-xs text-muted-foreground">
                                Personal best across {streak.total_workdays_evaluated} workdays.
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription className="flex items-center gap-2">
                                <History className="h-4 w-4 text-muted-foreground" />
                                Last violation
                            </CardDescription>
                            <CardTitle className="text-2xl font-semibold">
                                {formatDate(streak.last_violation_date)}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-xs text-muted-foreground">
                                {streak.last_violation_date
                                    ? 'Most recent non-excused attendance point.'
                                    : 'Clean record — no violations on file.'}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* Current badge + progress to next */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Award className="h-5 w-5 text-purple-500" />
                            Current Badge
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {streak.badge ? (
                            <div className="flex items-center gap-3">
                                <Badge
                                    className={`${tierStyles[streak.badge.tier]} px-3 py-1 text-sm font-semibold`}
                                >
                                    {streak.badge.label}
                                </Badge>
                                <span className="text-sm text-muted-foreground">
                                    Earned at {streak.badge.days}+ days
                                </span>
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No badge yet — earn the first one at 7 consecutive tardy-free days.
                            </p>
                        )}

                        {streak.next_badge && (
                            <div className="space-y-2">
                                <div className="flex items-center justify-between text-sm">
                                    <span className="flex items-center gap-1 text-muted-foreground">
                                        <Target className="h-3.5 w-3.5" />
                                        Next: <strong>{streak.next_badge.label}</strong> at{' '}
                                        {streak.next_badge.days} days
                                    </span>
                                    <span className="font-medium">
                                        {streak.next_badge.days_remaining} days to go
                                    </span>
                                </div>
                                <Progress value={progressPct} />
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* All badge tiers */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Calendar className="h-5 w-5" />
                            All Badge Tiers
                        </CardTitle>
                        <CardDescription>
                            Reach each milestone of consecutive tardy-free workdays to unlock badges.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
                            {[...badges].reverse().map((b) => {
                                const earned = streak.current_streak >= b.days;
                                return (
                                    <div
                                        key={b.days}
                                        className={`rounded-lg border p-3 text-center transition ${earned
                                            ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-700 dark:bg-emerald-950/40'
                                            : 'border-dashed border-muted-foreground/30 opacity-60'
                                            }`}
                                    >
                                        <div className="mb-2 flex justify-center">
                                            <Badge className={`${tierStyles[b.tier]} px-2 py-0.5 text-xs`}>
                                                {b.label}
                                            </Badge>
                                        </div>
                                        <p className="text-2xl font-bold">{b.days}</p>
                                        <p className="text-xs text-muted-foreground">days</p>
                                        {earned && (
                                            <p className="mt-1 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                                                ✓ Earned
                                            </p>
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
