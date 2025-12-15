import React, { useState, useEffect } from "react";
import { Head, Link, useForm, usePage, router } from "@inertiajs/react";
import type { PageProps as InertiaPageProps } from "@inertiajs/core";

import AppLayout from "@/layouts/app-layout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import PaginationNav, { PaginationLink } from "@/components/pagination-nav";
import { RefreshCw, Search, Filter, Plus, Play, Pause } from "lucide-react";

// New reusable components and hooks
import { usePageMeta, useFlashMessage, usePageLoading } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { DeleteConfirmDialog } from "@/components/DeleteConfirmDialog";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { Can } from "@/components/authorization";
import { usePermission } from "@/hooks/useAuthorization";

import { create, edit, destroy, index } from "@/routes/processorspecs";

interface ProcessorSpec {
    id: number;
    manufacturer: string;
    model: string;
    core_count: number;
    thread_count: number;
    base_clock_ghz: number;
    boost_clock_ghz: number;
    stock?: Stock | null;
}

interface Stock {
    quantity: number;
}

interface PaginatedProcessorSpecs {
    data: ProcessorSpec[];
    links: PaginationLink[];
}

interface Props extends InertiaPageProps {
    flash?: { message?: string; type?: string };
    processorspecs: PaginatedProcessorSpecs;
    search?: string;
}

export default function Index() {
    const { processorspecs, search: initialSearch } = usePage<Props>().props;
    const form = useForm({}); // Keep useForm for delete but empty for search

    // Use new hooks for cleaner code
    const { title, breadcrumbs } = usePageMeta({
        title: "Processor Specifications",
        breadcrumbs: [{ title: "Processor Specifications", href: index().url }]
    });

    useFlashMessage(); // Automatically handles flash messages
    const isLoading = usePageLoading(); // Track page loading state
    const { can } = usePermission(); // Check permissions

    const [searchQuery, setSearchQuery] = useState(initialSearch || "");
    const [lastRefresh, setLastRefresh] = useState(new Date());
    const [autoRefreshEnabled, setAutoRefreshEnabled] = useState(false);

    const handleManualRefresh = () => {
        setLastRefresh(new Date());
        router.reload({ only: ['processorspecs'] });
    };

    // Auto-refresh every 30 seconds
    useEffect(() => {
        if (!autoRefreshEnabled) return;
        const interval = setInterval(() => {
            router.reload({
                only: ['processorspecs'],
                onSuccess: () => setLastRefresh(new Date()),
            });
        }, 30000);

        return () => clearInterval(interval);
    }, [autoRefreshEnabled]);

    const handleFilter = () => {
        router.get(
            index().url,
            { search: searchQuery },
            {
                preserveState: true,
                preserveScroll: true,
            }
        );
    };

    const handleReset = () => {
        setSearchQuery("");
        router.get(index().url);
    };

    const handleDelete = (id: number) => {
        form.delete(destroy({ processorspec: id }).url, {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                {/* Loading overlay for page transitions */}
                <LoadingOverlay isLoading={isLoading} />

                {/* Reusable page header with create button */}
                <PageHeader
                    title="Processor Specs Management"
                    description="Manage CPU/processor component specifications and inventory"
                />

                <div className="flex flex-col gap-4">
                    <div className="flex flex-col sm:flex-row gap-4 justify-between items-start sm:items-center">
                        <div className="w-full sm:w-auto flex-1 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            <div className="relative">
                                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search processor specs..."
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                    className="pl-8"
                                />
                            </div>
                        </div>

                        <div className="flex flex-wrap gap-2 w-full sm:w-auto">
                            <Button onClick={handleFilter} className="flex-1 sm:flex-none">
                                <Filter className="mr-2 h-4 w-4" />
                                Filter
                            </Button>
                            <Button variant="outline" onClick={handleReset} className="flex-1 sm:flex-none">
                                Reset
                            </Button>
                            <div className="flex gap-2">
                                <Button variant="ghost" size="icon" onClick={handleManualRefresh} title="Refresh">
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
                            </div>
                            {can("hardware.create") && (
                                <Link href={create.url()}>
                                    <Button className="flex-1 sm:flex-none">
                                        <Plus className="mr-2 h-4 w-4" />
                                        Add Processor
                                    </Button>
                                </Link>
                            )}
                        </div>
                    </div>

                    <div className="flex justify-between items-center text-sm">
                        <div className="text-muted-foreground">
                            Showing {processorspecs.data.length} records
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Last updated: {lastRefresh.toLocaleTimeString()}
                        </div>
                    </div>
                </div>

                {/* Desktop Table */}
                <div className="hidden md:block shadow rounded-md overflow-hidden">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="hidden lg:table-cell">ID</TableHead>
                                    <TableHead>Manufacturer</TableHead>
                                    <TableHead>Model</TableHead>
                                    <TableHead>Cores</TableHead>
                                    <TableHead>Threads</TableHead>
                                    <TableHead className="hidden xl:table-cell">Base Clock</TableHead>
                                    <TableHead className="hidden xl:table-cell">Boost Clock</TableHead>
                                    <TableHead>Stocks</TableHead>
                                    <TableHead className="text-center">Actions</TableHead>
                                </TableRow>
                            </TableHeader>

                            <TableBody>
                                {processorspecs.data.map((cpu) => (
                                    <TableRow key={cpu.id}>
                                        <TableCell className="hidden lg:table-cell">{cpu.id}</TableCell>
                                        <TableCell className="font-medium">{cpu.manufacturer}</TableCell>
                                        <TableCell>{cpu.model}</TableCell>
                                        <TableCell>{cpu.core_count}</TableCell>
                                        <TableCell>{cpu.thread_count}</TableCell>
                                        <TableCell className="hidden xl:table-cell">{cpu.base_clock_ghz} GHz</TableCell>
                                        <TableCell className="hidden xl:table-cell">{cpu.boost_clock_ghz} GHz</TableCell>
                                        <TableCell>
                                            {cpu.stock ? cpu.stock.quantity : 0}

                                            {(!cpu.stock || cpu.stock.quantity < 10) && (
                                                <span
                                                    className={`
                                        ml-2 px-2 py-0.5 rounded-full text-xs font-semibold
                                        ${!cpu.stock || cpu.stock.quantity === 0
                                                            ? "bg-red-100 text-red-700"
                                                            : "bg-yellow-100 text-yellow-700"}
                                                `}
                                                >
                                                    {!cpu.stock || cpu.stock.quantity === 0 ? "Out of Stock" : "Low Stock"}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="flex justify-center gap-2">
                                            <Can permission="hardware.edit">
                                                <Link href={edit.url(cpu.id)}>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        className="bg-green-600 hover:bg-green-700 text-white"
                                                    >
                                                        Edit
                                                    </Button>
                                                </Link>
                                            </Can>

                                            {/* Reusable delete confirmation dialog */}
                                            <Can permission="hardware.delete">
                                                <DeleteConfirmDialog
                                                    onConfirm={() => handleDelete(cpu.id)}
                                                    title="Delete Processor Specification"
                                                    description={`Are you sure you want to delete ${cpu.manufacturer} ${cpu.model}?`}
                                                />
                                            </Can>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </div>

                {/* Mobile Card View */}
                <div className="md:hidden space-y-4">
                    {processorspecs.data.map((cpu) => (
                        <div key={cpu.id} className="bg-card border rounded-lg p-4 shadow-sm space-y-3">
                            <div className="flex justify-between items-start">
                                <div>
                                    <div className="text-xs text-muted-foreground">Manufacturer</div>
                                    <div className="font-semibold text-lg">{cpu.manufacturer}</div>
                                </div>
                                <div className="text-right">
                                    <div className="text-xs text-muted-foreground">Stock</div>
                                    <div className="font-medium">{cpu.stock ? cpu.stock.quantity : 0}</div>
                                    {(!cpu.stock || cpu.stock.quantity < 10) && (
                                        <span
                                            className={`inline-block mt-1 px-2 py-0.5 rounded-full text-xs font-semibold ${!cpu.stock || cpu.stock.quantity === 0
                                                ? "bg-red-100 text-red-700"
                                                : "bg-yellow-100 text-yellow-700"
                                                }`}
                                        >
                                            {!cpu.stock || cpu.stock.quantity === 0 ? "Out" : "Low"}
                                        </span>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-2 text-sm">
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Model:</span>
                                    <span className="font-medium break-words text-right">{cpu.model}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Cores/Threads:</span>
                                    <span className="font-medium">{cpu.core_count} / {cpu.thread_count}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Base Clock:</span>
                                    <span className="font-medium">{cpu.base_clock_ghz} GHz</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Boost Clock:</span>
                                    <span className="font-medium">{cpu.boost_clock_ghz} GHz</span>
                                </div>
                            </div>

                            {/* Actions */}
                            <div className="flex gap-2">
                                <Can permission="hardware.edit">
                                    <Link href={edit.url(cpu.id)} className="flex-1">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="bg-green-600 hover:bg-green-700 text-white w-full"
                                        >
                                            Edit
                                        </Button>
                                    </Link>
                                </Can>

                                {/* Reusable delete confirmation dialog */}
                                <Can permission="hardware.delete">
                                    <div className="flex-1">
                                        <DeleteConfirmDialog
                                            onConfirm={() => handleDelete(cpu.id)}
                                            title="Delete Processor Specification"
                                            description={`Are you sure you want to delete ${cpu.manufacturer} ${cpu.model}?`}
                                        />
                                    </div>
                                </Can>
                            </div>
                        </div>
                    ))}
                    {processorspecs.data.length === 0 && (
                        <div className="py-12 text-center text-gray-500 border rounded-lg bg-card">
                            No processor specs found
                        </div>
                    )}
                </div>

                {/* Pagination */}
                <div className="flex justify-center">
                    <PaginationNav links={processorspecs.links} />
                </div>
            </div>
        </AppLayout>
    );
}
