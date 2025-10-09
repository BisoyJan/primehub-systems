import { useEffect } from "react";
import { Head, Link, useForm, usePage } from "@inertiajs/react";
import type { PageProps as InertiaPageProps } from "@inertiajs/core";
import { toast } from "sonner";

import AppLayout from "@/layouts/app-layout";
import { Button } from "@/components/ui/button";
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from "@/components/ui/table";
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from "@/components/ui/alert-dialog";
import PaginationNav, { PaginationLink } from "@/components/pagination-nav";

import { dashboard } from "@/routes";
import { create, edit, destroy, index } from "@/routes/processorspecs";

const breadcrumbs = [{ title: "ProcessorSpecs", href: dashboard().url }];

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

    const { flash } = usePage().props as { flash?: { message?: string; type?: string } };

    useEffect(() => {
        if (!flash?.message) return;
        if (flash.type === "error") {
            toast.error(flash.message);
        } else {
            toast.success(flash.message);
        }
    }, [flash?.message, flash?.type]);

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
            <Head title="Processor Specs" />

            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3">
                {/* Header Section */}
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    {/* Search Form */}
                    <form onSubmit={handleSearch} className="flex gap-2">
                        <input
                            type="text"
                            name="search"
                            placeholder="Search processor model..."
                            value={form.data.search}
                            onChange={(e) => form.setData("search", e.target.value)}
                            className="border rounded px-3 py-2 text-sm w-64 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                        <Button type="submit">Search</Button>
                    </form>

                    {/* Add Button */}
                    <Link href={create.url()}>
                        <Button className="bg-blue-600 hover:bg-blue-700 text-white">
                            Add Processor
                        </Button>
                    </Link>
                </div>

                {/* Table Section */}
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>ID</TableHead>
                                <TableHead>Manufacturer</TableHead>
                                <TableHead>Model</TableHead>
                                <TableHead>Socket</TableHead>
                                <TableHead>Cores</TableHead>
                                <TableHead>Threads</TableHead>
                                <TableHead>Base Clock</TableHead>
                                <TableHead>Boost Clock</TableHead>
                                <TableHead>Graphics</TableHead>
                                <TableHead>TDP (W)</TableHead>
                                <TableHead>Stocks</TableHead>
                                <TableHead className="text-center">Actions</TableHead>
                            </TableRow>
                        </TableHeader>

                        <TableBody>
                            {processorspecs.data.map((cpu) => (
                                <TableRow key={cpu.id}>
                                    <TableCell>{cpu.id}</TableCell>
                                    <TableCell className="font-medium">{cpu.manufacturer}</TableCell>
                                    <TableCell>{cpu.model}</TableCell>
                                    <TableCell>{cpu.socket_type}</TableCell>
                                    <TableCell>{cpu.core_count}</TableCell>
                                    <TableCell>{cpu.thread_count}</TableCell>
                                    <TableCell>{cpu.base_clock_ghz} GHz</TableCell>
                                    <TableCell>{cpu.boost_clock_ghz} GHz</TableCell>
                                    <TableCell>{cpu.integrated_graphics}</TableCell>
                                    <TableCell>{cpu.tdp_watts}</TableCell>
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
                                        <Link href={edit.url(cpu.id)}>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="bg-green-600 hover:bg-green-700 text-white"
                                            >
                                                Edit
                                            </Button>
                                        </Link>
                                        <AlertDialog>
                                            <AlertDialogTrigger asChild>
                                                <Button
                                                    variant="destructive"
                                                    size="sm"
                                                    className="bg-red-600 hover:bg-red-700 text-white"
                                                >
                                                    Delete
                                                </Button>
                                            </AlertDialogTrigger>
                                            <AlertDialogContent>
                                                <AlertDialogHeader>
                                                    <AlertDialogTitle>Confirm Deletion</AlertDialogTitle>
                                                    <AlertDialogDescription>
                                                        Are you sure you want to delete{" "}
                                                        <strong>{cpu.manufacturer} {cpu.model}</strong>? This action cannot be undone.
                                                    </AlertDialogDescription>
                                                </AlertDialogHeader>
                                                <AlertDialogFooter>
                                                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                    <AlertDialogAction
                                                        onClick={() => handleDelete(cpu.id)}
                                                        className="bg-red-600 hover:bg-red-700"
                                                    >
                                                        Yes, Delete
                                                    </AlertDialogAction>
                                                </AlertDialogFooter>
                                            </AlertDialogContent>
                                        </AlertDialog>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>

                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={12} className="text-center font-medium">
                                    Processor Specs List
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>

                {/* Pagination */}
                <div className="flex justify-center">
                    <PaginationNav links={processorspecs.links} />
                </div>
            </div>
        </AppLayout>
    );
}
