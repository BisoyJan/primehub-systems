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
import { create, edit, destroy, index } from "@/routes/processorspecs";
import { store as stocksStore } from "@/routes/stocks";

const breadcrumbs = [{ title: "ProcessorSpecs", href: dashboard().url }];

interface ProcessorSpec {
    id: number;
    brand: string;
    series: string;
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
    reserved?: number;
    location?: string | null;
    notes?: string | null;
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

    // Add stock dialog state (autofill from existing stock if present)
    const [addOpen, setAddOpen] = useState(false);
    const [targetCpu, setTargetCpu] = useState<ProcessorSpec | null>(null);
    const [qty, setQty] = useState<string>("1");
    const [reserved, setReserved] = useState<string>("0");
    const [location, setLocation] = useState<string>("");
    const [notes, setNotes] = useState<string>("");

    function openAddStockDialog(cpu: ProcessorSpec) {
        setTargetCpu(cpu);
        const current = cpu.stock;
        setQty(String(current?.quantity ?? 1));
        setReserved(String(current?.reserved ?? 0));
        setLocation(current?.location ?? "");
        setNotes(current?.notes ?? "");
        setAddOpen(true);
    }

    function submitAddStock() {
        if (!targetCpu) return;
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
                type: "processor",
                stockable_id: targetCpu.id,
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
                    setTargetCpu(null);
                    form.get(index.url(), { preserveState: true, preserveScroll: true });
                },
                onError: () => toast.error("Failed to add stock"),
            }
        );
    }

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
                            placeholder="Search processor series..."
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
                                <TableHead>Brand</TableHead>
                                <TableHead>Series</TableHead>
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
                                    <TableCell className="font-medium">{cpu.brand}</TableCell>
                                    <TableCell>{cpu.series}</TableCell>
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
                                                className={`ml-2 px-2 py-0.5 rounded-full text-xs font-semibold ${!cpu.stock || cpu.stock.quantity === 0
                                                        ? "bg-red-100 text-red-700"
                                                        : "bg-yellow-100 text-yellow-700"
                                                    }`}
                                            >
                                                {!cpu.stock || cpu.stock.quantity === 0
                                                    ? "Out of Stock"
                                                    : "Low Stock"}
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

                                        {/* Add stock button */}
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="bg-indigo-600 hover:bg-indigo-700 text-white"
                                            onClick={() => openAddStockDialog(cpu)}
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
                                                    <AlertDialogTitle>Confirm Deletion</AlertDialogTitle>
                                                    <AlertDialogDescription>
                                                        Are you sure you want to delete{" "}
                                                        <strong>{cpu.brand} {cpu.series}</strong>? This action cannot be undone.
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

            {/* Add Stock Dialog */}
            <Dialog
                open={addOpen}
                onOpenChange={(o) => {
                    setAddOpen(o);
                    if (!o) {
                        setTargetCpu(null);
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
                        {targetCpu && (
                            <p className="text-sm text-muted-foreground">
                                {targetCpu.brand} {targetCpu.series}
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
