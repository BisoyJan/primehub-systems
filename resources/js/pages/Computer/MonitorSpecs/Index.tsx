import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { PageProps as InertiaPageProps } from "@inertiajs/core";

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
} from '@/components/ui/dialog';
import { Can } from '@/components/authorization';
import { usePermission } from '@/hooks/useAuthorization';
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

// Hooks and components
import { usePageMeta, useFlashMessage, usePageLoading } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { SearchBar } from "@/components/SearchBar";
import { LoadingOverlay } from "@/components/LoadingOverlay";

import {
    index as monitorSpecIndex,
    create as monitorSpecCreate,
    edit as monitorSpecEdit,
    destroy as monitorSpecDestroy,
} from '@/routes/monitorspecs';

interface MonitorSpec {
    id: number;
    brand: string;
    model: string;
    screen_size: number;
    resolution: string;
    panel_type: string;
    ports: string[] | null;
    notes: string | null;
    stock?: {
        id: number;
        quantity: number;
        reserved: number;
        location: string | null;
    };
}

interface PaginatedMonitorSpecs {
    data: MonitorSpec[];
    links: PaginationLink[];
}

interface Props extends InertiaPageProps {
    flash?: { message?: string; type?: string };
    monitorspecs: PaginatedMonitorSpecs;
    search?: string;
}

export default function Index() {
    const { monitorspecs, search: initialSearch } = usePage<Props>().props;
    const form = useForm({ search: initialSearch || '' });

    // Use hooks for cleaner code
    const { title, breadcrumbs } = usePageMeta({
        title: "Monitor Specifications",
        breadcrumbs: [{ title: "Monitor Specifications", href: monitorSpecIndex().url }]
    });

    useFlashMessage();
    const isLoading = usePageLoading();
    const { can } = usePermission(); // Check permissions

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        form.get(monitorSpecIndex.url(), {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleDelete = (id: number) => {
        form.delete(monitorSpecDestroy({ monitorspec: id }).url, {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isLoading} />

                <PageHeader
                    title="Monitor Specs Management"
                    description="Manage monitor specifications for PC configurations"
                    createLink={can("hardware.create") ? monitorSpecCreate.url() : undefined}
                    createLabel="Add Monitor Spec"
                >
                    <SearchBar
                        value={form.data.search}
                        onChange={(value) => form.setData("search", value)}
                        onSubmit={handleSearch}
                        placeholder="Search monitors..."
                    />
                </PageHeader>

                {/* Desktop Table */}
                <div className="hidden md:block shadow rounded-md overflow-hidden">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="hidden lg:table-cell">ID</TableHead>
                                    <TableHead>Brand</TableHead>
                                    <TableHead>Model</TableHead>
                                    <TableHead>Screen Size</TableHead>
                                    <TableHead>Resolution</TableHead>
                                    <TableHead className="hidden xl:table-cell">Panel Type</TableHead>
                                    <TableHead className="hidden xl:table-cell">Stock</TableHead>
                                    <TableHead className="text-center">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {monitorspecs.data.map((monitor) => (
                                    <TableRow key={monitor.id}>
                                        <TableCell className="hidden lg:table-cell">{monitor.id}</TableCell>
                                        <TableCell className="font-medium">{monitor.brand}</TableCell>
                                        <TableCell>{monitor.model}</TableCell>
                                        <TableCell>{monitor.screen_size}"</TableCell>
                                        <TableCell>{monitor.resolution}</TableCell>
                                        <TableCell className="hidden xl:table-cell">{monitor.panel_type}</TableCell>
                                        <TableCell className="hidden xl:table-cell">
                                            {monitor.stock ? (
                                                <span className={monitor.stock.quantity > 0 ? 'text-green-600 font-medium' : 'text-gray-400'}>
                                                    {monitor.stock.quantity} units
                                                </span>
                                            ) : (
                                                <span className="text-gray-400">No stock</span>
                                            )}
                                        </TableCell>
                                        <TableCell className="flex justify-center gap-2">
                                            {/* Edit */}
                                            <Can permission="hardware.edit">
                                                <Link href={monitorSpecEdit.url(monitor.id)}>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        className="bg-green-600 hover:bg-green-700 text-white"
                                                    >
                                                        Edit
                                                    </Button>
                                                </Link>
                                            </Can>

                                            {/* Details Dialog */}
                                            <Dialog>
                                                <DialogTrigger asChild>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                    >
                                                        Details
                                                    </Button>
                                                </DialogTrigger>
                                                <DialogContent className="max-w-[95vw] sm:max-w-2xl">
                                                    <DialogHeader>
                                                        <DialogTitle className="text-lg sm:text-xl font-semibold">
                                                            {monitor.brand} {monitor.model}
                                                        </DialogTitle>
                                                    </DialogHeader>

                                                    <div className="space-y-4 mt-4">
                                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                                            <div>
                                                                <span className="font-medium">Brand:</span> {monitor.brand}
                                                            </div>
                                                            <div>
                                                                <span className="font-medium">Model:</span> {monitor.model}
                                                            </div>
                                                            <div>
                                                                <span className="font-medium">Screen Size:</span> {monitor.screen_size}"
                                                            </div>
                                                            <div>
                                                                <span className="font-medium">Resolution:</span> {monitor.resolution}
                                                            </div>
                                                            <div>
                                                                <span className="font-medium">Panel Type:</span> {monitor.panel_type}
                                                            </div>
                                                            <div>
                                                                <span className="font-medium">Ports:</span>{' '}
                                                                {monitor.ports?.join(', ') || 'Not specified'}
                                                            </div>
                                                            <div>
                                                                <span className="font-medium">Stock:</span>{' '}
                                                                <span className={monitor.stock?.quantity ? 'text-green-600' : 'text-gray-400'}>
                                                                    {monitor.stock ? `${monitor.stock.quantity} units` : 'No stock'}
                                                                </span>
                                                            </div>
                                                            {monitor.stock?.location && (
                                                                <div>
                                                                    <span className="font-medium">Location:</span> {monitor.stock.location}
                                                                </div>
                                                            )}
                                                        </div>
                                                        {monitor.notes && (
                                                            <div className="pt-2 border-t">
                                                                <span className="font-medium">Notes:</span>
                                                                <p className="mt-1 text-sm text-muted-foreground">{monitor.notes}</p>
                                                            </div>
                                                        )}
                                                    </div>

                                                    <DialogClose asChild>
                                                        <Button className="mt-4 self-end">Close</Button>
                                                    </DialogClose>
                                                </DialogContent>
                                            </Dialog>

                                            {/* Delete */}
                                            <Can permission="hardware.delete">
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
                                                            <AlertDialogTitle>Confirm Delete</AlertDialogTitle>
                                                            <AlertDialogDescription>
                                                                Are you sure you want to delete {monitor.brand} {monitor.model}? This action cannot be undone.
                                                            </AlertDialogDescription>
                                                        </AlertDialogHeader>
                                                        <AlertDialogFooter className="flex-col sm:flex-row gap-2">
                                                            <AlertDialogCancel className="w-full sm:w-auto">Cancel</AlertDialogCancel>
                                                            <AlertDialogAction
                                                                className="bg-red-600 hover:bg-red-700 w-full sm:w-auto"
                                                                onClick={() => handleDelete(monitor.id)}
                                                            >
                                                                Yes, Delete
                                                            </AlertDialogAction>
                                                        </AlertDialogFooter>
                                                    </AlertDialogContent>
                                                </AlertDialog>
                                            </Can>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>

                            <TableFooter>
                                <TableRow>
                                    <TableCell colSpan={8} className="text-center font-medium">
                                        Monitor Specs List
                                    </TableCell>
                                </TableRow>
                            </TableFooter>
                        </Table>
                    </div>
                </div>

                {/* Mobile Card View */}
                <div className="md:hidden space-y-4">
                    {monitorspecs.data.map((monitor) => (
                        <div key={monitor.id} className="bg-card border rounded-lg p-4 shadow-sm space-y-3">
                            <div className="flex justify-between items-start">
                                <div>
                                    <div className="text-xs text-muted-foreground">Brand</div>
                                    <div className="font-bold text-lg">{monitor.brand}</div>
                                </div>
                                <div className="text-right">
                                    <div className="text-xs text-muted-foreground">ID</div>
                                    <div className="font-medium text-sm">#{monitor.id}</div>
                                </div>
                            </div>

                            <div className="space-y-2 text-sm">
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Model:</span>
                                    <span className="font-medium">{monitor.model}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Screen Size:</span>
                                    <span className="font-medium">{monitor.screen_size}"</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Resolution:</span>
                                    <span className="font-medium">{monitor.resolution}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Panel:</span>
                                    <span className="font-medium">{monitor.panel_type}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Stock:</span>
                                    <span className={monitor.stock?.quantity ? 'font-medium text-green-600' : 'text-gray-400'}>
                                        {monitor.stock ? `${monitor.stock.quantity} units` : 'No stock'}
                                    </span>
                                </div>
                            </div>

                            <div className="flex flex-col gap-2 pt-2 border-t">
                                <div className="flex gap-2">
                                    <Can permission="hardware.edit">
                                        <Link href={monitorSpecEdit.url(monitor.id)} className="flex-1">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="bg-green-600 hover:bg-green-700 text-white w-full"
                                            >
                                                Edit
                                            </Button>
                                        </Link>
                                    </Can>
                                    <Dialog>
                                        <DialogTrigger asChild>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="flex-1"
                                            >
                                                Details
                                            </Button>
                                        </DialogTrigger>
                                        <DialogContent className="max-w-[95vw]">
                                            <DialogHeader>
                                                <DialogTitle>
                                                    {monitor.brand} {monitor.model}
                                                </DialogTitle>
                                            </DialogHeader>

                                            <div className="space-y-3 text-sm mt-4">
                                                <div>
                                                    <span className="font-medium">Screen Size:</span> {monitor.screen_size}"
                                                </div>
                                                <div>
                                                    <span className="font-medium">Resolution:</span> {monitor.resolution}
                                                </div>
                                                <div>
                                                    <span className="font-medium">Panel Type:</span> {monitor.panel_type}
                                                </div>
                                                <div>
                                                    <span className="font-medium">Ports:</span>{' '}
                                                    {monitor.ports?.join(', ') || 'Not specified'}
                                                </div>
                                                <div>
                                                    <span className="font-medium">Stock:</span>{' '}
                                                    <span className={monitor.stock?.quantity ? 'text-green-600' : 'text-gray-400'}>
                                                        {monitor.stock ? `${monitor.stock.quantity} units` : 'No stock'}
                                                    </span>
                                                </div>
                                                {monitor.stock?.location && (
                                                    <div>
                                                        <span className="font-medium">Location:</span> {monitor.stock.location}
                                                    </div>
                                                )}
                                                {monitor.notes && (
                                                    <div className="pt-2 border-t">
                                                        <span className="font-medium">Notes:</span>
                                                        <p className="mt-1 text-muted-foreground">{monitor.notes}</p>
                                                    </div>
                                                )}
                                            </div>

                                            <DialogClose asChild>
                                                <Button className="mt-4 w-full">Close</Button>
                                            </DialogClose>
                                        </DialogContent>
                                    </Dialog>
                                </div>
                                <Can permission="hardware.delete">
                                    <AlertDialog>
                                        <AlertDialogTrigger asChild>
                                            <Button
                                                variant="destructive"
                                                size="sm"
                                                className="bg-red-600 hover:bg-red-700 text-white w-full"
                                            >
                                                Delete
                                            </Button>
                                        </AlertDialogTrigger>
                                        <AlertDialogContent className="max-w-[90vw] sm:max-w-lg">
                                            <AlertDialogHeader>
                                                <AlertDialogTitle>Confirm Delete</AlertDialogTitle>
                                                <AlertDialogDescription>
                                                    Are you sure you want to delete {monitor.brand} {monitor.model}?
                                                </AlertDialogDescription>
                                            </AlertDialogHeader>
                                            <AlertDialogFooter className="flex-col sm:flex-row gap-2">
                                                <AlertDialogCancel className="w-full sm:w-auto">Cancel</AlertDialogCancel>
                                                <AlertDialogAction
                                                    className="bg-red-600 hover:bg-red-700 w-full sm:w-auto"
                                                    onClick={() => handleDelete(monitor.id)}
                                                >
                                                    Yes, Delete
                                                </AlertDialogAction>
                                            </AlertDialogFooter>
                                        </AlertDialogContent>
                                    </AlertDialog>
                                </Can>
                            </div>
                        </div>
                    ))}
                    {monitorspecs.data.length === 0 && (
                        <div className="py-12 text-center text-gray-500 border rounded-lg bg-card">
                            No monitor specs found
                        </div>
                    )}
                </div>

                {/* Pagination */}
                <div className="flex justify-center">
                    <PaginationNav links={monitorspecs.links} />
                </div>
            </div>
        </AppLayout>
    );
}
