import React, { useState } from 'react';
import { motion } from 'framer-motion';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { ChartContainer, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';
import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from 'recharts';
import { Server, HardDrive, Monitor, Cpu, Package } from 'lucide-react';
import { StatCard } from '../components/StatCard';
import { DetailDialog } from '../components/DetailDialog';
import type { StockSummary, StockSummaryItem } from '../types';

export interface StockOverviewTabProps {
    stockSummary?: StockSummary;
}

const STOCK_CONFIG: Record<string, { label: string; icon: React.ComponentType<{ className?: string }>; color: string }> = {
    ram: { label: 'RAM', icon: Server, color: 'hsl(221, 83%, 53%)' },
    disk: { label: 'Disk', icon: HardDrive, color: 'hsl(142, 71%, 45%)' },
    monitor: { label: 'Monitor', icon: Monitor, color: 'hsl(280, 65%, 60%)' },
    processor: { label: 'Processor', icon: Cpu, color: 'hsl(45, 93%, 47%)' },
    other: { label: 'Other', icon: Package, color: 'hsl(25, 95%, 53%)' },
};

const chartConfig = {
    total: { label: 'Total', color: 'hsl(221, 83%, 53%)' },
    reserved: { label: 'Reserved', color: 'hsl(45, 93%, 47%)' },
    available: { label: 'Available', color: 'hsl(142, 71%, 45%)' },
};

export const StockOverviewTab: React.FC<StockOverviewTabProps> = ({ stockSummary }) => {
    const [activeDialog, setActiveDialog] = useState<string | null>(null);

    if (!stockSummary) {
        return (
            <motion.div
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                className="flex items-center justify-center py-12"
            >
                <p className="text-muted-foreground">No stock data available.</p>
            </motion.div>
        );
    }

    const categories = Object.keys(STOCK_CONFIG);
    const chartData = categories.map((key) => {
        const item = stockSummary[key] || { total: 0, reserved: 0, available: 0, items: 0 };
        const config = STOCK_CONFIG[key];
        return {
            category: config.label,
            total: item.total,
            reserved: item.reserved,
            available: item.available,
        };
    });

    const getVariant = (item: StockSummaryItem): 'default' | 'warning' | 'danger' | 'success' => {
        if (item.available === 0 && item.total > 0) return 'danger';
        if (item.available > 0 && item.available <= item.total * 0.2) return 'warning';
        if (item.available > 0) return 'success';
        return 'default';
    };

    return (
        <div className="space-y-6">
            {/* Stat Cards Grid */}
            <div className="grid gap-4 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                {categories.map((key, index) => {
                    const item = stockSummary[key] || { total: 0, reserved: 0, available: 0, items: 0 };
                    const config = STOCK_CONFIG[key];
                    return (
                        <StatCard
                            key={key}
                            title={config.label}
                            value={item.total}
                            icon={config.icon}
                            description={`${item.available} available · ${item.reserved} reserved · ${item.items} items`}
                            onClick={() => setActiveDialog(key)}
                            variant={getVariant(item)}
                            delay={index * 0.05}
                        />
                    );
                })}
            </div>

            {/* Comparison Bar Chart */}
            <motion.div
                initial={{ opacity: 0, y: 20 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.4, delay: 0.25 }}
            >
                <Card>
                    <CardHeader>
                        <CardTitle>Stock Levels Comparison</CardTitle>
                        <CardDescription>Total, reserved, and available stock across all categories</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ChartContainer config={chartConfig} className="h-[300px] w-full">
                            <BarChart data={chartData} layout="vertical" margin={{ left: 20, right: 20 }}>
                                <CartesianGrid strokeDasharray="3 3" horizontal={false} />
                                <XAxis type="number" />
                                <YAxis type="category" dataKey="category" width={80} />
                                <ChartTooltip content={<ChartTooltipContent />} />
                                <Bar dataKey="total" fill="var(--color-total)" radius={[0, 4, 4, 0]} />
                                <Bar dataKey="reserved" fill="var(--color-reserved)" radius={[0, 4, 4, 0]} />
                                <Bar dataKey="available" fill="var(--color-available)" radius={[0, 4, 4, 0]} />
                            </BarChart>
                        </ChartContainer>
                    </CardContent>
                </Card>
            </motion.div>

            {/* Detail Dialogs */}
            {categories.map((key) => {
                const item = stockSummary[key] || { total: 0, reserved: 0, available: 0, items: 0 };
                const config = STOCK_CONFIG[key];
                return (
                    <DetailDialog
                        key={key}
                        open={activeDialog === key}
                        onClose={() => setActiveDialog(null)}
                        title={`${config.label} Stock Details`}
                        description={`Breakdown of ${config.label.toLowerCase()} inventory`}
                    >
                        <div className="space-y-4">
                            <div className="grid grid-cols-2 gap-4">
                                <div className="rounded-lg border p-4">
                                    <p className="text-sm text-muted-foreground">Total Stock</p>
                                    <p className="text-2xl font-bold">{item.total}</p>
                                </div>
                                <div className="rounded-lg border p-4">
                                    <p className="text-sm text-muted-foreground">Unique Items</p>
                                    <p className="text-2xl font-bold">{item.items}</p>
                                </div>
                                <div className="rounded-lg border p-4">
                                    <p className="text-sm text-muted-foreground">Reserved</p>
                                    <p className="text-2xl font-bold text-orange-500">{item.reserved}</p>
                                </div>
                                <div className="rounded-lg border p-4">
                                    <p className="text-sm text-muted-foreground">Available</p>
                                    <p className="text-2xl font-bold text-green-500">{item.available}</p>
                                </div>
                            </div>
                            {item.total > 0 && (
                                <div className="space-y-2">
                                    <p className="text-sm font-medium">Utilization</p>
                                    <div className="h-3 w-full rounded-full bg-muted overflow-hidden">
                                        <div
                                            className="h-full rounded-full bg-primary transition-all"
                                            style={{ width: `${((item.reserved / item.total) * 100).toFixed(1)}%` }}
                                        />
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        {((item.reserved / item.total) * 100).toFixed(1)}% utilized
                                    </p>
                                </div>
                            )}
                        </div>
                    </DetailDialog>
                );
            })}
        </div>
    );
};
