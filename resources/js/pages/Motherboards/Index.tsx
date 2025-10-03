import { useEffect } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { toast } from 'sonner';

import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Dialog,
    DialogTrigger,
    DialogContent,
    DialogTitle,
    DialogDescription,
    DialogClose,
} from '@/components/ui/dialog';
import {
    AlertDialog,
    AlertDialogTrigger,
    AlertDialogContent,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogCancel,
    AlertDialogAction,
} from '@/components/ui/alert-dialog';
import PaginationNav, { PaginationLink } from '@/components/pagination-nav';

import {
    index as motherboardIndex,
    create as motherboardCreate,
    edit as motherboardEdit,
    destroy as motherboardDestroy,
} from '@/routes/motherboards';

const breadcrumbs = [{ title: "MotherboardSpecs", href: motherboardIndex().url }];

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
interface DiskSpec {
    id: number;
    manufacturer: string;
    model_number: string;
    capacity_gb: number;
    interface: string;
    drive_type: string;
    sequential_read_mb: number;
    sequential_write_mb: number;
}
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
}
interface Motherboard {
    id: number;
    brand: string;
    model: string;
    chipset: string;
    memory_type: string;
    ramSpecs: RamSpec[];
    diskSpecs: DiskSpec[];
    processorSpecs: ProcessorSpec[];
}

type PageProps = {
    motherboards: { data: Motherboard[]; links: PaginationLink[] };
    flash?: { message?: string; type?: string };
    search?: string;
};

export default function Index() {
    const { motherboards, flash, search: initialSearch } =
        usePage<PageProps>().props;
    const form = useForm({ search: initialSearch || '' });

    useEffect(() => {
        if (!flash?.message) return;
        if (flash.type === "error") {
            toast.error(flash.message);
        } else {
            toast.success(flash.message);
        }
    }, [flash?.message, flash?.type]);

    function handleSearch(e: React.FormEvent) {
        e.preventDefault();
        form.get(motherboardIndex.url(), {
            preserveState: true,
            preserveScroll: true,
        });
    }

    function handleDelete(id: number) {
        form.delete(
            motherboardDestroy({ motherboard: id }).url, {
            preserveScroll: true,
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Motherboards" />

            {/* Search & Add */}
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3">
                <form onSubmit={handleSearch} className="flex gap-2 mb-2">
                    <input
                        type="text"
                        name="search"
                        placeholder="Search by model…"
                        value={form.data.search}
                        onChange={(e) => form.setData('search', e.target.value)}
                        className="border rounded px-2 py-1"
                    />
                    <Button type="submit">Search</Button>
                </form>
                <Link href={motherboardCreate.url()}>
                    <Button>Add Motherboard</Button>
                </Link>


                {/* Specs Table */}
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>ID</TableHead>
                            <TableHead>Brand</TableHead>
                            <TableHead>Model</TableHead>
                            <TableHead>Chipset</TableHead>
                            <TableHead>Memory</TableHead>
                            <TableHead>Actions</TableHead>
                        </TableRow>
                    </TableHeader>

                    <TableBody>
                        {motherboards.data.map((mb) => (
                            <TableRow key={mb.id}>
                                <TableCell>{mb.id}</TableCell>
                                <TableCell>{mb.brand}</TableCell>
                                <TableCell>{mb.model}</TableCell>
                                <TableCell>{mb.chipset}</TableCell>
                                <TableCell>{mb.memory_type}</TableCell>
                                <TableCell className="space-x-2">
                                    {/* Edit */}
                                    <Link href={motherboardEdit.url(mb.id)}>
                                        <Button size="sm">Edit</Button> {/* TODO when pressing the edit button,the popover for ramSpecs, diskSpecs, processorSpecs is not showing the already selected options. */}
                                    </Link>

                                    {/* Details Dialog */}
                                    <Dialog>
                                        <DialogTrigger asChild>
                                            <Button variant="outline" size="sm">
                                                Details
                                            </Button>
                                        </DialogTrigger>
                                        <DialogContent className="max-w-lg">
                                            <DialogTitle>
                                                {mb.brand} {mb.model} Specs
                                            </DialogTitle>
                                            <DialogDescription>
                                                <section className="mt-4">
                                                    <h3 className="font-medium">RAM Specs</h3>
                                                    {mb.ramSpecs?.length ? (
                                                        <ul className="list-disc ml-5">
                                                            {mb.ramSpecs.map((r) => (
                                                                <li key={r.id}>
                                                                    {r.manufacturer} {r.model} – {r.capacity_gb}GB {r.type} @ {r.speed}
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    ) : (
                                                        <p className="text-sm text-muted-foreground">No RAM specs available.</p>
                                                    )}
                                                </section>

                                                <section className="mt-4">
                                                    <h3 className="font-medium">Disk Specs</h3>
                                                    {mb.diskSpecs?.length ? (
                                                        <ul className="list-disc ml-5">
                                                            {mb.diskSpecs.map((d) => (
                                                                <li key={d.id}>
                                                                    {d.manufacturer} {d.model_number} – {d.capacity_gb}GB {d.drive_type} ({d.interface})
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    ) : (
                                                        <p className="text-sm text-muted-foreground">No disk specs available.</p>
                                                    )}
                                                </section>

                                                <section className="mt-4">
                                                    <h3 className="font-medium">Processor Specs</h3>
                                                    {mb.processorSpecs?.length ? (
                                                        <ul className="list-disc ml-5">
                                                            {mb.processorSpecs.map((p) => (
                                                                <li key={p.id}>
                                                                    {p.brand} {p.series} ({p.socket_type})
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    ) : (
                                                        <p className="text-sm text-muted-foreground">No processor specs available.</p>
                                                    )}
                                                </section>
                                            </DialogDescription>
                                            <DialogClose asChild>
                                                <Button className="mt-4">Close</Button>
                                            </DialogClose>
                                        </DialogContent>
                                    </Dialog>

                                    {/* Delete */}
                                    <AlertDialog>
                                        <AlertDialogTrigger asChild>
                                            <Button variant="destructive" size="sm">
                                                Delete
                                            </Button>
                                        </AlertDialogTrigger>
                                        <AlertDialogContent>
                                            <AlertDialogHeader>
                                                <AlertDialogTitle>Confirm Delete</AlertDialogTitle>
                                                <AlertDialogDescription>
                                                    Are you sure you want to delete {mb.brand} {mb.model}? This
                                                    action cannot be undone.
                                                </AlertDialogDescription>
                                            </AlertDialogHeader>
                                            <AlertDialogFooter>
                                                <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                <AlertDialogAction
                                                    className="bg-red-600 hover:bg-red-700"
                                                    onClick={() => handleDelete(mb.id)}
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
                            <TableCell colSpan={6} className="text-center">
                                Page {motherboards.links.find((l) => l.active)?.label} of{' '}
                                {motherboards.links.length - 2}
                            </TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>

                <PaginationNav links={motherboards.links} className="mt-4" />
            </div>
        </AppLayout>
    );
}
