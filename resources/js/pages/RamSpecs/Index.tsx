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
import AppLayout from "@/layouts/app-layout";
import { dashboard } from "@/routes";
import { Head, Link, useForm, usePage } from "@inertiajs/react";
import type { PageProps as InertiaPageProps } from "@inertiajs/core";
import { Button } from "@/components/ui/button";
import { create, edit, destroy, index } from "@/routes/ramspecs";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Bell } from "lucide-react";

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
    ramSpecs: PaginatedRamSpecs;
    search?: string;
}

export default function Index() {
    const { flash, ramSpecs, search: initialSearch } = usePage<Props>().props;

    // 1. Initialize form with existing search query (if any)
    const form = useForm({ search: initialSearch || "" });

    // 2. Delete handler remains unchanged
    const handleDelete = (id: number) => {
        form.delete(destroy({ ramspec: id }).url, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    // 3. Search now uses form.get
    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        form.get(index.url(), {
            preserveState: true,
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Ram Specs" />

            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3">
                {/* Search Form */}
                <form onSubmit={handleSearch} className="flex gap-2 mb-2">
                    <input
                        type="text"
                        name="search"
                        placeholder="Search RAM details..."
                        value={form.data.search}
                        onChange={(e) => form.setData("search", e.target.value)}
                        className="border rounded px-2 py-1"
                    />
                    <Button type="submit">Search</Button>
                </form>

                <Link href={create.url()}>
                    <Button>Add Model</Button>
                </Link>

                {/* Flash Message */}
                {flash?.message && (
                    <Alert variant="default" className="my-2">
                        <Bell className="mr-2" />
                        <AlertDescription>{flash.message}</AlertDescription>
                    </Alert>
                )}

                {/* RAM Specs Table */}
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Manufacturer</TableHead>
                            <TableHead>Model</TableHead>
                            <TableHead>Form Factor</TableHead>
                            <TableHead>Voltage</TableHead>
                            <TableHead>Type</TableHead>
                            <TableHead>Capacity (GB)</TableHead>
                            <TableHead>Speed</TableHead>
                            <TableHead>Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {ramSpecs.data.map((ram) => (
                            <TableRow key={ram.id}>
                                <TableCell className="font-medium">{ram.manufacturer}</TableCell>
                                <TableCell>{ram.model}</TableCell>
                                <TableCell>{ram.form_factor}</TableCell>
                                <TableCell>{ram.voltage}</TableCell>
                                <TableCell>{ram.type}</TableCell>
                                <TableCell>{ram.capacity_gb}</TableCell>
                                <TableCell>{ram.speed}</TableCell>
                                <TableCell>
                                    <Link href={edit.url(ram.id)}>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="bg-green-600 hover:bg-green-700 text-white mr-2"
                                        >
                                            Edit
                                        </Button>
                                    </Link>
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
                                                <AlertDialogTitle>Confirm Deletion</AlertDialogTitle>
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
                            <TableCell colSpan={8} className="text-center">
                                Ram Specs List
                            </TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>

                <PaginationNav links={ramSpecs.links} className="mt-4" />
            </div>
        </AppLayout>
    );
}
