import React, { useEffect, useState } from "react";
import { router, usePage, useForm, Head } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import AppLayout from "@/layouts/app-layout";
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from "@/components/ui/select";
import { Input } from "@/components/ui/input";
import PcSpecTable from "@/components/PcSpecTable";
import MonitorSpecTable from "@/components/MonitorSpecTable";
import { toast } from "sonner";
import type { BreadcrumbItem } from "@/types";
import { index as stationsIndex } from "@/routes/stations";

const breadcrumbs: BreadcrumbItem[] = [
    { title: "Stations", href: stationsIndex().url }
];

interface Site { id: number; name: string; }
interface Campaign { id: number; name: string; }
interface MonitorSpec {
    id: number;
    brand: string;
    model: string;
    screen_size: number;
    resolution: string;
    panel_type: string;
}
interface PcSpec {
    id: number;
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
        campaign_id: number;
        status: string;
        monitor_type: string;
        pc_spec_id: number;
        monitors?: Array<{ id: number; pivot: { quantity: number } }>;
    };
    sites: Site[];
    campaigns: Campaign[];
    pcSpecs: PcSpec[];
    monitorSpecs: MonitorSpec[];
    usedPcSpecIds: number[];
    flash?: { message?: string; type?: string };
    [key: string]: unknown;
}

export default function StationEdit() {
    const { station, sites, campaigns, pcSpecs, usedPcSpecIds, monitorSpecs } = usePage<StationEditProps>().props;
    const [showNoSpecWarning, setShowNoSpecWarning] = useState(false);
    const [showSpecSelectedInfo, setShowSpecSelectedInfo] = useState(false);

    // Initialize monitor_ids from existing station monitors
    const initialMonitorIds = station.monitors?.map(m => ({
        id: m.id,
        quantity: m.pivot.quantity
    })) || [];

    const { data, setData, put, processing, errors } = useForm({
        site_id: String(station.site_id),
        station_number: station.station_number,
        campaign_id: String(station.campaign_id),
        status: station.status,
        monitor_type: station.monitor_type || 'single',
        pc_spec_id: String(station.pc_spec_id),
        monitor_ids: initialMonitorIds as Array<{ id: number; quantity: number }>,
    });

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
        put(`/stations/${station.id}`, {
            onSuccess: () => {
                toast.success("Station updated");
                router.get("/stations");
            },
            onError: (errors) => {
                const firstError = Object.values(errors)[0] as string;
                toast.error(firstError || "Validation error");
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Edit Station" />
            <div className="max-w-7xl mx-auto mt-4 p-3 md:p-6">
                <h2 className="text-xl md:text-2xl font-semibold mb-4">Edit Station</h2>
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
                            <label className="block text-sm font-medium mb-1">Campaign</label>
                            <Select value={data.campaign_id} onValueChange={(val) => setData("campaign_id", val)}>
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Select Campaign" />
                                </SelectTrigger>
                                <SelectContent>
                                    {campaigns.map((c: Campaign) => (
                                        <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {errors.campaign_id && <p className="text-red-600 text-sm mt-1">{errors.campaign_id}</p>}
                        </div>
                        <div>
                            <label className="block text-sm font-medium mb-1">Status</label>
                            <Select value={data.status} onValueChange={(val) => setData("status", val)}>
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Select Status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="Admin">Admin</SelectItem>
                                    <SelectItem value="Occupied">Occupied</SelectItem>
                                    <SelectItem value="Vacant">Vacant</SelectItem>
                                    <SelectItem value="No PC">No PC</SelectItem>
                                </SelectContent>
                            </Select>
                            {errors.status && <p className="text-red-600 text-sm mt-1">{errors.status}</p>}
                        </div>
                        <div>
                            <label className="block text-sm font-medium mb-1">Monitor Type</label>
                            <Select value={data.monitor_type} onValueChange={(val) => setData("monitor_type", val)}>
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Select Monitor Type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="single">Single Monitor</SelectItem>
                                    <SelectItem value="dual">Dual Monitor</SelectItem>
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

                    {/* Monitor Selection Section */}
                    <div>
                        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-2 gap-2">
                            <label className="block font-medium text-sm sm:text-base">
                                Select Monitor(s) (Optional)
                            </label>
                            {data.monitor_ids.length > 0 && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setData("monitor_ids", [])}
                                    className="text-xs text-red-600 hover:text-red-700 hover:bg-red-50 w-full sm:w-auto"
                                >
                                    ✕ Clear Monitor Selection
                                </Button>
                            )}
                        </div>
                        <p className="text-xs text-gray-500 mb-2 break-words">
                            💡 Monitor Type: <strong>{data.monitor_type === 'single' ? 'Single' : 'Dual'}</strong>
                            {data.monitor_type === 'dual' ? ' - You can select up to 2 monitors' : ' - Select 1 monitor'}
                        </p>

                        <MonitorSpecTable
                            monitorSpecs={monitorSpecs}
                            selectedMonitors={data.monitor_ids}
                            monitorType={data.monitor_type as 'single' | 'dual'}
                            onSelect={(monitor) => {
                                setData("monitor_ids", [...data.monitor_ids, { id: monitor.id, quantity: 1 }]);
                            }}
                            onQuantityChange={(monitorId, quantity) => {
                                setData("monitor_ids", data.monitor_ids.map(m =>
                                    m.id === monitorId ? { ...m, quantity } : m
                                ));
                            }}
                            onDeselect={(monitorId) => {
                                setData("monitor_ids", data.monitor_ids.filter(m => m.id !== monitorId));
                            }}
                        />
                        {errors.monitor_ids && <p className="text-red-600 text-sm mt-1">{errors.monitor_ids}</p>}
                    </div>

                    <div className="flex flex-col sm:flex-row gap-2 mt-4">
                        <Button type="submit" disabled={processing} className="w-full sm:w-auto">
                            {processing ? "Saving..." : "Save"}
                        </Button>
                        <Button variant="outline" type="button" onClick={() => router.get("/stations")} className="w-full sm:w-auto">Cancel</Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
