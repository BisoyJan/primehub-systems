import React, { useState, useEffect } from "react";
import { Head, Link, useForm, usePage, router } from "@inertiajs/react";
import type { PageProps as InertiaPageProps } from "@inertiajs/core";

import AppLayout from "@/layouts/app-layout";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import PaginationNav, { PaginationLink } from "@/components/pagination-nav";
import { RefreshCw, Search, Filter, Plus } from "lucide-react";

// New reusable components and hooks
import { usePageMeta, useFlashMessage, usePageLoading } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { DeleteConfirmDialog } from "@/components/DeleteConfirmDialog";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { Can } from "@/components/authorization";
import { usePermission } from "@/hooks/useAuthorization";

import { create, edit, destroy, index } from "@/routes/diskspecs";

interface DiskSpec {
    id: number;
    manufacturer: string;
    model: string;
    capacity_gb: number;
    interface: string;
    drive_type: string;
    sequential_read_mb: number;
    sequential_write_mb: number;
    stock?: Stock | null;
}

interface Stock {
    quantity: number;
}

interface PaginatedDiskSpecs {
    data: DiskSpec[];
    links: PaginationLink[];
}

interface Props extends InertiaPageProps {
    flash?: { message?: string; type?: string };
    diskspecs: PaginatedDiskSpecs;
    search?: string;
}

export default function Index() {
    const { diskspecs, search: initialSearch } = usePage<Props>().props;
    const form = useForm({}); // Keep useForm for delete but empty for search

    // Use new hooks for cleaner code
    const { title, breadcrumbs } = usePageMeta({
        title: "Disk Specifications",
        breadcrumbs: [{ title: "Disk Specifications", href: index().url }]
    });

    useFlashMessage(); // Automatically handles flash messages
    const isLoading = usePageLoading(); // Track page loading state
    const { can } = usePermission(); // Check permissions

    const [searchQuery, setSearchQuery] = useState(initialSearch || "");
    const [lastRefresh, setLastRefresh] = useState(new Date());

    const handleManualRefresh = () => {
        setLastRefresh(new Date());
        router.reload({ only: ['diskspecs'] });
    };

    // Auto-refresh every 30 seconds
    useEffect(() => {
        const interval = setInterval(() => {
            router.reload({
                only: ['diskspecs'],
                onSuccess: () => setLastRefresh(new Date()),
            });
        }, 30000);

        return () => clearInterval(interval);
    }, []);

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
        form.delete(destroy({ diskspec: id }).url, {
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
                    title="Disk Specs Management"
                    description="Manage disk storage component specifications and inventory"
                />

                <div className="flex flex-col gap-4">
                    <div className="flex flex-col sm:flex-row gap-4 justify-between items-start sm:items-center">
                        <div className="w-full sm:w-auto flex-1 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            <div className="relative">
                                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search disk specs..."
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                    className="pl-8"
                                />
                            </div>
                        </div>

                        <div className="flex gap-2 w-full sm:w-auto">
                            <Button onClick={handleFilter} className="flex-1 sm:flex-none">
                                <Filter className="mr-2 h-4 w-4" />
                                Filter
                            </Button>
                            <Button variant="outline" onClick={handleReset} className="flex-1 sm:flex-none">
                                Reset
                            </Button>
                            <Button variant="ghost" size="icon" onClick={handleManualRefresh} title="Refresh">
                                <RefreshCw className="h-4 w-4" />
                            </Button>
                            {can("hardware.create") && (
                                <Link href={create.url()}>
                                    <Button className="flex-1 sm:flex-none">
                                        <Plus className="mr-2 h-4 w-4" />
                                        Add Disk Spec
                                    </Button>
                                </Link>
                            )}
                        </div>
                    </div>

                    <div className="flex justify-between items-center text-sm">
                        <div className="text-muted-foreground">
                            Showing {diskspecs.data.length} records
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Last updated: {lastRefresh.toLocaleTimeString()}
                        </div>
                    </div>
                </div>

                {/* Desktop Table - hidden on mobile */}
                <div className="hidden md:block shadow rounded-md overflow-hidden">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="hidden lg:table-cell">ID</TableHead>
                                    <TableHead>Manufacturer</TableHead>
                                    <TableHead>Model Number</TableHead>
                                    <TableHead>Capacity (GB)</TableHead>
                                    <TableHead className="hidden xl:table-cell">Interface</TableHead>
                                    <TableHead>Drive Type</TableHead>
                                    <TableHead className="hidden xl:table-cell">Read Speed (MB/s)</TableHead>
                                    <TableHead className="hidden xl:table-cell">Write Speed (MB/s)</TableHead>
                                    <TableHead>Stocks</TableHead>
                                    <TableHead className="text-center">Actions</TableHead>
                                </TableRow>
                            </TableHeader>

                            <TableBody>
                                {diskspecs.data.map((disk) => (
                                    <TableRow key={disk.id}>
                                        <TableCell className="hidden lg:table-cell">{disk.id}</TableCell>
                                        <TableCell className="font-medium">{disk.manufacturer}</TableCell>
                                        <TableCell>{disk.model}</TableCell>
                                        <TableCell>{disk.capacity_gb}</TableCell>
                                        <TableCell className="hidden xl:table-cell">{disk.interface}</TableCell>
                                        <TableCell>{disk.drive_type}</TableCell>
                                        <TableCell className="hidden xl:table-cell">{disk.sequential_read_mb}</TableCell>
                                        <TableCell className="hidden xl:table-cell">{disk.sequential_write_mb}</TableCell>
                                        <TableCell>
                                            {disk.stock ? disk.stock.quantity : 0}

                                            {(!disk.stock || disk.stock.quantity < 10) && (
                                                <span
                                                    className={`
                                        ml-2 px-2 py-0.5 rounded-full text-xs font-semibold
                                        ${!disk.stock || disk.stock.quantity === 0
                                                            ? "bg-red-100 text-red-700"
                                                            : "bg-yellow-100 text-yellow-700"}
                                                `}
                                                >
                                                    {!disk.stock || disk.stock.quantity === 0 ? "Out of Stock" : "Low Stock"}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="flex justify-center gap-2">
                                            <Can permission="hardware.edit">
                                                <Link href={edit.url(disk.id)}>
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
                                                    onConfirm={() => handleDelete(disk.id)}
                                                    title="Delete Disk Specification"
                                                    description={`Are you sure you want to delete ${disk.manufacturer} ${disk.model}?`}
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
                    {diskspecs.data.map((disk) => (
                        <div key={disk.id} className="bg-card border rounded-lg p-4 shadow-sm space-y-3">
                            {/* Header */}
                            <div className="flex justify-between items-start">
                                <div>
                                    <div className="text-xs text-muted-foreground">Manufacturer</div>
                                    <div className="font-semibold text-lg">{disk.manufacturer}</div>
                                </div>
                                <div className="text-right">
                                    <div className="text-xs text-muted-foreground">Stock</div>
                                    <div className="font-medium">{disk.stock ? disk.stock.quantity : 0}</div>
                                    {(!disk.stock || disk.stock.quantity < 10) && (
                                        <span
                                            className={`inline-block mt-1 px-2 py-0.5 rounded-full text-xs font-semibold ${!disk.stock || disk.stock.quantity === 0
                                                ? "bg-red-100 text-red-700"
                                                : "bg-yellow-100 text-yellow-700"
                                                }`}
                                        >
                                            {!disk.stock || disk.stock.quantity === 0 ? "Out" : "Low"}
                                        </span>
                                    )}
                                </div>
                            </div>

                            {/* Details */}
                            <div className="space-y-2 text-sm">
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Model:</span>
                                    <span className="font-medium">{disk.model}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Capacity:</span>
                                    <span className="font-medium">{disk.capacity_gb} GB</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Drive Type:</span>
                                    <span className="font-medium">{disk.drive_type}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Interface:</span>
                                    <span className="font-medium">{disk.interface}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Read Speed:</span>
                                    <span className="font-medium">{disk.sequential_read_mb} MB/s</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Write Speed:</span>
                                    <span className="font-medium">{disk.sequential_write_mb} MB/s</span>
                                </div>
                            </div>

                            {/* Actions */}
                            <div className="flex gap-2">
                                <Can permission="hardware.edit">
                                    <Link href={edit.url(disk.id)} className="flex-1">
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
                                            onConfirm={() => handleDelete(disk.id)}
                                            title="Delete Disk Specification"
                                            description={`Are you sure you want to delete ${disk.manufacturer} ${disk.model}?`}
                                        />
                                    </div>
                                </Can>
                            </div>
                        </div>
                    ))}
                    {diskspecs.data.length === 0 && (
                        <div className="py-12 text-center text-gray-500 border rounded-lg bg-card">
                            No disk specs found
                        </div>
                    )}
                </div>                {/* Pagination */}
                <div className="flex justify-center">
                    <PaginationNav links={diskspecs.links} />
                </div>
            </div>
        </AppLayout>
    );
}
