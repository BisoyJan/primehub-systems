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
import { Can } from "@/components/authorization";
import { usePermission } from "@/hooks/useAuthorization";
import { LoadingOverlay } from "@/components/LoadingOverlay";

import { create, edit, destroy, index } from "@/routes/ramspecs";

interface RamSpec {
    id: number;
    manufacturer: string;
    model: string;
    capacity_gb: number;
    type: string;
    speed: string;
    stock?: { quantity: number } | null;
}

interface PaginatedRamSpecs {
    data: RamSpec[];
    links: PaginationLink[];
}

interface Props extends InertiaPageProps {
    ramspecs: PaginatedRamSpecs;
    search?: string;
}

export default function RamSpecsIndexRefactored() {
    const { ramspecs, search: initialSearch } = usePage<Props>().props;
    const form = useForm({}); // Keep useForm for delete but empty for search

    // Use new hooks for cleaner code
    const { title, breadcrumbs } = usePageMeta({
        title: "RAM Specifications",
        breadcrumbs: [{ title: "RAM Specifications", href: index().url }]
    });

    useFlashMessage(); // Automatically handles flash messages
    const isLoading = usePageLoading(); // Track page loading state
    const { can } = usePermission(); // Check permissions

    const [searchQuery, setSearchQuery] = useState(initialSearch || "");
    const [lastRefresh, setLastRefresh] = useState(new Date());
    const [autoRefreshEnabled, setAutoRefreshEnabled] = useState(false);

    const handleManualRefresh = () => {
        setLastRefresh(new Date());
        router.reload({ only: ['ramspecs'] });
    };

    // Auto-refresh every 30 seconds (only when enabled)
    useEffect(() => {
        if (!autoRefreshEnabled) return;

        const interval = setInterval(() => {
            router.reload({
                only: ['ramspecs'],
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
        form.delete(destroy({ ramspec: id }).url, {
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
                    title="RAM Specs Management"
                    description="Manage RAM component specifications and inventory"
                />

                <div className="flex flex-col gap-4">
                    <div className="flex flex-col sm:flex-row gap-4 justify-between items-start sm:items-center">
                        <div className="w-full sm:w-auto flex-1 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            <div className="relative">
                                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search RAM specs..."
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
                                        Add RAM Spec
                                    </Button>
                                </Link>
                            )}
                        </div>
                    </div>

                    <div className="flex justify-between items-center text-sm">
                        <div className="text-muted-foreground">
                            Showing {ramspecs.data.length} records
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Last updated: {lastRefresh.toLocaleTimeString()}
                        </div>
                    </div>
                </div>

                {/* Desktop Table View */}
                <div className="hidden md:block shadow rounded-md overflow-hidden">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="hidden lg:table-cell">ID</TableHead>
                                    <TableHead>Manufacturer</TableHead>
                                    <TableHead>Model</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Capacity (GB)</TableHead>
                                    <TableHead>Speed</TableHead>
                                    <TableHead>Stock</TableHead>
                                    <TableHead className="text-center">Actions</TableHead>
                                </TableRow>
                            </TableHeader>

                            <TableBody>
                                {ramspecs.data.map((ram) => (
                                    <TableRow key={ram.id}>
                                        <TableCell className="hidden lg:table-cell">{ram.id}</TableCell>
                                        <TableCell className="font-medium">{ram.manufacturer}</TableCell>
                                        <TableCell>{ram.model}</TableCell>
                                        <TableCell>{ram.type}</TableCell>
                                        <TableCell>{ram.capacity_gb}</TableCell>
                                        <TableCell>{ram.speed}</TableCell>
                                        <TableCell>
                                            {ram.stock ? ram.stock.quantity : 0}
                                            {(!ram.stock || ram.stock.quantity < 10) && (
                                                <span className="ml-2 px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-800">
                                                    Low Stock
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center justify-center gap-2">
                                                <Can permission="hardware.edit">
                                                    <Link href={edit({ ramspec: ram.id }).url}>
                                                        <Button variant="outline" size="sm">Edit</Button>
                                                    </Link>
                                                </Can>

                                                {/* Reusable delete confirmation dialog */}
                                                <Can permission="hardware.delete">
                                                    <DeleteConfirmDialog
                                                        onConfirm={() => handleDelete(ram.id)}
                                                        title="Delete RAM Specification"
                                                        description={`Are you sure you want to delete ${ram.manufacturer} ${ram.model}?`}
                                                    />
                                                </Can>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {ramspecs.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={8} className="h-24 text-center text-muted-foreground">
                                            No RAM specifications found
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </div>

                {/* Mobile Card View */}
                <div className="md:hidden space-y-4">
                    {ramspecs.data.map((ram) => (
                        <div key={ram.id} className="bg-card border rounded-lg p-4 shadow-sm space-y-3">
                            <div className="flex justify-between items-start">
                                <div>
                                    <div className="text-lg font-semibold">{ram.manufacturer}</div>
                                    <div className="text-sm text-muted-foreground">{ram.model}</div>
                                </div>
                                <div className="flex items-center gap-2">
                                    {(!ram.stock || ram.stock.quantity < 10) && (
                                        <span className="px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-800">
                                            Low Stock
                                        </span>
                                    )}
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-2 text-sm">
                                <div>
                                    <span className="text-muted-foreground">Type:</span>{' '}
                                    <span className="font-medium">{ram.type}</span>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Capacity:</span>{' '}
                                    <span className="font-medium">{ram.capacity_gb} GB</span>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Speed:</span>{' '}
                                    <span className="font-medium">{ram.speed}</span>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Stock:</span>{' '}
                                    <span className="font-medium">{ram.stock ? ram.stock.quantity : 0}</span>
                                </div>
                            </div>

                            <div className="flex gap-2 pt-2 border-t">
                                <Can permission="hardware.edit">
                                    <Link href={edit({ ramspec: ram.id }).url} className="flex-1">
                                        <Button variant="outline" size="sm" className="w-full">Edit</Button>
                                    </Link>
                                </Can>
                                <Can permission="hardware.delete">
                                    <div className="flex-1">
                                        <DeleteConfirmDialog
                                            onConfirm={() => handleDelete(ram.id)}
                                            title="Delete RAM Specification"
                                            description={`Are you sure you want to delete ${ram.manufacturer} ${ram.model}?`}
                                        />
                                    </div>
                                </Can>
                            </div>
                        </div>
                    ))}
                    {ramspecs.data.length === 0 && (
                        <div className="py-12 text-center text-gray-500 border rounded-lg bg-card">
                            No RAM specifications found
                        </div>
                    )}
                </div>

                {/* Pagination */}
                {ramspecs.links && (
                    <div className="flex justify-center">
                        <PaginationNav links={ramspecs.links} />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
