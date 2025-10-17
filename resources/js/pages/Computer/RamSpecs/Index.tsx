import React, { useEffect } from "react";
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
import { create, edit, destroy, index } from "@/routes/ramspecs";

const breadcrumbs = [{ title: "RamSpecs", href: dashboard().url }];

interface RamSpec {
    id: number;
    manufacturer: string;
    model: string;
    capacity_gb: number;
    type: string;
    speed: string;
    form_factor: string;
    voltage: number;
    stock?: Stock | null;
}

interface Stock {
    quantity: number;
}

interface PaginatedRamSpecs {
    data: RamSpec[];
    links: PaginationLink[];
}

interface Props extends InertiaPageProps {
    flash?: { message?: string };
    ramspecs: PaginatedRamSpecs;
    search?: string;
}

export default function Index() {
    const { ramspecs, search: initialSearch } = usePage<Props>().props;
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
        form.delete(destroy({ ramspec: id }).url, {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Ram Specs" />

            <div className="flex h-full flex-1 flex-col gap-3 rounded-xl p-3 md:p-6">
                <div className="flex flex-col gap-3">
                    <h2 className="text-lg md:text-xl font-semibold">RAM Specs Management</h2>

                    <form onSubmit={handleSearch} className="flex gap-2">
                        <input
                            type="text"
                            name="search"
                            placeholder="Search RAM details..."
                            value={form.data.search}
                            onChange={(e) => form.setData("search", e.target.value)}
                            className="border rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                        <Button type="submit" className="shrink-0">Search</Button>
                    </form>

                    <Link href={create.url()} className="w-full sm:w-auto sm:self-end">
                        <Button className="bg-blue-600 hover:bg-blue-700 text-white w-full sm:w-auto">Add Model</Button>
                    </Link>
                </div>

                <div className="hidden md:block shadow rounded-md overflow-hidden">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="hidden lg:table-cell">ID</TableHead>
                                    <TableHead>Manufacturer</TableHead>
                                    <TableHead>Model</TableHead>
                                    <TableHead className="hidden xl:table-cell">Form Factor</TableHead>
                                    <TableHead className="hidden xl:table-cell">Voltage</TableHead>
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
                                        <TableCell className="hidden xl:table-cell">{ram.form_factor}</TableCell>
                                        <TableCell className="hidden xl:table-cell">{ram.voltage}</TableCell>
                                        <TableCell>{ram.type}</TableCell>
                                        <TableCell>{ram.capacity_gb}</TableCell>
                                        <TableCell>{ram.speed}</TableCell>
                                        <TableCell>
                                            {ram.stock ? ram.stock.quantity : 0}
                                            {(!ram.stock || ram.stock.quantity < 10) && (
                                                <span
                                                    className={`ml-2 px-2 py-0.5 rounded-full text-xs font-semibold ${!ram.stock || ram.stock.quantity === 0
                                                            ? "bg-red-100 text-red-700"
                                                            : "bg-yellow-100 text-yellow-700"
                                                        }`}
                                                >
                                                    {!ram.stock || ram.stock.quantity === 0 ? "Out of Stock" : "Low Stock"}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="flex justify-center gap-2">
                                            <Link href={edit.url(ram.id)}>
                                                <Button variant="outline" size="sm" className="bg-green-600 hover:bg-green-700 text-white">
                                                    Edit
                                                </Button>
                                            </Link>
                                            <AlertDialog>
                                                <AlertDialogTrigger asChild>
                                                    <Button variant="destructive" size="sm" className="bg-red-600 hover:bg-red-700 text-white">
                                                        Delete
                                                    </Button>
                                                </AlertDialogTrigger>
                                                <AlertDialogContent className="max-w-[90vw] sm:max-w-lg">
                                                    <AlertDialogHeader>
                                                        <AlertDialogTitle>Confirm Deletion?</AlertDialogTitle>
                                                        <AlertDialogDescription>
                                                            Are you sure you want to delete{" "}
                                                            <strong>
                                                                {ram.manufacturer} {ram.model}
                                                            </strong>
                                                            ? This action cannot be undone.
                                                        </AlertDialogDescription>
                                                    </AlertDialogHeader>
                                                    <AlertDialogFooter className="flex-col sm:flex-row gap-2">
                                                        <AlertDialogCancel className="w-full sm:w-auto">Cancel</AlertDialogCancel>
                                                        <AlertDialogAction onClick={() => handleDelete(ram.id)} className="bg-red-600 hover:bg-red-700 w-full sm:w-auto">
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
                                        RAM Specs List
                                    </TableCell>
                                </TableRow>
                            </TableFooter>
                        </Table>
                    </div>
                </div>

                <div className="md:hidden space-y-4">
                    {ramspecs.data.map((ram) => (
                        <div key={ram.id} className="bg-card border rounded-lg p-4 shadow-sm space-y-3">
                            <div className="flex justify-between items-start">
                                <div>
                                    <div className="text-xs text-muted-foreground">Manufacturer</div>
                                    <div className="font-semibold text-lg">{ram.manufacturer}</div>
                                </div>
                                <div className="text-right">
                                    <div className="text-xs text-muted-foreground">Stock</div>
                                    <div className="font-medium">{ram.stock ? ram.stock.quantity : 0}</div>
                                    {(!ram.stock || ram.stock.quantity < 10) && (
                                        <span
                                            className={`inline-block mt-1 px-2 py-0.5 rounded-full text-xs font-semibold ${!ram.stock || ram.stock.quantity === 0
                                                    ? "bg-red-100 text-red-700"
                                                    : "bg-yellow-100 text-yellow-700"
                                                }`}
                                        >
                                            {!ram.stock || ram.stock.quantity === 0 ? "Out" : "Low"}
                                        </span>
                                    )}
                                </div>
                            </div>

                            <div className="space-y-2 text-sm">
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Model:</span>
                                    <span className="font-medium break-words text-right">{ram.model}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Type:</span>
                                    <span className="font-medium">{ram.type}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Capacity:</span>
                                    <span className="font-medium">{ram.capacity_gb} GB</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Speed:</span>
                                    <span className="font-medium">{ram.speed}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Form Factor:</span>
                                    <span className="font-medium">{ram.form_factor}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Voltage:</span>
                                    <span className="font-medium">{ram.voltage}</span>
                                </div>
                            </div>

                            <div className="flex gap-2 pt-2 border-t">
                                <Link href={edit.url(ram.id)} className="flex-1">
                                    <Button variant="outline" size="sm" className="bg-green-600 hover:bg-green-700 text-white w-full">
                                        Edit
                                    </Button>
                                </Link>
                                <AlertDialog>
                                    <AlertDialogTrigger asChild>
                                        <Button variant="destructive" size="sm" className="bg-red-600 hover:bg-red-700 text-white flex-1">
                                            Delete
                                        </Button>
                                    </AlertDialogTrigger>
                                    <AlertDialogContent className="max-w-[90vw] sm:max-w-lg">
                                        <AlertDialogHeader>
                                            <AlertDialogTitle>Confirm Deletion?</AlertDialogTitle>
                                            <AlertDialogDescription>
                                                Are you sure you want to delete{" "}
                                                <strong>
                                                    {ram.manufacturer} {ram.model}
                                                </strong>
                                                ? This action cannot be undone.
                                            </AlertDialogDescription>
                                        </AlertDialogHeader>
                                        <AlertDialogFooter className="flex-col sm:flex-row gap-2">
                                            <AlertDialogCancel className="w-full sm:w-auto">Cancel</AlertDialogCancel>
                                            <AlertDialogAction onClick={() => handleDelete(ram.id)} className="bg-red-600 hover:bg-red-700 w-full sm:w-auto">
                                                Yes, Delete
                                            </AlertDialogAction>
                                        </AlertDialogFooter>
                                    </AlertDialogContent>
                                </AlertDialog>
                            </div>
                        </div>
                    ))}
                    {ramspecs.data.length === 0 && (
                        <div className="py-12 text-center text-gray-500 border rounded-lg bg-card">
                            No RAM specs found
                        </div>
                    )}
                </div>

                <div className="flex justify-center">
                    <PaginationNav links={ramspecs.links} />
                </div>
            </div>
        </AppLayout>
    );
}
