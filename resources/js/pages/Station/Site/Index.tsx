import React, { useState, useEffect } from "react";
import { router, usePage } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { dashboard } from '@/routes';
import {
    //index as siteIndex,
    store as siteStore,
    update as siteUpdate,
} from '@/routes/sites';
import AppLayout from "@/layouts/app-layout";
import { toast } from "sonner";
import { Label } from "@/components/ui/label";

const breadcrumbs = [{ title: 'Site', href: dashboard().url }];

interface Site {
    id: number;
    name: string;
}

interface Flash {
    message?: string;
    type?: string;
}

export default function SiteManagement() {
    // Fix: Use correct prop name for paginated data
    const { sites, flash } = usePage<{ sites: { data: Site[] }, flash?: Flash }>().props;
    const [open, setOpen] = useState(false);
    const [editSite, setEditSite] = useState<Site | null>(null);
    const [name, setName] = useState("");
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleteSite, setDeleteSite] = useState<Site | null>(null);

    useEffect(() => {
        if (flash?.message) {
            if (flash.type === 'error') toast.error(flash.message);
            else toast.success(flash.message);
        }
    }, [flash?.message, flash?.type]);

    // Add new site
    const handleAdd = () => {
        setEditSite(null);
        setName("");
        setError(null);
        setOpen(true);
    };

    // Edit site
    const handleEdit = (site: Site) => {
        setEditSite(site);
        setName(site.name);
        setError(null);
        setOpen(true);
    };

    // Delete site (open dialog)
    const handleDelete = (site: Site) => {
        setDeleteSite(site);
        setDeleteOpen(true);
    };

    // Confirm delete
    const confirmDelete = () => {
        if (!deleteSite) return;
        setLoading(true);
        router.delete(`/sites/${deleteSite.id}`, {
            onFinish: () => {
                setLoading(false);
                setDeleteOpen(false);
                setDeleteSite(null);
            },
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    // Save (add or update)
    const handleSave = (e: React.FormEvent) => {
        e.preventDefault();
        setLoading(true);
        setError(null);
        const payload = { name };
        const options = {
            onFinish: () => {
                setLoading(false);
                setOpen(false);
            },
            preserveState: true,
            preserveScroll: true,
            replace: true,
        };
        if (editSite) {
            router.put(siteUpdate(editSite.id).url, payload, options);
        } else {
            router.post(siteStore().url, payload, options);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3">
                <div className="flex items-center gap-3 mb-4">
                    <h2 className="text-xl font-semibold">Site Management</h2>
                    <div className="ml-auto flex items-center gap-2">
                        <Button onClick={handleAdd} disabled={loading}>
                            {loading ? 'Loading...' : 'Add Site'}
                        </Button>
                    </div>
                </div>
                <div className="shadow rounded-md overflow-hidden">
                    <div className="overflow-x-auto ">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>ID</TableHead>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {sites.data.map((site) => (
                                    <TableRow key={site.id}>
                                        <TableCell>{site.id}</TableCell>
                                        <TableCell>{site.name}</TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <Button variant="outline" size="sm" onClick={() => handleEdit(site)} disabled={loading}>
                                                    Edit
                                                </Button>
                                                <Button variant="destructive" size="sm" onClick={() => handleDelete(site)} className="ml-2" disabled={loading}>
                                                    Delete
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {sites.data.length === 0 && !loading && (
                                    <TableRow>
                                        <TableCell colSpan={3} className="py-8 text-center text-gray-500">
                                            No sites found
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </div>
                {/* Pagination can be added here if needed */}
            </div>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{editSite ? "Edit Site" : "Add Site"}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleSave} className="space-y-4">
                        <Label htmlFor="site-name">Name</Label>
                        <Input
                            id="site-name"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            required
                            placeholder="Site name"
                            disabled={loading}
                        />
                        {error && <div className="text-red-500 text-sm">{error}</div>}
                        <DialogFooter>
                            <Button type="submit" disabled={loading}>
                                {loading ? 'Saving...' : 'Save'}
                            </Button>
                            <Button variant="outline" type="button" onClick={() => setOpen(false)} disabled={loading}>
                                Cancel
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
            <Dialog open={deleteOpen} onOpenChange={setDeleteOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Confirm Delete</DialogTitle>
                    </DialogHeader>
                    <div className="py-2 text-sm">
                        {deleteSite && (
                            <>Are you sure you want to delete site <b>{deleteSite.name}</b>? This action cannot be undone.</>
                        )}
                    </div>
                    <DialogFooter className="flex gap-2">
                        <Button variant="outline" onClick={() => setDeleteOpen(false)} disabled={loading}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={confirmDelete} disabled={loading}>
                            Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
