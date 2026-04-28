import React, { useEffect, useState } from "react";
import { router, useForm, Head } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import AppLayout from "@/layouts/app-layout";
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from "@/components/ui/select";
import { Input } from "@/components/ui/input";
import PcSpecTable from "@/components/PcSpecTable";
import { toast } from "sonner";
import { PageHeader } from "@/components/PageHeader";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { usePageMeta, useFlashMessage, usePageLoading } from "@/hooks";
import {
    index as stationsIndexRoute,
    edit as stationsEditRoute,
    update as stationsUpdateRoute,
} from "@/routes/stations";

interface Site { id: number; name: string; }
interface Campaign { id: number; name: string; }
interface PcSpec {
    id: number;
    pc_number?: string;
    model: string;
    ram: string;
    ram_gb?: number;
    disk: string;
    disk_gb?: number;
    processor: string;
    [key: string]: unknown;
}
interface StationEditProps {
    station: {
        id: number;
        site_id: number;
        station_number: string;
        campaign_id: number | null;
        status: string | null;
        monitor_type: string;
        pc_spec_id: number;
    };
    sites: Site[];
    campaigns: Campaign[];
    pcSpecs: PcSpec[];
    usedPcSpecIds: number[];
    flash?: { message?: string; type?: string };
    [key: string]: unknown;
}

export default function StationEdit({ station, sites, campaigns, pcSpecs, usedPcSpecIds }: StationEditProps) {
    const [showNoSpecWarning, setShowNoSpecWarning] = useState(false);
    const [showSpecSelectedInfo, setShowSpecSelectedInfo] = useState(false);

    const { data, setData, processing, errors } = useForm({
        site_id: String(station.site_id),
        station_number: station.station_number,
        campaign_id: station.campaign_id ? String(station.campaign_id) : "",
        status: station.status || "",
        monitor_type: station.monitor_type || 'single',
        pc_spec_id: String(station.pc_spec_id),
        _page: typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('page') ?? '' : '',
    });

    const { title, breadcrumbs } = usePageMeta({
        title: "Edit Station",
        breadcrumbs: [
            { title: "Stations", href: stationsIndexRoute().url },
            { title: "Edit", href: stationsEditRoute(station.id).url },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    // Auto-hide messages after 6 seconds when PC spec selection changes
    useEffect(() => {
        const hasSpec = !!data.pc_spec_id;
        setShowNoSpecWarning(!hasSpec);
        setShowSpecSelectedInfo(hasSpec);

        const timer = setTimeout(() => {
            setShowNoSpecWarning(false);
            setShowSpecSelectedInfo(false);
        }, 4000);

        return () => clearTimeout(timer);
    }, [data.pc_spec_id]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        // Use router.put with transformed data to properly handle null values
        router.put(stationsUpdateRoute(station.id).url, {
            ...data,
            pc_spec_id: data.pc_spec_id || null,
        }, {
            onSuccess: () => {
                toast.success("Station updated");
            },
            onError: (errors) => {
                const firstError = Object.values(errors)[0] as string;
                toast.error(firstError || "Validation error");
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="max-w-7xl mx-auto mt-4 p-3 md:p-6 relative">
                <LoadingOverlay isLoading={isPageLoading || processing} message={processing ? "Saving station..." : undefined} />
                <PageHeader
                    title="Edit Station"
                    description="Update assignment details, PC specs, and monitor configuration"
                />
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium mb-1">Site</label>
                            <Select value={data.site_id} onValueChange={(val) => setData("site_id", val)}>
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Select Site" />
                                </SelectTrigger>
                                <SelectContent>
                                    {sites.map((s: Site) => (
                                        <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {errors.site_id && <p className="text-red-600 text-sm mt-1">{errors.site_id}</p>}
                        </div>
                        <div>
                            <label className="block text-sm font-medium mb-1">Station Number</label>
                            <Input
                                value={data.station_number}
                                onChange={e => setData("station_number", e.target.value.toUpperCase())}
                                placeholder="Station Number"
                                required
                            />
                            {errors.station_number && <p className="text-red-600 text-sm mt-1">{errors.station_number}</p>}
                        </div>
                        <div>
                            <label className="block text-sm font-medium mb-1">Campaign (Optional)</label>
                            <Select value={data.campaign_id} onValueChange={(val) => setData("campaign_id", val === "__none__" ? "" : val)}>
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Select Campaign" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">None</SelectItem>
                                    {campaigns.map((c: Campaign) => (
                                        <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {errors.campaign_id && <p className="text-red-600 text-sm mt-1">{errors.campaign_id}</p>}
                        </div>
                        <div>
                            <label className="block text-sm font-medium mb-1">Status (Optional)</label>
                            <Select value={data.status} onValueChange={(val) => setData("status", val === "__none__" ? "" : val)}>
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Select Status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">None</SelectItem>
                                    <SelectItem value="Admin">Admin</SelectItem>
                                    <SelectItem value="Occupied">Occupied</SelectItem>
                                    <SelectItem value="Vacant">Vacant</SelectItem>
                                    <SelectItem value="No PC">No PC</SelectItem>
                                </SelectContent>
                            </Select>
                            {errors.status && <p className="text-red-600 text-sm mt-1">{errors.status}</p>}
                        </div>
                        <div>
                            <label className="block text-sm font-medium mb-1">Monitor</label>
                            <Select value={data.monitor_type} onValueChange={(val) => setData("monitor_type", val)}>
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Select Monitor" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="single">Single Monitor</SelectItem>
                                    <SelectItem value="dual">Dual Monitor</SelectItem>
                                    <SelectItem value="none">No Monitor</SelectItem>
                                </SelectContent>
                            </Select>
                            {errors.monitor_type && <p className="text-red-600 text-sm mt-1">{errors.monitor_type}</p>}
                        </div>
                    </div>
                    <div>
                        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-2 gap-2">
                            <label className="block font-medium text-sm sm:text-base">Select PC Spec (Optional)</label>
                            {data.pc_spec_id && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setData("pc_spec_id", "")}
                                    className="text-xs text-red-600 hover:text-red-700 hover:bg-red-50 w-full sm:w-auto"
                                >
                                    ✕ Remove PC Spec
                                </Button>
                            )}
                        </div>
                        {!data.pc_spec_id && showNoSpecWarning && (
                            <div className="bg-yellow-50 border border-yellow-200 rounded-md p-3 mb-3 transition-opacity duration-300">
                                <p className="text-xs sm:text-sm text-yellow-800 break-words">
                                    ⚠️ No PC spec selected - Station will be saved without a PC specification
                                </p>
                            </div>
                        )}
                        {data.pc_spec_id && showSpecSelectedInfo && (
                            <div className="bg-blue-50 border border-blue-200 rounded-md p-3 mb-3 transition-opacity duration-300">
                                <p className="text-xs sm:text-sm text-blue-800 break-words">
                                    ✓ PC spec selected - Click "Remove PC Spec" above to deselect
                                </p>
                            </div>
                        )}
                        <p className="text-xs text-gray-500 mb-2 break-words">
                            💡 You can deselect the PC spec by clicking the "Remove PC Spec" button above
                        </p>
                        <PcSpecTable
                            pcSpecs={pcSpecs}
                            selectedId={data.pc_spec_id}
                            onSelect={(id) => setData("pc_spec_id", id)}
                            usedPcSpecIds={usedPcSpecIds?.filter(id => id !== station.pc_spec_id)}
                        />
                        {errors.pc_spec_id && <p className="text-red-600 text-sm mt-1">{errors.pc_spec_id}</p>}
                    </div>

                    <div className="flex flex-col sm:flex-row gap-2 mt-4">
                        <Button type="submit" disabled={processing} className="w-full sm:w-auto">
                            {processing ? "Saving..." : "Save"}
                        </Button>
                        <Button
                            variant="outline"
                            type="button"
                            onClick={() => router.visit(stationsIndexRoute().url)}
                            className="w-full sm:w-auto"
                        >
                            Cancel
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
