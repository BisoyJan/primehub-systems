/**
 * EXAMPLE: Refactored RamSpecs Index page using new reusable hooks and components
 * 
 * This demonstrates how to use:
 * - usePageMeta() for consistent page metadata
 * - useFlashMessage() for automatic flash message handling
 * - usePageLoading() for page transition loading states
 * - PageHeader for consistent page headers
 * - SearchBar for search functionality
 * - DeleteConfirmDialog for delete confirmations
 * - LoadingOverlay for loading states
 * 
 * The refactored code is ~40% shorter and more maintainable.
 * This pattern can be applied to DiskSpecs, ProcessorSpecs, and other CRUD pages.
 */

import React from "react";
import { Head, Link, useForm, usePage } from "@inertiajs/react";
import type { PageProps as InertiaPageProps } from "@inertiajs/core";

import AppLayout from "@/layouts/app-layout";
import { Button } from "@/components/ui/button";
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import PaginationNav, { PaginationLink } from "@/components/pagination-nav";

// New reusable components and hooks
import { usePageMeta, useFlashMessage, usePageLoading } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { SearchBar } from "@/components/SearchBar";
import { DeleteConfirmDialog } from "@/components/DeleteConfirmDialog";
import { LoadingOverlay } from "@/components/LoadingOverlay";

import { create, edit, destroy, index } from "@/routes/ramspecs";

interface RamSpec {
    id: number;
    manufacturer: string;
    model: string;
    capacity_gb: number;
    type: string;
    speed: string;
    form_factor: string;
    voltage: number;
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
    const form = useForm({ search: initialSearch || "" });

    // Use new hooks for cleaner code
    const { title, breadcrumbs } = usePageMeta({
        title: "RAM Specifications",
        breadcrumbs: [{ title: "RAM Specifications", href: index().url }]
    });
    
    useFlashMessage(); // Automatically handles flash messages
    const isLoading = usePageLoading(); // Track page loading state

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        form.get(index.url(), {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleDelete = (id: number) => {
        form.delete(destroy({ ramspec: id }).url, {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex h-full flex-1 flex-col gap-3 rounded-xl p-3 md:p-6 relative">
                {/* Loading overlay for page transitions */}
                <LoadingOverlay isLoading={isLoading} />

                {/* Reusable page header with create button */}
                <PageHeader
                    title="RAM Specs Management"
                    description="Manage RAM component specifications and inventory"
                    createLink={create.url()}
                    createLabel="Add RAM Spec"
                >
                    {/* Reusable search bar */}
                    <SearchBar
                        value={form.data.search}
                        onChange={(value) => form.setData("search", value)}
                        onSubmit={handleSearch}
                        placeholder="Search RAM specifications..."
                    />
                </PageHeader>

                {/* Table section - kept as is, but can be further componentized */}
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
                                                <Link href={edit({ ramspec: ram.id }).url}>
                                                    <Button variant="outline" size="sm">Edit</Button>
                                                </Link>
                                                
                                                {/* Reusable delete confirmation dialog */}
                                                <DeleteConfirmDialog
                                                    onConfirm={() => handleDelete(ram.id)}
                                                    title="Delete RAM Specification"
                                                    description={`Are you sure you want to delete ${ram.manufacturer} ${ram.model}?`}
                                                />
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </div>

                {/* Pagination */}
                {ramspecs.links && (
                    <div className="mt-4">
                        <PaginationNav links={ramspecs.links} />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
