import React, { useState, useEffect, useRef } from "react";
import { router, usePage, Head } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import AppLayout from "@/layouts/app-layout";
import { toast } from "sonner";
import { Label } from "@/components/ui/label";
import PaginationNav, { PaginationLink } from "@/components/pagination-nav";
import type { BreadcrumbItem } from "@/types";
import { index as campaignsIndex } from "@/routes/campaigns";
import { Can } from "@/components/authorization";

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Campaigns', href: campaignsIndex().url }
];

interface Campaign {
    id: number;
    name: string;
}

interface PaginatedCampaigns {
    data: Campaign[];
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

export default function CampaignManagement() {
    const { campaigns, flash, filters = {} } = usePage<{ campaigns: PaginatedCampaigns, flash?: Flash, filters?: Filters }>().props;
    const [open, setOpen] = useState(false);
    const [editCampaign, setEditCampaign] = useState<Campaign | null>(null);
    const [name, setName] = useState("");
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleteCampaign, setDeleteCampaign] = useState<Campaign | null>(null);

    // Search state
    const [search, setSearch] = useState(filters.search || "");
    const [debouncedSearch, setDebouncedSearch] = useState(filters.search || "");
    const isInitialMount = useRef(true);

    useEffect(() => {
        if (flash?.message) {
            if (flash.type === 'error') toast.error(flash.message);
            else toast.success(flash.message);
        }
    }, [flash?.message, flash?.type]);

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
        router.get("/campaigns", params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => setLoading(false),
        });
    }, [debouncedSearch]);

    // Add new campaign
    const handleAdd = () => {
        setEditCampaign(null);
        setName("");
        setError(null);
        setOpen(true);
    };

    // Edit campaign
    const handleEdit = (campaign: Campaign) => {
        setEditCampaign(campaign);
        setName(campaign.name);
        setError(null);
        setOpen(true);
    };

    // Delete campaign (open dialog)
    const handleDelete = (campaign: Campaign) => {
        setDeleteCampaign(campaign);
        setDeleteOpen(true);
    };

    // Confirm delete
    const confirmDelete = () => {
        if (!deleteCampaign) return;
        setLoading(true);
        router.delete(`/campaigns/${deleteCampaign.id}`, {
            onFinish: () => {
                setLoading(false);
                setDeleteOpen(false);
                setDeleteCampaign(null);
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
        const isDuplicate = campaigns.data.some(campaign =>
            campaign.name.toLowerCase() === trimmedName.toLowerCase() &&
            campaign.id !== editCampaign?.id
        );

        if (isDuplicate) {
            setError(`A campaign with the name "${trimmedName}" already exists.`);
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
        if (editCampaign) {
            router.put(`/campaigns/${editCampaign.id}`, payload, options);
        } else {
            router.post(`/campaigns`, payload, options);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Campaign Management" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3">
                <div className="flex flex-col gap-3 mb-4">
                    <div className="flex items-center justify-between">
                        <h2 className="text-xl font-semibold">Campaign Management</h2>
                        <Can permission="campaigns.create">
                            <Button onClick={handleAdd} disabled={loading}>
                                {loading ? 'Loading...' : 'Add Campaign'}
                            </Button>
                        </Can>
                    </div>

                    {/* Search Input */}
                    <Input
                        type="search"
                        placeholder="Search campaigns by name..."
                        value={search}
                        onChange={e => setSearch(e.target.value)}
                        className="max-w-md"
                    />

                    {/* Clear search button */}
                    {search && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setSearch("")}
                            className="w-fit"
                        >
                            Clear Search
                        </Button>
                    )}
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
                                {campaigns.data.map((campaign) => (
                                    <TableRow key={campaign.id}>
                                        <TableCell>{campaign.id}</TableCell>
                                        <TableCell>{campaign.name}</TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <Can permission="campaigns.edit">
                                                    <Button variant="outline" size="sm" onClick={() => handleEdit(campaign)} disabled={loading}>
                                                        Edit
                                                    </Button>
                                                </Can>
                                                <Can permission="campaigns.delete">
                                                    <Button variant="destructive" size="sm" onClick={() => handleDelete(campaign)} className="ml-2" disabled={loading}>
                                                        Delete
                                                    </Button>
                                                </Can>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {campaigns.data.length === 0 && !loading && (
                                    <TableRow>
                                        <TableCell colSpan={3} className="py-8 text-center text-gray-500">
                                            No campaigns found
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </div>

                {/* Pagination */}
                {campaigns.links && campaigns.links.length > 0 && (
                    <div className="flex justify-center mt-4">
                        <PaginationNav links={campaigns.links} />
                    </div>
                )}

                {/* Results count */}
                {campaigns.meta && (
                    <div className="text-sm text-muted-foreground text-center">
                        Showing {campaigns.data.length} of {campaigns.meta.total} campaign{campaigns.meta.total !== 1 ? 's' : ''}
                    </div>
                )}
            </div>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{editCampaign ? "Edit Campaign" : "Add Campaign"}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleSave} className="space-y-4">
                        <Label htmlFor="campaign-name">Name</Label>
                        <Input
                            id="campaign-name"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            required
                            placeholder="Campaign name"
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
                        {deleteCampaign && (
                            <>Are you sure you want to delete campaign <b>{deleteCampaign.name}</b>? This action cannot be undone.</>
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
