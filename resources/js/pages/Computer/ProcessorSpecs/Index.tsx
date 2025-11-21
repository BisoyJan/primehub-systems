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
import { Can } from "@/components/authorization";
import { usePermission } from "@/hooks/useAuthorization";

import { create, edit, destroy, index } from "@/routes/processorspecs";

interface ProcessorSpec {
    id: number;
    manufacturer: string;
    model: string;
    socket_type: string;
    core_count: number;
    thread_count: number;
    base_clock_ghz: number;
    boost_clock_ghz: number;
    integrated_graphics: string;
    tdp_watts: number;
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
    const form = useForm({ search: initialSearch || "" });

    // Use new hooks for cleaner code
    const { title, breadcrumbs } = usePageMeta({
        title: "Processor Specifications",
        breadcrumbs: [{ title: "Processor Specifications", href: index().url }]
    });

    useFlashMessage(); // Automatically handles flash messages
    const isLoading = usePageLoading(); // Track page loading state
    const { can } = usePermission(); // Check permissions

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        form.get(index.url(), {
            preserveState: true,
            preserveScroll: true,
        });
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
                    createLink={can("hardware.create") ? create.url() : undefined}
                    createLabel="Add Processor"
                >
                    {/* Reusable search bar */}
                    <SearchBar
                        value={form.data.search}
                        onChange={(value) => form.setData("search", value)}
                        onSubmit={handleSearch}
                        placeholder="Search processor specifications..."
                    />
                </PageHeader>

                {/* Desktop Table */}
                <div className="hidden md:block shadow rounded-md overflow-hidden">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="hidden lg:table-cell">ID</TableHead>
                                    <TableHead>Manufacturer</TableHead>
                                    <TableHead>Model</TableHead>
                                    <TableHead className="hidden xl:table-cell">Socket</TableHead>
                                    <TableHead>Cores</TableHead>
                                    <TableHead>Threads</TableHead>
                                    <TableHead className="hidden xl:table-cell">Base Clock</TableHead>
                                    <TableHead className="hidden xl:table-cell">Boost Clock</TableHead>
                                    <TableHead className="hidden xl:table-cell">Graphics</TableHead>
                                    <TableHead className="hidden xl:table-cell">TDP (W)</TableHead>
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
                                        <TableCell className="hidden xl:table-cell">{cpu.socket_type}</TableCell>
                                        <TableCell>{cpu.core_count}</TableCell>
                                        <TableCell>{cpu.thread_count}</TableCell>
                                        <TableCell className="hidden xl:table-cell">{cpu.base_clock_ghz} GHz</TableCell>
                                        <TableCell className="hidden xl:table-cell">{cpu.boost_clock_ghz} GHz</TableCell>
                                        <TableCell className="hidden xl:table-cell">{cpu.integrated_graphics}</TableCell>
                                        <TableCell className="hidden xl:table-cell">{cpu.tdp_watts}</TableCell>
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
                                    <span className="text-muted-foreground">Socket:</span>
                                    <span className="font-medium">{cpu.socket_type}</span>
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
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Graphics:</span>
                                    <span className="font-medium">{cpu.integrated_graphics}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">TDP:</span>
                                    <span className="font-medium">{cpu.tdp_watts} W</span>
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
