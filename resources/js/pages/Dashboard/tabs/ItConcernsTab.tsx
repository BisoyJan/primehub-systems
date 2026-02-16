import React, { useState } from 'react';
import { motion } from 'framer-motion';
import { router } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ChartContainer, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, XAxis, YAxis } from 'recharts';
import {
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    ClipboardList,
    Clock,
    Loader2,
} from 'lucide-react';
import { StatCard } from '../components/StatCard';
import { DetailDialog } from '../components/DetailDialog';
import type { DashboardProps } from '../types';

export interface ItConcernsTabProps {
    itConcernStats: DashboardProps['itConcernStats'];
    itConcernTrends: DashboardProps['itConcernTrends'];
}

const CONCERN_STATUS_CONFIG = [
    { key: 'pending', label: 'Pending' },
    { key: 'in_progress', label: 'In Progress' },
    { key: 'resolved', label: 'Resolved' },
];

const IT_TREND_SLIDES = [
    { key: 'total', label: 'All Concerns Trend', description: 'Overview of all IT concerns over time', color: 'hsl(280, 65%, 60%)' },
    { key: 'pending', label: 'Pending Trend', description: 'Monitors new issues awaiting action', color: 'hsl(45, 93%, 47%)' },
    { key: 'in_progress', label: 'In-Progress Trend', description: 'Tracks workload currently being handled', color: 'hsl(221, 83%, 53%)' },
    { key: 'resolved', label: 'Resolved Trend', description: 'Measures closure rate per month', color: 'hsl(142, 71%, 45%)' },
];

export const ItConcernsTab: React.FC<ItConcernsTabProps> = ({
    itConcernStats,
    itConcernTrends,
}) => {
    const [activeDialog, setActiveDialog] = useState<string | null>(null);
    const [itTrendSlideIndex, setItTrendSlideIndex] = useState(0);

    const concernStats = itConcernStats ?? { pending: 0, in_progress: 0, resolved: 0 };
    const concernBreakdown = itConcernStats?.bySite ?? [];
    const totalConcerns = concernStats.pending + concernStats.in_progress + concernStats.resolved;
    const itTrends = itConcernTrends ?? [];
    const activeItTrendSlide = IT_TREND_SLIDES[itTrendSlideIndex];
    const latestItTrend = itTrends.length > 0 ? itTrends[itTrends.length - 1] : null;

    const goToItConcerns = (status?: 'pending' | 'in_progress' | 'resolved') => {
        const params = status ? { status } : {};
        router.get('/form-requests/it-concerns', params);
    };

    const handleTrendPrev = () => {
        setItTrendSlideIndex((prev) => (prev === 0 ? IT_TREND_SLIDES.length - 1 : prev - 1));
    };

    const handleTrendNext = () => {
        setItTrendSlideIndex((prev) => (prev === IT_TREND_SLIDES.length - 1 ? 0 : prev + 1));
    };

    const closeDialog = () => setActiveDialog(null);

    return (
        <>
            <motion.div
                initial={{ opacity: 0, y: 20 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.3 }}
            >
                {/* IT Concerns Summary Cards */}
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4 mb-6">
                    <StatCard
                        title="Total Concerns"
                        value={totalConcerns}
                        icon={ClipboardList}
                        description="Click for per-site breakdown"
                        onClick={() => setActiveDialog('itConcernsBySite')}
                        variant={totalConcerns > 0 ? 'success' : 'default'}
                    />
                    <StatCard
                        title="Pending"
                        value={concernStats.pending}
                        icon={Clock}
                        description="Awaiting acknowledgement"
                        onClick={() => goToItConcerns('pending')}
                        variant={concernStats.pending > 0 ? 'warning' : 'default'}
                    />
                    <StatCard
                        title="In Progress"
                        value={concernStats.in_progress}
                        icon={Loader2}
                        description="Currently being handled"
                        onClick={() => goToItConcerns('in_progress')}
                    />
                    <StatCard
                        title="Resolved"
                        value={concernStats.resolved}
                        icon={CheckCircle2}
                        description="Closed this period"
                        onClick={() => goToItConcerns('resolved')}
                        variant="success"
                    />
                </div>

                {/* IT Concerns Trend Chart */}
                <Card>
                    <CardHeader className="pb-0">
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <CardTitle className="text-base">IT Concern Trends</CardTitle>
                                <CardDescription className="text-xs">
                                    {activeItTrendSlide.description}
                                </CardDescription>
                            </div>
                            <div className="flex items-center gap-2">
                                <div className="text-sm font-medium">
                                    {activeItTrendSlide.label}
                                </div>
                                <div className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        onClick={handleTrendPrev}
                                        className="rounded-full border px-2 py-1 text-xs hover:bg-muted"
                                        aria-label="Previous trend"
                                    >
                                        <ChevronLeft className="h-4 w-4" />
                                    </button>
                                    <button
                                        type="button"
                                        onClick={handleTrendNext}
                                        className="rounded-full border px-2 py-1 text-xs hover:bg-muted"
                                        aria-label="Next trend"
                                    >
                                        <ChevronRight className="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="pt-4">
                        {itTrends.length === 0 ? (
                            <div className="py-10 text-center text-muted-foreground">
                                No IT concern activity recorded for the selected window.
                            </div>
                        ) : (
                            <>
                                <ChartContainer
                                    config={{
                                        [activeItTrendSlide.key]: {
                                            label: activeItTrendSlide.label,
                                            color: activeItTrendSlide.color,
                                        }
                                    }}
                                    className="h-[320px] w-full"
                                >
                                    <ResponsiveContainer width="100%" height="100%">
                                        <AreaChart data={itTrends} margin={{ left: 10, right: 10 }}>
                                            <defs>
                                                <linearGradient id={`it-trend-dedicated-${activeItTrendSlide.key}`} x1="0" y1="0" x2="0" y2="1">
                                                    <stop offset="5%" stopColor={activeItTrendSlide.color} stopOpacity={0.8} />
                                                    <stop offset="95%" stopColor={activeItTrendSlide.color} stopOpacity={0.05} />
                                                </linearGradient>
                                            </defs>
                                            <CartesianGrid strokeDasharray="3 3" />
                                            <XAxis dataKey="label" tickLine={false} axisLine={false} fontSize={12} interval="preserveStartEnd" />
                                            <YAxis allowDecimals={false} width={40} tickLine={false} axisLine={false} fontSize={12} />
                                            <ChartTooltip
                                                cursor={false}
                                                content={<ChartTooltipContent />}
                                            />
                                            <Area
                                                type="monotone"
                                                dataKey={activeItTrendSlide.key as 'total' | 'pending' | 'in_progress' | 'resolved'}
                                                stroke={activeItTrendSlide.color}
                                                fill={`url(#it-trend-dedicated-${activeItTrendSlide.key})`}
                                                strokeWidth={2}
                                                activeDot={{ r: 5 }}
                                            />
                                        </AreaChart>
                                    </ResponsiveContainer>
                                </ChartContainer>
                                <div className="mt-4 flex flex-wrap items-center justify-between gap-4 text-sm">
                                    <div>
                                        <p className="text-muted-foreground text-xs uppercase">Latest Month</p>
                                        <p className="font-semibold">{latestItTrend?.label ?? 'N/A'}</p>
                                    </div>
                                    <div className="flex flex-wrap gap-4">
                                        <div>
                                            <p className="text-muted-foreground text-xs uppercase">Total</p>
                                            <p className="font-semibold">{latestItTrend?.total ?? 0}</p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground text-xs uppercase">Pending</p>
                                            <p className="font-semibold">{latestItTrend?.pending ?? 0}</p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground text-xs uppercase">In Progress</p>
                                            <p className="font-semibold">{latestItTrend?.in_progress ?? 0}</p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground text-xs uppercase">Resolved</p>
                                            <p className="font-semibold">{latestItTrend?.resolved ?? 0}</p>
                                        </div>
                                    </div>
                                </div>
                            </>
                        )}
                    </CardContent>
                </Card>
            </motion.div>

            {/* ─── Dialog ─────────────────────────────────────────────── */}

            <DetailDialog
                open={activeDialog === 'itConcernsBySite'}
                onClose={closeDialog}
                title="IT Concerns by Site"
                description="Pending, in-progress, and resolved concerns grouped per site"
            >
                {concernBreakdown.length === 0 ? (
                    <div className="py-6 text-center text-muted-foreground">
                        No IT concerns recorded yet.
                    </div>
                ) : (
                    <div className="space-y-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <p className="text-sm text-muted-foreground">
                                Totals reflect all concern statuses with site-specific counts.
                            </p>
                            <button
                                className="text-sm font-medium text-primary underline"
                                onClick={() => goToItConcerns()}
                            >
                                View IT Concerns List
                            </button>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full border text-sm">
                                <thead>
                                    <tr className="bg-muted">
                                        <th className="px-3 py-2 text-left font-semibold">IT Concerns</th>
                                        {concernBreakdown.map((site) => (
                                            <th key={site.site} className="px-3 py-2 text-left font-semibold whitespace-nowrap">
                                                {site.site}
                                            </th>
                                        ))}
                                        <th className="px-3 py-2 text-left font-semibold">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {CONCERN_STATUS_CONFIG.map((status) => (
                                        <tr key={status.key} className="border-t">
                                            <td className="px-3 py-2 font-medium">{status.label}</td>
                                            {concernBreakdown.map((site) => (
                                                <td
                                                    key={`${site.site}-${status.key}`}
                                                    className="px-3 py-2"
                                                >
                                                    {site[status.key as 'pending' | 'in_progress' | 'resolved']}
                                                </td>
                                            ))}
                                            <td className="px-3 py-2 font-semibold">
                                                {status.key === 'pending'
                                                    ? concernStats.pending
                                                    : status.key === 'in_progress'
                                                        ? concernStats.in_progress
                                                        : concernStats.resolved}
                                            </td>
                                        </tr>
                                    ))}
                                    <tr className="border-t bg-muted/50">
                                        <td className="px-3 py-2 font-semibold">Total</td>
                                        {concernBreakdown.map((site) => (
                                            <td key={`${site.site}-total`} className="px-3 py-2 font-semibold">
                                                {site.total}
                                            </td>
                                        ))}
                                        <td className="px-3 py-2 font-bold">{totalConcerns}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </DetailDialog>
        </>
    );
};
