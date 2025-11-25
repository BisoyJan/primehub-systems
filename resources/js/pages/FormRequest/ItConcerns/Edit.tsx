import React from "react";
import { Head, router, useForm, usePage } from "@inertiajs/react";
import AppLayout from "@/layouts/app-layout";
import { useFlashMessage, usePageLoading, usePageMeta } from "@/hooks";
import type { SharedData } from "@/types";
import { PageHeader } from "@/components/PageHeader";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue
} from "@/components/ui/select";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Can } from "@/components/authorization";

interface Site {
    id: number;
    name: string;
}

interface ItConcern {
    id: number;
    user?: {
        id: number;
        name: string;
    };
    resolved_by?: {
        id: number;
        name: string;
    } | null;
    site_id: number;
    station_number: string;
    category: string;
    description: string;
    status: string;
    priority: string;
    resolution_notes?: string;
}

interface PageProps extends SharedData {
    concern: ItConcern;
    sites: Site[];
    [key: string]: unknown;
}

export default function ItConcernEdit() {
    const { concern, sites } = usePage<PageProps>().props;

    const { title, breadcrumbs } = usePageMeta({
        title: "Edit IT Concern",
        breadcrumbs: [
            { title: "IT Concerns", href: "/form-requests/it-concerns" },
            { title: "Edit", href: "" },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const { data, setData, put, processing, errors } = useForm({
        site_id: String(concern.site_id) || "",
        station_number: concern.station_number || "",
        category: concern.category || "Hardware",
        description: concern.description || "",
        status: concern.status || "pending",
        priority: concern.priority || "medium",
        resolution_notes: concern.resolution_notes || "",
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/form-requests/it-concerns/${concern.id}`, {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isPageLoading || processing} />

                <PageHeader
                    title="Edit IT Concern"
                    description="Update IT concern details and status"
                />

                <div className="flex justify-center">
                    <div className="w-full max-w-2xl space-y-6">
                        <form onSubmit={handleSubmit} className="space-y-6">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Basic Information</CardTitle>
                                    <CardDescription>
                                        Original concern details submitted by the agent
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="space-y-2">
                                        <Label>Requested By</Label>
                                        <div className="text-sm font-medium">{concern.user?.name || 'Unknown'}</div>
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="site_id">
                                            Site <span className="text-red-500">*</span>
                                        </Label>
                                        <Select
                                            value={data.site_id}
                                            onValueChange={(value) => setData("site_id", value)}
                                        >
                                            <SelectTrigger className={errors.site_id ? "border-red-500" : ""}>
                                                <SelectValue placeholder="Select a site" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {sites.map((site) => (
                                                    <SelectItem key={site.id} value={String(site.id)}>
                                                        {site.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {errors.site_id && (
                                            <p className="text-sm text-red-500">{errors.site_id}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="station_number">
                                            Station # <span className="text-red-500">*</span>
                                        </Label>
                                        <Input
                                            id="station_number"
                                            type="text"
                                            placeholder="e.g., PH1-001"
                                            value={data.station_number}
                                            onChange={(e) => setData("station_number", e.target.value)}
                                            className={errors.station_number ? "border-red-500" : ""}
                                        />
                                        {errors.station_number && (
                                            <p className="text-sm text-red-500">{errors.station_number}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="category">
                                            Category <span className="text-red-500">*</span>
                                        </Label>
                                        <Select
                                            value={data.category}
                                            onValueChange={(value) => setData("category", value)}
                                        >
                                            <SelectTrigger className={errors.category ? "border-red-500" : ""}>
                                                <SelectValue placeholder="Choose a category" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="Hardware">
                                                    Hardware (e.g., PC, Mouse, Keyboard, etc.)
                                                </SelectItem>
                                                <SelectItem value="Software">
                                                    Software (e.g., Gensys Dialer, Krisp, VPN, etc.)
                                                </SelectItem>
                                                <SelectItem value="Network/Connectivity">
                                                    Network/Connectivity (e.g., no internet, slow internet, etc.)
                                                </SelectItem>
                                                <SelectItem value="Other">Other</SelectItem>
                                            </SelectContent>
                                        </Select>
                                        {errors.category && (
                                            <p className="text-sm text-red-500">{errors.category}</p>
                                        )}
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="priority">
                                            Priority <span className="text-red-500">*</span>
                                        </Label>
                                        <Select
                                            value={data.priority}
                                            onValueChange={(value) => setData("priority", value)}
                                        >
                                            <SelectTrigger className={errors.priority ? "border-red-500" : ""}>
                                                <SelectValue placeholder="Select priority level" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="low">Low - Can wait</SelectItem>
                                                <SelectItem value="medium">Medium - Normal priority</SelectItem>
                                                <SelectItem value="high">High - Needs attention</SelectItem>
                                                <SelectItem value="urgent">Urgent - Critical issue</SelectItem>
                                            </SelectContent>
                                        </Select>
                                        {errors.priority && (
                                            <p className="text-sm text-red-500">{errors.priority}</p>
                                        )}
                                        <p className="text-sm text-muted-foreground">
                                            Select the urgency level of your issue
                                        </p>
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="description">
                                            Description <span className="text-red-500">*</span>
                                        </Label>
                                        <Textarea
                                            id="description"
                                            placeholder="Briefly describe your IT concern"
                                            value={data.description}
                                            onChange={(e) => setData("description", e.target.value)}
                                            className={errors.description ? "border-red-500" : ""}
                                            rows={5}
                                        />
                                        {errors.description && (
                                            <p className="text-sm text-red-500">{errors.description}</p>
                                        )}
                                        <p className="text-sm text-muted-foreground">
                                            {data.description.length} / 1000 characters
                                        </p>
                                    </div>
                                </CardContent>
                            </Card>

                            <Can permission="it_concerns.assign">
                                <Card>
                                    <CardHeader>
                                        <CardTitle>Management</CardTitle>
                                        <CardDescription>
                                            Update status and assignment (IT Staff Only)
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="space-y-4">
                                        <div className="space-y-2">
                                            <Label htmlFor="status">Status</Label>
                                            <Select
                                                value={data.status}
                                                onValueChange={(value) => setData("status", value)}
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select status" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="pending">Pending</SelectItem>
                                                    <SelectItem value="in_progress">In Progress</SelectItem>
                                                    <SelectItem value="resolved">Resolved</SelectItem>
                                                    <SelectItem value="cancelled">Cancelled</SelectItem>
                                                </SelectContent>
                                            </Select>
                                            {errors.status && (
                                                <p className="text-sm text-red-500">{errors.status}</p>
                                            )}
                                        </div>

                                        {concern.resolved_by && (
                                            <div className="space-y-2">
                                                <Label>Resolved By</Label>
                                                <div className="text-sm font-medium">{concern.resolved_by.name}</div>
                                            </div>
                                        )}

                                        <div className="space-y-2">
                                            <Label htmlFor="resolution_notes">Resolution Notes</Label>
                                            <Textarea
                                                id="resolution_notes"
                                                placeholder="Add notes about the resolution"
                                                value={data.resolution_notes}
                                                onChange={(e) => setData("resolution_notes", e.target.value)}
                                                className={errors.resolution_notes ? "border-red-500" : ""}
                                                rows={4}
                                            />
                                            {errors.resolution_notes && (
                                                <p className="text-sm text-red-500">{errors.resolution_notes}</p>
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>
                            </Can>

                            <div className="flex gap-3">
                                <Button type="submit" disabled={processing}>
                                    {processing ? "Updating..." : "Update IT Concern"}
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => router.get("/form-requests/it-concerns")}
                                    disabled={processing}
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
