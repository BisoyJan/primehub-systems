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
import { create, edit, destroy, index } from "@/routes/diskspecs";


const breadcrumbs = [{ title: "DiskSpecs", href: dashboard().url }];

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
        form.delete(destroy({ diskspec: id }).url, {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Disk Specs" />

            <div className="flex h-full flex-1 flex-col gap-3 rounded-xl p-3 md:p-6">
                {/* Header Section */}
                <div className="flex flex-col gap-3">
                    <h2 className="text-lg md:text-xl font-semibold">Disk Specs Management</h2>

                    {/* Search Form - full width on mobile */}
                    <form onSubmit={handleSearch} className="flex gap-2">
                        <input
                            type="text"
                            name="search"
                            placeholder="Search disk details..."
                            value={form.data.search}
                            onChange={(e) => form.setData("search", e.target.value)}
                            className="border rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                        <Button type="submit" className="shrink-0">Search</Button>
                    </form>

                    {/* Add Button - full width on mobile */}
                    <Link href={create.url()} className="w-full sm:w-auto sm:self-end">
                        <Button className="bg-blue-600 hover:bg-blue-700 text-white w-full sm:w-auto">
                            Add Disk Spec
                        </Button>
                    </Link>
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
                                            <Link href={edit.url(disk.id)}>
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
                                                <AlertDialogContent className="max-w-[90vw] sm:max-w-lg">
                                                    <AlertDialogHeader>
                                                        <AlertDialogTitle>Confirm Deletion?</AlertDialogTitle>
                                                        <AlertDialogDescription>
                                                            Are you sure you want to delete{" "}
                                                            <strong>
                                                                {disk.manufacturer} {disk.model}
                                                            </strong>
                                                            ? This action cannot be undone.
                                                        </AlertDialogDescription>
                                                    </AlertDialogHeader>
                                                    <AlertDialogFooter className="flex-col sm:flex-row gap-2">
                                                        <AlertDialogCancel className="w-full sm:w-auto">Cancel</AlertDialogCancel>
                                                        <AlertDialogAction
                                                            onClick={() => handleDelete(disk.id)}
                                                            className="bg-red-600 hover:bg-red-700 w-full sm:w-auto"
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
                                    <TableCell colSpan={10} className="text-center font-medium">
                                        Disk Specs List
                                    </TableCell>
                                </TableRow>
                            </TableFooter>
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
                            <div className="flex gap-2 pt-2 border-t">
                                <Link href={edit.url(disk.id)} className="flex-1">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="bg-green-600 hover:bg-green-700 text-white w-full"
                                    >
                                        Edit
                                    </Button>
                                </Link>
                                <AlertDialog>
                                    <AlertDialogTrigger asChild>
                                        <Button
                                            variant="destructive"
                                            size="sm"
                                            className="bg-red-600 hover:bg-red-700 text-white flex-1"
                                        >
                                            Delete
                                        </Button>
                                    </AlertDialogTrigger>
                                    <AlertDialogContent className="max-w-[90vw] sm:max-w-lg">
                                        <AlertDialogHeader>
                                            <AlertDialogTitle>Confirm Deletion?</AlertDialogTitle>
                                            <AlertDialogDescription>
                                                Are you sure you want to delete{" "}
                                                <strong>
                                                    {disk.manufacturer} {disk.model}
                                                </strong>
                                                ? This action cannot be undone.
                                            </AlertDialogDescription>
                                        </AlertDialogHeader>
                                        <AlertDialogFooter className="flex-col sm:flex-row gap-2">
                                            <AlertDialogCancel className="w-full sm:w-auto">Cancel</AlertDialogCancel>
                                            <AlertDialogAction
                                                onClick={() => handleDelete(disk.id)}
                                                className="bg-red-600 hover:bg-red-700 w-full sm:w-auto"
                                            >
                                                Yes, Delete
                                            </AlertDialogAction>
                                        </AlertDialogFooter>
                                    </AlertDialogContent>
                                </AlertDialog>
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
