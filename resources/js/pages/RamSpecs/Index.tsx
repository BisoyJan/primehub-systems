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


    // 2. Search handler
    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        form.get(index.url(), {
            preserveState: true,
            preserveScroll: true,
        });
    };

    // 3. Delete handler
    const handleDelete = (id: number) => {
        form.delete(destroy({ ramspec: id }).url, {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Ram Specs" />

            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3">
                {/* Header Section */}
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    {/* Search Form */}
                    <form onSubmit={handleSearch} className="flex gap-2">
                        <input
                            type="text"
                            name="search"
                            placeholder="Search RAM details..."
                            value={form.data.search}
                            onChange={(e) => form.setData("search", e.target.value)}
                            className="border rounded px-3 py-2 text-sm w-64 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                        <Button type="submit">Search</Button>
                    </form>

                    {/* Add Button */}
                    <Link href={create.url()}>
                        <Button className="bg-blue-600 hover:bg-blue-700 text-white">
                            Add Model
                        </Button>
                    </Link>
                </div>

                {/* RAM Specs Table */}
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>ID</TableHead>
                                <TableHead>Manufacturer</TableHead>
                                <TableHead>Model</TableHead>
                                <TableHead>Form Factor</TableHead>
                                <TableHead>Voltage</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Capacity (GB)</TableHead>
                                <TableHead>Speed</TableHead>
                                <TableHead className="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>

                        <TableBody>
                            {ramspecs.data.map((ram) => (
                                <TableRow key={ram.id}>
                                    <TableCell>{ram.id}</TableCell>
                                    <TableCell className="font-medium">{ram.manufacturer}</TableCell>
                                    <TableCell>{ram.model}</TableCell>
                                    <TableCell>{ram.form_factor}</TableCell>
                                    <TableCell>{ram.voltage}</TableCell>
                                    <TableCell>{ram.type}</TableCell>
                                    <TableCell>{ram.capacity_gb}</TableCell>
                                    <TableCell>{ram.speed}</TableCell>
                                    <TableCell className="flex justify-end gap-2">
                                        <Link href={edit.url(ram.id)}>
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
                                                    <AlertDialogTitle>Confirm Deletion?</AlertDialogTitle>
                                                    <AlertDialogDescription>
                                                        Are you sure you want to delete{" "}
                                                        <strong>
                                                            {ram.manufacturer} {ram.model}
                                                        </strong>
                                                        ? This action cannot be undone.
                                                    </AlertDialogDescription>
                                                </AlertDialogHeader>
                                                <AlertDialogFooter>
                                                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                    <AlertDialogAction
                                                        onClick={() => handleDelete(ram.id)}
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
                                <TableCell colSpan={10} className="text-center font-medium">
                                    RAM Specs List
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>

                {/* Pagination */}
                <div className="flex justify-center">
                    <PaginationNav links={ramspecs.links} />
                </div>
            </div>
        </AppLayout>
    );
}
