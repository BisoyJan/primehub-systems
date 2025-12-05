import React, { useState, useEffect, useRef } from "react";
import { router, Head } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import {
    index as sitesIndex,
    store as siteStore,
    update as siteUpdate,
    destroy as siteDestroy,
} from '@/routes/sites';
import AppLayout from "@/layouts/app-layout";
import { Label } from "@/components/ui/label";
import PaginationNav, { PaginationLink } from "@/components/pagination-nav";
import { Can } from "@/components/authorization";
import { PageHeader } from "@/components/PageHeader";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { usePageMeta, useFlashMessage, usePageLoading } from "@/hooks";
import { index as stationsIndexRoute } from "@/routes/stations";
import { RefreshCw, Search, Plus, Play, Pause } from 'lucide-react';

interface Site {
    id: number;
    name: string;
}

interface PaginatedSites {
    data: Site[];
    links: PaginationLink[];
    meta?: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}

interface Flash {
    message?: string;
    type?: string;
}

interface Filters {
    search?: string;
}

interface SiteManagementProps {
    sites: PaginatedSites;
    flash?: Flash;
    filters?: Filters;
}

export default function SiteManagement({ sites, filters = {} }: SiteManagementProps) {
    const [open, setOpen] = useState(false);
    const [editSite, setEditSite] = useState<Site | null>(null);
    const [name, setName] = useState("");
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleteSite, setDeleteSite] = useState<Site | null>(null);
    const [lastRefresh, setLastRefresh] = useState<Date>(new Date());
    const [autoRefreshEnabled, setAutoRefreshEnabled] = useState(false);

    // Search state
    const [search, setSearch] = useState(filters.search || "");
    const [debouncedSearch, setDebouncedSearch] = useState(filters.search || "");
    const isInitialMount = useRef(true);

    const { title, breadcrumbs } = usePageMeta({
        title: 'Site Management',
        breadcrumbs: [
            { title: 'Stations', href: stationsIndexRoute().url },
            { title: 'Sites', href: sitesIndex().url },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    // Debounce search input
    useEffect(() => {
        const timer = setTimeout(() => setDebouncedSearch(search), 500);
        return () => clearTimeout(timer);
    }, [search]);

    // Handle search changes
    useEffect(() => {
        // Skip the effect on initial mount to avoid duplicate requests
        if (isInitialMount.current) {
            isInitialMount.current = false;
            return;
        }

        const params: Record<string, string> = {};
        if (debouncedSearch) params.search = debouncedSearch;

        setLoading(true);
        router.get(sitesIndex().url, params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: () => setLastRefresh(new Date()),
            onFinish: () => setLoading(false),
        });
    }, [debouncedSearch]);

    // Auto-refresh every 30 seconds
    useEffect(() => {
        if (!autoRefreshEnabled) return;
        const interval = setInterval(() => {
            const params: Record<string, string> = {};
            if (debouncedSearch) params.search = debouncedSearch;

            router.get(sitesIndex().url, params, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['sites'],
                onSuccess: () => setLastRefresh(new Date()),
            });
        }, 30000);

        return () => clearInterval(interval);
    }, [autoRefreshEnabled, debouncedSearch]);

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
        router.delete(siteDestroy(deleteSite.id).url, {
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

        // Check for duplicate names (case-insensitive)
        const trimmedName = name.trim();
        const isDuplicate = sites.data.some(site =>
            site.name.toLowerCase() === trimmedName.toLowerCase() &&
            site.id !== editSite?.id
        );

        if (isDuplicate) {
            setError(`A site with the name "${trimmedName}" already exists.`);
            setLoading(false);
            return;
        }

        const payload = { name: trimmedName };
        const options = {
            onFinish: () => {
                setLoading(false);
                setOpen(false);
            },
            onError: (errors: Record<string, string | string[]>) => {
                // Handle backend validation errors
                if (errors.name) {
                    setError(Array.isArray(errors.name) ? errors.name[0] : errors.name);
                }
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

    const handleManualRefresh = () => {
        setLoading(true);
        router.reload({
            only: ['sites'],
            onSuccess: () => setLastRefresh(new Date()),
            onFinish: () => setLoading(false),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isPageLoading || loading} message={loading ? 'Processing...' : undefined} />

                <PageHeader
                    title="Site Management"
                    description="Manage the list of available site locations"
                />

                <div className="flex flex-col gap-4">
                    <div className="flex flex-col sm:flex-row gap-4 justify-between items-start sm:items-center">
                        <div className="w-full sm:w-auto flex-1 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            <div className="relative">
                                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search sites by name..."
                                    value={search}
                                    onChange={e => setSearch(e.target.value)}
                                    className="pl-8"
                                />
                            </div>
                        </div>

                        <div className="flex flex-wrap gap-2 w-full sm:w-auto">
                            {search && (
                                <Button variant="outline" onClick={() => setSearch("")} className="flex-1 sm:flex-none">
                                    Reset
                                </Button>
                            )}

                            <div className="flex gap-2">
                                <Button variant="ghost" size="icon" onClick={handleManualRefresh} title="Refresh">
                                    <RefreshCw className="h-4 w-4" />
                                </Button>
                                <Button
                                    variant={autoRefreshEnabled ? "default" : "ghost"}
                                    size="icon"
                                    onClick={() => setAutoRefreshEnabled(!autoRefreshEnabled)}
                                    title={autoRefreshEnabled ? "Disable auto-refresh" : "Enable auto-refresh (30s)"}
                                >
                                    {autoRefreshEnabled ? <Pause className="h-4 w-4" /> : <Play className="h-4 w-4" />}
                                </Button>
                            </div>

                            <Can permission="sites.create">
                                <Button onClick={handleAdd} disabled={loading} className="flex-1 sm:flex-none">
                                    <Plus className="mr-2 h-4 w-4" />
                                    Add Site
                                </Button>
                            </Can>
                        </div>
                    </div>

                    <div className="flex justify-between items-center text-sm">
                        <div className="text-muted-foreground">
                            Showing {sites.data.length} of {sites.meta?.total || 0} site{sites.meta?.total !== 1 ? 's' : ''}
                        </div>
                        <div className="text-xs text-muted-foreground">Last updated: {lastRefresh.toLocaleTimeString()}</div>
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
                                                <Can permission="sites.edit">
                                                    <Button variant="outline" size="sm" onClick={() => handleEdit(site)} disabled={loading}>
                                                        Edit
                                                    </Button>
                                                </Can>
                                                <Can permission="sites.delete">
                                                    <Button variant="destructive" size="sm" onClick={() => handleDelete(site)} className="ml-2" disabled={loading}>
                                                        Delete
                                                    </Button>
                                                </Can>
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

                {/* Pagination */}
                {sites.links && sites.links.length > 0 && (
                    <div className="flex justify-center mt-4">
                        <PaginationNav links={sites.links} />
                    </div>
                )}


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
