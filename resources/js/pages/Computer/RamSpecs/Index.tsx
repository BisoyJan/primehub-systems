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
import { create, edit, destroy, index } from "@/routes/ramspecs";
import { store as stocksStore } from "@/routes/stocks";

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
    reserved?: number;
    location?: string | null;
    notes?: string | null;
}

interface PaginatedRamSpecs {
    data: RamSpec[];
    links: PaginationLink[];
}

interface Props extends InertiaPageProps {
    flash?: { message?: string; type?: string };
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

    // Search handler
    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        form.get(index.url(), {
            preserveState: true,
            preserveScroll: true,
        });
    };

    // Delete handler
    const handleDelete = (id: number) => {
        form.delete(destroy({ ramspec: id }).url, {
            preserveScroll: true,
        });
    };

    // Add stock dialog state
    const [addOpen, setAddOpen] = useState(false);
    const [targetRam, setTargetRam] = useState<RamSpec | null>(null);
    const [qty, setQty] = useState<string>("1");
    const [reserved, setReserved] = useState<string>("0");
    const [location, setLocation] = useState<string>("");
    const [notes, setNotes] = useState<string>("");

    function openAddStockDialog(ram: RamSpec) {
        setTargetRam(ram);
        // Autofill from current stock if present, with sensible fallbacks
        const current = ram.stock;
        setQty(String(current?.quantity ?? 1));
        setReserved(String(current?.reserved ?? 0));
        setLocation(current?.location ?? "");
        setNotes(current?.notes ?? "");
        setAddOpen(true);
    }

    function submitAddStock() {
        if (!targetRam) return;
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

        // Create or upsert a stock row for this RAM spec
        router.post(
            stocksStore().url,
            {
                type: "ram",
                stockable_id: targetRam.id,
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
                    setTargetRam(null);
                    // Refresh list to reflect updated stock badge
                    form.get(index.url(), {
                        preserveState: true,
                        preserveScroll: true,
                    });
                },
                onError: () => toast.error("Failed to add stock"),
            }
        );
    }

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

                    {/* Add RAM model */}
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
                                <TableHead>Stock</TableHead>
                                <TableHead className="text-center">Actions</TableHead>
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
                                    <TableCell>
                                        {ram.stock ? ram.stock.quantity : 0}
                                        {(!ram.stock || ram.stock.quantity < 10) && (
                                            <span
                                                className={`ml-2 px-2 py-0.5 rounded-full text-xs font-semibold ${!ram.stock || ram.stock.quantity === 0
                                                        ? "bg-red-100 text-red-700"
                                                        : "bg-yellow-100 text-yellow-700"
                                                    }`}
                                            >
                                                {!ram.stock || ram.stock.quantity === 0
                                                    ? "Out of Stock"
                                                    : "Low Stock"}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="flex justify-center gap-2">
                                        <Link href={edit.url(ram.id)}>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="bg-green-600 hover:bg-green-700 text-white"
                                            >
                                                Edit
                                            </Button>
                                        </Link>

                                        {/* Add stock button */}
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="bg-indigo-600 hover:bg-indigo-700 text-white"
                                            onClick={() => openAddStockDialog(ram)}
                                        >
                                            Add Stock
                                        </Button>

                                        {/* Delete with confirmation */}
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

            {/* Add Stock Dialog */}
            <Dialog
                open={addOpen}
                onOpenChange={(o) => {
                    setAddOpen(o);
                    if (!o) {
                        setTargetRam(null);
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
                        {targetRam && (
                            <p className="text-sm text-muted-foreground">
                                {targetRam.manufacturer} {targetRam.model}
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
                                placeholder="e.g. 10"
                            />
                        </div>

                        <div>
                            <Label>Reserved</Label>
                            <Input
                                type="number"
                                value={reserved}
                                onChange={(e) => setReserved(e.target.value)}
                                min={0}
                                placeholder="e.g. 0"
                            />
                        </div>

                        <div>
                            <Label>Location</Label>
                            <Input
                                value={location}
                                onChange={(e) => setLocation(e.target.value)}
                                placeholder="Shelf A-3"
                            />
                        </div>

                        <div>
                            <Label>Notes</Label>
                            <Input
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                placeholder="Batch arrival details"
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
