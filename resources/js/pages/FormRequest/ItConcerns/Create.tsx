import React from "react";
import { Head, router, useForm, usePage } from "@inertiajs/react";
import AppLayout from "@/layouts/app-layout";
import { useFlashMessage, usePageLoading, usePageMeta } from "@/hooks";
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

interface Site {
    id: number;
    name: string;
}

interface PageProps {
    sites: Site[];
    [key: string]: unknown;
}

export default function ItConcernCreate() {
    const { sites } = usePage<PageProps>().props;

    const { title, breadcrumbs } = usePageMeta({
        title: "Submit IT Concern",
        breadcrumbs: [
            { title: "IT Concerns", href: "/it-concerns" },
            { title: "Create", href: "" },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const { data, setData, post, processing, errors } = useForm({
        site_id: "",
        station_number: "",
        category: "Hardware",
        description: "",
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post("/it-concerns", {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isPageLoading || processing} />

                <PageHeader
                    title="Submit IT Concern"
                    description="Report an IT issue for assistance"
                />

                <div className="flex justify-center">
                    <Card className="w-full max-w-2xl">
                        <CardHeader>
                            <CardTitle>IT Concern Details</CardTitle>
                            <CardDescription>
                                Please provide detailed information about your IT issue
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-6">
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

                                <div className="flex gap-3 pt-4">
                                    <Button type="submit" disabled={processing}>
                                        {processing ? "Submitting..." : "Submit IT Concern"}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => router.get("/it-concerns")}
                                        disabled={processing}
                                    >
                                        Cancel
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
