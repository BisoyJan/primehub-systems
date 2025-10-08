import { useEffect, useState } from "react";
import { Head, Link, useForm, usePage, router } from "@inertiajs/react";
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
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogFooter,
} from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";

import PaginationNav, { PaginationLink } from "@/components/pagination-nav";

import { dashboard } from "@/routes";
import { create, edit, destroy, index } from "@/routes/diskspecs";
import { store as stocksStore } from "@/routes/stocks";

const breadcrumbs = [{ title: "DiskSpecs", href: dashboard().url }];

interface DiskSpec {
    id: number;
    manufacturer: string;
    model_number: string;
    capacity_gb: number;
    interface: string;
    drive_type: string;
    sequential_read_mb: number;
    sequential_write_mb: number;
    stock?: Stock | null;
}

interface Stock {
    quantity: number;
    reserved?: number;
    location?: string | null;
    notes?: string | null;
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
        form.get(index.url(), { preserveState: true, preserveScroll: true });
    };

    const handleDelete = (id: number) => {
        form.delete(destroy({ diskspec: id }).url, { preserveScroll: true });
    };

    // Add stock dialog state
    const [addOpen, setAddOpen] = useState(false);
    const [targetDisk, setTargetDisk] = useState<DiskSpec | null>(null);
    const [qty, setQty] = useState<string>("1");
    const [reserved, setReserved] = useState<string>("0");
    const [location, setLocation] = useState<string>("");
    const [notes, setNotes] = useState<string>("");

    function openAddStockDialog(disk: DiskSpec) {
        setTargetDisk(disk);
        // Autofill from current stock if present
        const current = disk.stock;
        setQty(String(current?.quantity ?? 1));
        setReserved(String(current?.reserved ?? 0));
        setLocation(current?.location ?? "");
        setNotes(current?.notes ?? "");
        setAddOpen(true);
    }

    function submitAddStock() {
        if (!targetDisk) return;
        const quantity = Number(qty);
        const resv = Number(reserved);
        if (Number.isNaN(quantity) || quantity < 0) {
            toast.error("Quantity must be a non-negative number");
            return;
        }
        if (Number.isNaN(resv) || resv < 0) {
            toast.error("Reserved must be a non-negative number");
            return;
        }

        router.post(
            stocksStore().url,
            {
                type: "disk",
                stockable_id: targetDisk.id,
                quantity,
                reserved: resv,
                location: location || null,
                notes: notes || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success("Stock added");
                    setAddOpen(false);
                    setTargetDisk(null);
                    form.get(index.url(), { preserveState: true, preserveScroll: true });
                },
                onError: () => toast.error("Failed to add stock"),
            }
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Disk Specs" />

            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3">
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <form onSubmit={handleSearch} className="flex gap-2">
                        <input
                            type="text"
                            name="search"
                            placeholder="Search disk details..."
                            value={form.data.search}
                            onChange={(e) => form.setData("search", e.target.value)}
                            className="border rounded px-3 py-2 text-sm w-64 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                        <Button type="submit">Search</Button>
                    </form>

                    <Link href={create.url()}>
                        <Button className="bg-blue-600 hover:bg-blue-700 text-white">
                            Add Disk Spec
                        </Button>
                    </Link>
                </div>

                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>ID</TableHead>
                                <TableHead>Manufacturer</TableHead>
                                <TableHead>Model Number</TableHead>
                                <TableHead>Capacity (GB)</TableHead>
                                <TableHead>Interface</TableHead>
                                <TableHead>Drive Type</TableHead>
                                <TableHead>Read Speed (MB/s)</TableHead>
                                <TableHead>Write Speed (MB/s)</TableHead>
                                <TableHead>Stocks</TableHead>
                                <TableHead className="text-center">Actions</TableHead>
                            </TableRow>
                        </TableHeader>

                        <TableBody>
                            {diskspecs.data.map((disk) => (
                                <TableRow key={disk.id}>
                                    <TableCell>{disk.id}</TableCell>
                                    <TableCell className="font-medium">{disk.manufacturer}</TableCell>
                                    <TableCell>{disk.model_number}</TableCell>
                                    <TableCell>{disk.capacity_gb}</TableCell>
                                    <TableCell>{disk.interface}</TableCell>
                                    <TableCell>{disk.drive_type}</TableCell>
                                    <TableCell>{disk.sequential_read_mb}</TableCell>
                                    <TableCell>{disk.sequential_write_mb}</TableCell>
                                    <TableCell>
                                        {disk.stock ? disk.stock.quantity : 0}
                                        {(!disk.stock || disk.stock.quantity < 10) && (
                                            <span
                                                className={`ml-2 px-2 py-0.5 rounded-full text-xs font-semibold ${!disk.stock || disk.stock.quantity === 0
                                                        ? "bg-red-100 text-red-700"
                                                        : "bg-yellow-100 text-yellow-700"
                                                    }`}
                                            >
                                                {!disk.stock || disk.stock.quantity === 0
                                                    ? "Out of Stock"
                                                    : "Low Stock"}
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

                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="bg-indigo-600 hover:bg-indigo-700 text-white"
                                            onClick={() => openAddStockDialog(disk)}
                                        >
                                            Add Stock
                                        </Button>

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
                                                            {disk.manufacturer} {disk.model_number}
                                                        </strong>
                                                        ? This action cannot be undone.
                                                    </AlertDialogDescription>
                                                </AlertDialogHeader>
                                                <AlertDialogFooter>
                                                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                    <AlertDialogAction
                                                        onClick={() => handleDelete(disk.id)}
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
                                    Disk Specs List
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>

                <div className="flex justify-center">
                    <PaginationNav links={diskspecs.links} />
                </div>
            </div>

            {/* Add Stock Dialog */}
            <Dialog
                open={addOpen}
                onOpenChange={(o) => {
                    setAddOpen(o);
                    if (!o) {
                        setTargetDisk(null);
                        setQty("1");
                        setReserved("0");
                        setLocation("");
                        setNotes("");
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <h3 className="text-lg font-semibold">Add Stock</h3>
                        {targetDisk && (
                            <p className="text-sm text-muted-foreground">
                                {targetDisk.manufacturer} {targetDisk.model_number}
                            </p>
                        )}
                    </DialogHeader>

                    <div className="grid grid-cols-1 gap-3 py-2">
                        <div>
                            <Label>Quantity</Label>
                            <Input
                                type="number"
                                value={qty}
                                onChange={(e) => setQty(e.target.value)}
                                min={0}
                            />
                        </div>

                        <div>
                            <Label>Reserved</Label>
                            <Input
                                type="number"
                                value={reserved}
                                onChange={(e) => setReserved(e.target.value)}
                                min={0}
                            />
                        </div>

                        <div>
                            <Label>Location</Label>
                            <Input
                                value={location}
                                onChange={(e) => setLocation(e.target.value)}
                            />
                        </div>

                        <div>
                            <Label>Notes</Label>
                            <Input
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                            />
                        </div>
                    </div>

                    <DialogFooter className="flex gap-2">
                        <Button variant="outline" onClick={() => setAddOpen(false)}>
                            Cancel
                        </Button>
                        <Button onClick={submitAddStock}>Add</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
