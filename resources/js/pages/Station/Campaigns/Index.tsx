import React, { useState, useEffect } from "react";
import { router, usePage } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from "@/components/ui/dialog";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import AppLayout from "@/layouts/app-layout";
import { toast } from "sonner";
import { Label } from "@/components/ui/label";

const breadcrumbs = [{ title: 'Campaign', href: '/campaigns' }];

interface Campaign {
    id: number;
    name: string;
}
interface Flash {
    message?: string;
    type?: string;
}

export default function CampaignManagement() {
    const { campaigns, flash } = usePage<{ campaigns: { data: Campaign[] }, flash?: Flash }>().props;
    const [open, setOpen] = useState(false);
    const [editCampaign, setEditCampaign] = useState<Campaign | null>(null);
    const [name, setName] = useState("");
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleteCampaign, setDeleteCampaign] = useState<Campaign | null>(null);

    useEffect(() => {
        if (flash?.message) {
            if (flash.type === 'error') toast.error(flash.message);
            else toast.success(flash.message);
        }
    }, [flash?.message, flash?.type]);

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
        if (editCampaign) {
            router.put(`/campaigns/${editCampaign.id}`, payload, options);
        } else {
            router.post(`/campaigns`, payload, options);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3">
                <div className="flex items-center gap-3 mb-4">
                    <h2 className="text-xl font-semibold">Campaign Management</h2>
                    <div className="ml-auto flex items-center gap-2">
                        <Button onClick={handleAdd} disabled={loading}>
                            {loading ? 'Loading...' : 'Add Campaign'}
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
                                {campaigns.data.map((campaign) => (
                                    <TableRow key={campaign.id}>
                                        <TableCell>{campaign.id}</TableCell>
                                        <TableCell>{campaign.name}</TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <Button variant="outline" size="sm" onClick={() => handleEdit(campaign)} disabled={loading}>
                                                    Edit
                                                </Button>
                                                <Button variant="destructive" size="sm" onClick={() => handleDelete(campaign)} className="ml-2" disabled={loading}>
                                                    Delete
                                                </Button>
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
