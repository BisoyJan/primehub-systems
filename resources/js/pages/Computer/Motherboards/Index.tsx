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
    model: string;
    capacity_gb: number;
    interface: string;
    drive_type: string;
    sequential_read_mb: number;
    sequential_write_mb: number;
}
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
}
interface Motherboard {
    id: number;
    manufacturer: string;
    model: string;
    chipset: string;
    memory_type: string;
    form_factor: string;
    socket_type: string;
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

            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3">
                {/* Header Section */}
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    {/* Search Form */}
                    <form onSubmit={handleSearch} className="flex gap-2">
                        <input
                            type="text"
                            name="search"
                            placeholder="Search by model…"
                            value={form.data.search}
                            onChange={(e) => form.setData("search", e.target.value)}
                            className="border rounded px-3 py-2 text-sm w-64 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                        <Button type="submit">Search</Button>
                    </form>

                    {/* Add Button */}
                    <Link href={motherboardCreate.url()}>
                        <Button className="bg-blue-600 hover:bg-blue-700 text-white">
                            Add Motherboard
                        </Button>
                    </Link>
                </div>

                {/* Specs Table */}
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>ID</TableHead>
                                <TableHead>Manufacturer</TableHead>
                                <TableHead>Model</TableHead>
                                <TableHead>Chipset</TableHead>
                                <TableHead>Memory</TableHead>
                                <TableHead className="text-center">Actions</TableHead>
                            </TableRow>
                        </TableHeader>

                        <TableBody>
                            {motherboards.data.map((mb) => (
                                <TableRow key={mb.id}>
                                    <TableCell>{mb.id}</TableCell>
                                    <TableCell>{mb.manufacturer}</TableCell>
                                    <TableCell>{mb.model}</TableCell>
                                    <TableCell>{mb.chipset}</TableCell>
                                    <TableCell>{mb.memory_type}</TableCell>
                                    <TableCell className="flex justify-center gap-2">
                                        {/* Edit */}
                                        <Link href={motherboardEdit.url(mb.id)}>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="bg-green-600 hover:bg-green-700 text-white"
                                            >
                                                Edit
                                            </Button>
                                        </Link>

                                        {/* Details Dialog */}
                                        <Dialog>
                                            <DialogTrigger asChild>
                                                <Button variant="outline" size="sm">
                                                    Details
                                                </Button>
                                            </DialogTrigger>
                                            <DialogContent className="max-w-7xl w-full h-[90vh] flex flex-col">
                                                <DialogTitle className="text-xl font-semibold">
                                                    {mb.manufacturer} {mb.model} — Full Specifications
                                                </DialogTitle>

                                                {/* Scrollable body */}
                                                <div className="flex-1 overflow-y-auto pr-2 mt-4 space-y-6 text-sm">
                                                    {/* Motherboard Core Info */}
                                                    <section>
                                                        <h3 className="font-semibold text-base mb-2">Motherboard Details</h3>
                                                        <div className="grid grid-cols-2 gap-x-6 gap-y-2">
                                                            <p><span className="font-medium">manufacturer:</span> {mb.manufacturer}</p>
                                                            <p><span className="font-medium">Model:</span> {mb.model}</p>
                                                            <p><span className="font-medium">Chipset:</span> {mb.chipset}</p>
                                                            <p><span className="font-medium">Memory Type:</span> {mb.memory_type}</p>
                                                            <p><span className="font-medium">Form Factor:</span> {mb.form_factor ?? "—"}</p>
                                                            <p><span className="font-medium">Socket Type:</span> {mb.socket_type ?? "—"}</p>
                                                        </div>
                                                    </section>

                                                    {/* RAM Specs */}
                                                    <section>
                                                        <h3 className="font-semibold text-base mb-2">RAM Specs</h3>
                                                        {mb.ramSpecs?.length ? (
                                                            <Table>
                                                                <TableHeader>
                                                                    <TableRow>
                                                                        <TableHead>Manufacturer</TableHead>
                                                                        <TableHead>Model</TableHead>
                                                                        <TableHead>Capacity</TableHead>
                                                                        <TableHead>Type</TableHead>
                                                                        <TableHead>Speed</TableHead>
                                                                    </TableRow>
                                                                </TableHeader>
                                                                <TableBody>
                                                                    {mb.ramSpecs.map((r) => (
                                                                        <TableRow key={r.id}>
                                                                            <TableCell>{r.manufacturer}</TableCell>
                                                                            <TableCell>{r.model}</TableCell>
                                                                            <TableCell>{r.capacity_gb} GB</TableCell>
                                                                            <TableCell>{r.type}</TableCell>
                                                                            <TableCell>{r.speed}</TableCell>
                                                                        </TableRow>
                                                                    ))}
                                                                </TableBody>
                                                            </Table>
                                                        ) : (
                                                            <p className="text-muted-foreground">No RAM specs available.</p>
                                                        )}
                                                    </section>

                                                    {/* Disk Specs */}
                                                    <section>
                                                        <h3 className="font-semibold text-base mb-2">Disk Specs</h3>
                                                        {mb.diskSpecs?.length ? (
                                                            <Table>
                                                                <TableHeader>
                                                                    <TableRow>
                                                                        <TableHead>Manufacturer</TableHead>
                                                                        <TableHead>Model</TableHead>
                                                                        <TableHead>Capacity</TableHead>
                                                                        <TableHead>Type</TableHead>
                                                                        <TableHead>Interface</TableHead>
                                                                        <TableHead>Read</TableHead>
                                                                        <TableHead>Write</TableHead>
                                                                    </TableRow>
                                                                </TableHeader>
                                                                <TableBody>
                                                                    {mb.diskSpecs.map((d) => (
                                                                        <TableRow key={d.id}>
                                                                            <TableCell>{d.manufacturer}</TableCell>
                                                                            <TableCell>{d.model}</TableCell>
                                                                            <TableCell>{d.capacity_gb} GB</TableCell>
                                                                            <TableCell>{d.drive_type}</TableCell>
                                                                            <TableCell>{d.interface}</TableCell>
                                                                            <TableCell>{d.sequential_read_mb} MB/s</TableCell>
                                                                            <TableCell>{d.sequential_write_mb} MB/s</TableCell>
                                                                        </TableRow>
                                                                    ))}
                                                                </TableBody>
                                                            </Table>
                                                        ) : (
                                                            <p className="text-muted-foreground">No disk specs available.</p>
                                                        )}
                                                    </section>

                                                    {/* Processor Specs */}
                                                    <section>
                                                        <h3 className="font-semibold text-base mb-2">Processor Specs</h3>
                                                        {mb.processorSpecs?.length ? (
                                                            <Table>
                                                                <TableHeader>
                                                                    <TableRow>
                                                                        <TableHead>manufacturer</TableHead>
                                                                        <TableHead>model</TableHead>
                                                                        <TableHead>Socket</TableHead>
                                                                        <TableHead>Cores</TableHead>
                                                                        <TableHead>Threads</TableHead>
                                                                        <TableHead>Base Clock</TableHead>
                                                                        <TableHead>Boost Clock</TableHead>
                                                                        <TableHead>TDP</TableHead>
                                                                    </TableRow>
                                                                </TableHeader>
                                                                <TableBody>
                                                                    {mb.processorSpecs.map((p) => (
                                                                        <TableRow key={p.id}>
                                                                            <TableCell>{p.manufacturer}</TableCell>
                                                                            <TableCell>{p.model}</TableCell>
                                                                            <TableCell>{p.socket_type}</TableCell>
                                                                            <TableCell>{p.core_count}</TableCell>
                                                                            <TableCell>{p.thread_count}</TableCell>
                                                                            <TableCell>{p.base_clock_ghz} GHz</TableCell>
                                                                            <TableCell>{p.boost_clock_ghz} GHz</TableCell>
                                                                            <TableCell>{p.tdp_watts} W</TableCell>
                                                                        </TableRow>
                                                                    ))}
                                                                </TableBody>
                                                            </Table>
                                                        ) : (
                                                            <p className="text-muted-foreground">No processor specs available.</p>
                                                        )}
                                                    </section>
                                                </div>

                                                {/* Footer */}
                                                <DialogClose asChild>
                                                    <Button className="mt-4 self-end">Close</Button>
                                                </DialogClose>
                                            </DialogContent>
                                        </Dialog>

                                        {/* Delete */}
                                        <AlertDialog>
                                            <AlertDialogTrigger asChild>
                                                <Button
                                                    variant="destructive"
                                                    className="bg-red-600 hover:bg-red-700 text-white"
                                                >
                                                    Delete
                                                </Button>
                                            </AlertDialogTrigger>
                                            <AlertDialogContent>
                                                <AlertDialogHeader>
                                                    <AlertDialogTitle>Confirm Delete</AlertDialogTitle>
                                                    <AlertDialogDescription>
                                                        Are you sure you want to delete {mb.manufacturer} {mb.model}? This action cannot be undone.
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
                                <TableCell colSpan={6} className="text-center font-medium">
                                    Motherboard Specs List
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>

                {/* Pagination */}
                <div className="flex justify-center">
                    <PaginationNav links={motherboards.links} />
                </div>
            </div>
        </AppLayout>
    );
}
