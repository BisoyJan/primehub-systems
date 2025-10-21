import { useEffect, useState } from 'react';
import { Head, Link, useForm, usePage, router } from '@inertiajs/react';
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
    DialogHeader,
    DialogDescription,
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
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import PaginationNav, { PaginationLink } from '@/components/pagination-nav';

import type { BreadcrumbItem } from '@/types';
import {
    index as pcSpecIndex,
    create as pcSpecCreate,
    edit as pcSpecEdit,
    destroy as pcSpecDestroy,
} from '@/routes/pcspecs';

const breadcrumbs: BreadcrumbItem[] = [
    { title: "PC Specifications", href: pcSpecIndex().url }
];

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
interface PcSpec {
    id: number;
    pc_number?: string | null;
    manufacturer: string;
    model: string;
    chipset: string;
    memory_type: string;
    form_factor: string;
    socket_type: string;
    issue?: string | null;
    ramSpecs: RamSpec[];
    diskSpecs: DiskSpec[];
    processorSpecs: ProcessorSpec[];
}


type PageProps = {
    pcspecs: { data: PcSpec[]; links: PaginationLink[] };
    flash?: { message?: string; type?: string };
    search?: string;
};

export default function Index() {
    const { pcspecs, flash, search: initialSearch } =
        usePage<PageProps>().props;
    const form = useForm({ search: initialSearch || '' });
    const [issueDialogOpen, setIssueDialogOpen] = useState(false);
    const [selectedPcSpec, setSelectedPcSpec] = useState<PcSpec | null>(null);
    const [issueText, setIssueText] = useState('');

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
        form.get(pcSpecIndex.url(), {
            preserveState: true,
            preserveScroll: true,
        });
    }

    function handleDelete(id: number) {
        form.delete(
            pcSpecDestroy({ pcspec: id }).url, {
            preserveScroll: true,
        });
    }

    function handleOpenIssueDialog(pcSpec: PcSpec) {
        setSelectedPcSpec(pcSpec);
        setIssueText(pcSpec.issue || '');
        setIssueDialogOpen(true);
    }

    function handleSaveIssue() {
        if (!selectedPcSpec) return;

        router.patch(`/pcspecs/${selectedPcSpec.id}/issue`, {
            issue: issueText || null,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Issue updated successfully');
                setIssueDialogOpen(false);
            },
            onError: () => {
                toast.error('Failed to update issue');
            },
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="PC Specs" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 md:p-6">
                {/* Header Section */}
                <div className="flex flex-col gap-3">
                    <h2 className="text-lg md:text-xl font-semibold">PC Specs Management</h2>

                    {/* Search Form */}
                    <form onSubmit={handleSearch} className="flex gap-2">
                        <input
                            type="text"
                            name="search"
                            placeholder="Search by model…"
                            value={form.data.search}
                            onChange={(e) => form.setData("search", e.target.value)}
                            className="border rounded px-3 py-2 text-sm w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                        <Button type="submit" className="shrink-0">Search</Button>
                    </form>

                    {/* Add Button */}
                    <Link href={pcSpecCreate.url()} className="w-full sm:w-auto sm:self-end">
                        <Button className="bg-blue-600 hover:bg-blue-700 text-white w-full sm:w-auto">
                            Add PC Spec
                        </Button>
                    </Link>
                </div>

                {/* Desktop Table */}
                <div className="hidden md:block shadow rounded-md overflow-hidden">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="hidden lg:table-cell">ID</TableHead>
                                    <TableHead>PC Number</TableHead>
                                    <TableHead>Manufacturer</TableHead>
                                    <TableHead>Model</TableHead>
                                    <TableHead className="hidden xl:table-cell">Processor</TableHead>
                                    <TableHead>RAM (GB)</TableHead>
                                    <TableHead className="hidden xl:table-cell">Storage Type</TableHead>
                                    <TableHead>Issue</TableHead>
                                    <TableHead className="text-center">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {pcspecs.data.map((pc) => {
                                    // Get first processor
                                    const proc = pc.processorSpecs?.[0];
                                    const procLabel = proc ? `${proc.manufacturer} ${proc.model}` : '—';

                                    // Total RAM GB
                                    const totalRamGb = pc.ramSpecs?.reduce((sum, r) => sum + (r.capacity_gb || 0), 0);

                                    // Storage Type: show SSD if any disk is SSD, else HDD if any disk is HDD, else —
                                    let storageType = '—';
                                    if (pc.diskSpecs?.length) {
                                        const hasSSD = pc.diskSpecs.some(d => (d.drive_type || '').toUpperCase().includes('SSD'));
                                        const hasHDD = pc.diskSpecs.some(d => (d.drive_type || '').toUpperCase().includes('HDD'));
                                        if (hasSSD && hasHDD) storageType = 'SSD + HDD';
                                        else if (hasSSD) storageType = 'SSD';
                                        else if (hasHDD) storageType = 'HDD';
                                    }

                                    return (
                                        <TableRow key={pc.id}>
                                            <TableCell className="hidden lg:table-cell">{pc.id}</TableCell>
                                            <TableCell className="font-medium">
                                                {pc.pc_number || <span className="text-gray-400">—</span>}
                                            </TableCell>
                                            <TableCell>{pc.manufacturer}</TableCell>
                                            <TableCell>{pc.model}</TableCell>
                                            <TableCell className="hidden xl:table-cell">{procLabel}</TableCell>
                                            <TableCell>{totalRamGb || 0}</TableCell>
                                            <TableCell className="hidden xl:table-cell">{storageType}</TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    {pc.issue ? (
                                                        <span className="text-xs text-red-600 font-medium truncate max-w-[200px]" title={pc.issue}>
                                                            ⚠️ {pc.issue}
                                                        </span>
                                                    ) : (
                                                        <span className="text-xs text-gray-400">No issue</span>
                                                    )}
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => handleOpenIssueDialog(pc)}
                                                        className="h-7 px-2 text-xs"
                                                    >
                                                        {pc.issue ? 'Edit' : 'Add'}
                                                    </Button>
                                                </div>
                                            </TableCell>
                                            <TableCell className="flex justify-center gap-2">
                                                {/* Edit */}
                                                <Link href={pcSpecEdit.url(pc.id)}>
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
                                                    <DialogContent className="max-w-[95vw] sm:max-w-7xl w-full h-[90vh] flex flex-col">
                                                        <DialogTitle className="text-lg sm:text-xl font-semibold break-words">
                                                            {pc.manufacturer} {pc.model} — Full Specifications
                                                        </DialogTitle>

                                                        {/* Scrollable body */}
                                                        <div className="flex-1 overflow-y-auto pr-2 mt-4 space-y-6 text-sm">
                                                            {/* Motherboard Core Info */}
                                                            <section>
                                                                <h3 className="font-semibold text-base mb-2">PC Spec Details</h3>
                                                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2">
                                                                    <p><span className="font-medium">Manufacturer:</span> {pc.manufacturer}</p>
                                                                    <p><span className="font-medium">Model:</span> {pc.model}</p>
                                                                    <p><span className="font-medium">Memory Type:</span> {pc.memory_type}</p>
                                                                    <p><span className="font-medium">Form Factor:</span> {pc.form_factor ?? "—"}</p>
                                                                </div>
                                                            </section>

                                                            {/* RAM Specs */}
                                                            <section>
                                                                <h3 className="font-semibold text-base mb-2">RAM Specs</h3>
                                                                {pc.ramSpecs?.length ? (
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
                                                                            {pc.ramSpecs.map((r) => (
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
                                                                {pc.diskSpecs?.length ? (
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
                                                                            {pc.diskSpecs.map((d) => (
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
                                                                {pc.processorSpecs?.length ? (
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
                                                                            {pc.processorSpecs.map((p) => (
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
                                                                Are you sure you want to delete {pc.manufacturer} {pc.model}? This action cannot be undone.
                                                            </AlertDialogDescription>
                                                        </AlertDialogHeader>
                                                        <AlertDialogFooter className="flex-col sm:flex-row gap-2">
                                                            <AlertDialogCancel className="w-full sm:w-auto">Cancel</AlertDialogCancel>
                                                            <AlertDialogAction
                                                                className="bg-red-600 hover:bg-red-700 w-full sm:w-auto"
                                                                onClick={() => handleDelete(pc.id)}
                                                            >
                                                                Yes, Delete
                                                            </AlertDialogAction>
                                                        </AlertDialogFooter>
                                                    </AlertDialogContent>
                                                </AlertDialog>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>

                            <TableFooter>
                                <TableRow>
                                    <TableCell colSpan={9} className="text-center font-medium">
                                        PC Specs List
                                    </TableCell>
                                </TableRow>
                            </TableFooter>
                        </Table>
                    </div>
                </div>

                {/* Mobile Card View */}
                <div className="md:hidden space-y-4">
                    {pcspecs.data.map((pc) => {
                        const proc = pc.processorSpecs?.[0];
                        const procLabel = proc ? `${proc.manufacturer} ${proc.model}` : '—';
                        const totalRamGb = pc.ramSpecs?.reduce((sum, r) => sum + (r.capacity_gb || 0), 0);
                        let storageType = '—';
                        if (pc.diskSpecs?.length) {
                            const hasSSD = pc.diskSpecs.some(d => (d.drive_type || '').toUpperCase().includes('SSD'));
                            const hasHDD = pc.diskSpecs.some(d => (d.drive_type || '').toUpperCase().includes('HDD'));
                            if (hasSSD && hasHDD) storageType = 'SSD + HDD';
                            else if (hasSSD) storageType = 'SSD';
                            else if (hasHDD) storageType = 'HDD';
                        }

                        return (
                            <div key={pc.id} className="bg-card border rounded-lg p-4 shadow-sm space-y-3">
                                <div className="flex justify-between items-start">
                                    <div>
                                        <div className="text-xs text-muted-foreground">PC Number</div>
                                        <div className="font-bold text-blue-600">
                                            {pc.pc_number || <span className="text-gray-400">Not assigned</span>}
                                        </div>
                                    </div>
                                    <div className="text-right">
                                        <div className="text-xs text-muted-foreground">ID</div>
                                        <div className="font-medium text-sm">#{pc.id}</div>
                                    </div>
                                </div>

                                <div className="flex justify-between items-start">
                                    <div>
                                        <div className="text-xs text-muted-foreground">Manufacturer</div>
                                        <div className="font-semibold text-lg">{pc.manufacturer}</div>
                                    </div>
                                    <div className="text-right">
                                        <div className="text-xs text-muted-foreground">Model</div>
                                        <div className="font-medium text-sm">{pc.model}</div>
                                    </div>
                                </div>

                                <div className="space-y-2 text-sm">
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Processor:</span>
                                        <span className="font-medium text-right break-words max-w-[60%]">{procLabel}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">RAM:</span>
                                        <span className="font-medium">{totalRamGb || 0} GB</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Storage:</span>
                                        <span className="font-medium">{storageType}</span>
                                    </div>
                                    <div className="pt-2 border-t">
                                        <div className="flex justify-between items-start gap-2">
                                            <span className="text-muted-foreground">Issue:</span>
                                            <div className="flex items-center gap-2 flex-1 justify-end">
                                                {pc.issue ? (
                                                    <span className="text-xs text-red-600 font-medium truncate max-w-[120px]" title={pc.issue}>
                                                        ⚠️ {pc.issue}
                                                    </span>
                                                ) : (
                                                    <span className="text-xs text-gray-400">No issue</span>
                                                )}
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => handleOpenIssueDialog(pc)}
                                                    className="h-7 px-2 text-xs"
                                                >
                                                    {pc.issue ? 'Edit' : 'Add'}
                                                </Button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div className="flex flex-col gap-2 pt-2 border-t">
                                    <div className="flex gap-2">
                                        <Link href={pcSpecEdit.url(pc.id)} className="flex-1">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="bg-green-600 hover:bg-green-700 text-white w-full"
                                            >
                                                Edit
                                            </Button>
                                        </Link>
                                        <Dialog>
                                            <DialogTrigger asChild>
                                                <Button variant="outline" size="sm" className="flex-1">
                                                    Details
                                                </Button>
                                            </DialogTrigger>
                                            <DialogContent className="max-w-[95vw] sm:max-w-7xl w-full h-[90vh] flex flex-col">
                                                <DialogTitle className="text-lg sm:text-xl font-semibold break-words">
                                                    {pc.manufacturer} {pc.model} — Full Specifications
                                                </DialogTitle>

                                                <div className="flex-1 overflow-y-auto pr-2 mt-4 space-y-6 text-sm">
                                                    <section>
                                                        <h3 className="font-semibold text-base mb-2">PC Spec Details</h3>
                                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2">
                                                            <p><span className="font-medium">Manufacturer:</span> {pc.manufacturer}</p>
                                                            <p><span className="font-medium">Model:</span> {pc.model}</p>
                                                            <p><span className="font-medium">Memory Type:</span> {pc.memory_type}</p>
                                                            <p><span className="font-medium">Form Factor:</span> {pc.form_factor ?? "—"}</p>
                                                        </div>
                                                    </section>

                                                    <section>
                                                        <h3 className="font-semibold text-base mb-2">RAM Specs</h3>
                                                        {pc.ramSpecs?.length ? (
                                                            <div className="space-y-2">
                                                                {pc.ramSpecs.map((r) => (
                                                                    <div key={r.id} className="border p-3 rounded text-sm">
                                                                        <div className="font-medium">{r.manufacturer} {r.model}</div>
                                                                        <div className="text-muted-foreground">
                                                                            {r.capacity_gb} GB • {r.type} • {r.speed}
                                                                        </div>
                                                                    </div>
                                                                ))}
                                                            </div>
                                                        ) : (
                                                            <p className="text-muted-foreground">No RAM specs available.</p>
                                                        )}
                                                    </section>

                                                    <section>
                                                        <h3 className="font-semibold text-base mb-2">Disk Specs</h3>
                                                        {pc.diskSpecs?.length ? (
                                                            <div className="space-y-2">
                                                                {pc.diskSpecs.map((d) => (
                                                                    <div key={d.id} className="border p-3 rounded text-sm">
                                                                        <div className="font-medium">{d.manufacturer} {d.model}</div>
                                                                        <div className="text-muted-foreground">
                                                                            {d.capacity_gb} GB • {d.drive_type} • {d.interface}
                                                                        </div>
                                                                        <div className="text-muted-foreground text-xs">
                                                                            Read: {d.sequential_read_mb} MB/s • Write: {d.sequential_write_mb} MB/s
                                                                        </div>
                                                                    </div>
                                                                ))}
                                                            </div>
                                                        ) : (
                                                            <p className="text-muted-foreground">No disk specs available.</p>
                                                        )}
                                                    </section>

                                                    <section>
                                                        <h3 className="font-semibold text-base mb-2">Processor Specs</h3>
                                                        {pc.processorSpecs?.length ? (
                                                            <div className="space-y-2">
                                                                {pc.processorSpecs.map((p) => (
                                                                    <div key={p.id} className="border p-3 rounded text-sm">
                                                                        <div className="font-medium">{p.manufacturer} {p.model}</div>
                                                                        <div className="text-muted-foreground">
                                                                            {p.socket_type} • {p.core_count} cores / {p.thread_count} threads
                                                                        </div>
                                                                        <div className="text-muted-foreground text-xs">
                                                                            {p.base_clock_ghz} GHz - {p.boost_clock_ghz} GHz • TDP: {p.tdp_watts}W
                                                                        </div>
                                                                    </div>
                                                                ))}
                                                            </div>
                                                        ) : (
                                                            <p className="text-muted-foreground">No processor specs available.</p>
                                                        )}
                                                    </section>
                                                </div>

                                                <DialogClose asChild>
                                                    <Button className="mt-4 self-end">Close</Button>
                                                </DialogClose>
                                            </DialogContent>
                                        </Dialog>
                                    </div>
                                    <AlertDialog>
                                        <AlertDialogTrigger asChild>
                                            <Button
                                                variant="destructive"
                                                className="bg-red-600 hover:bg-red-700 text-white w-full"
                                            >
                                                Delete
                                            </Button>
                                        </AlertDialogTrigger>
                                        <AlertDialogContent className="max-w-[90vw] sm:max-w-lg">
                                            <AlertDialogHeader>
                                                <AlertDialogTitle>Confirm Delete</AlertDialogTitle>
                                                <AlertDialogDescription>
                                                    Are you sure you want to delete {pc.manufacturer} {pc.model}? This action cannot be undone.
                                                </AlertDialogDescription>
                                            </AlertDialogHeader>
                                            <AlertDialogFooter className="flex-col sm:flex-row gap-2">
                                                <AlertDialogCancel className="w-full sm:w-auto">Cancel</AlertDialogCancel>
                                                <AlertDialogAction
                                                    className="bg-red-600 hover:bg-red-700 w-full sm:w-auto"
                                                    onClick={() => handleDelete(pc.id)}
                                                >
                                                    Yes, Delete
                                                </AlertDialogAction>
                                            </AlertDialogFooter>
                                        </AlertDialogContent>
                                    </AlertDialog>
                                </div>
                            </div>
                        );
                    })}
                    {pcspecs.data.length === 0 && (
                        <div className="py-12 text-center text-gray-500 border rounded-lg bg-card">
                            No PC specs found
                        </div>
                    )}
                </div>

                {/* Pagination */}
                <div className="flex justify-center">
                    <PaginationNav links={pcspecs.links} />
                </div>

                {/* Issue Dialog */}
                <Dialog open={issueDialogOpen} onOpenChange={setIssueDialogOpen}>
                    <DialogContent className="max-w-[90vw] sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>Manage Issue Note</DialogTitle>
                            <DialogDescription>
                                {selectedPcSpec && (
                                    <span className="text-sm break-words">
                                        {selectedPcSpec.manufacturer} {selectedPcSpec.model}
                                    </span>
                                )}
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-4 py-4">
                            <div className="space-y-2">
                                <Label htmlFor="issue">Issue Details</Label>
                                <Textarea
                                    id="issue"
                                    placeholder="Describe any issues with this PC spec..."
                                    value={issueText}
                                    onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setIssueText(e.target.value)}
                                    rows={5}
                                    className="resize-none"
                                />
                                <p className="text-xs text-gray-500">
                                    Leave empty to remove the issue note.
                                </p>
                            </div>
                        </div>
                        <div className="flex flex-col sm:flex-row justify-end gap-2">
                            <Button variant="outline" onClick={() => setIssueDialogOpen(false)} className="w-full sm:w-auto">
                                Cancel
                            </Button>
                            <Button onClick={handleSaveIssue} className="w-full sm:w-auto">
                                Save Issue
                            </Button>
                        </div>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
