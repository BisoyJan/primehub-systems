import React from "react";
import { router, usePage, useForm } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import AppLayout from "@/layouts/app-layout";
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from "@/components/ui/select";
import { Input } from "@/components/ui/input";
import PcSpecTable from "@/components/PcSpecTable";
import { toast } from "sonner";

const breadcrumbs = [{ title: "Stations", href: "/stations" }];

interface Site { id: number; name: string; }
interface Campaign { id: number; name: string; }
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
        pc_spec_id: number;
    };
    sites: Site[];
    campaigns: Campaign[];
    pcSpecs: PcSpec[];
    usedPcSpecIds: number[];
    flash?: { message?: string; type?: string };
    [key: string]: unknown;
}

export default function StationEdit() {
    const { station, sites, campaigns, pcSpecs, usedPcSpecIds } = usePage<StationEditProps>().props;

    const { data, setData, put, processing, errors } = useForm({
        site_id: String(station.site_id),
        station_number: station.station_number,
        campaign_id: String(station.campaign_id),
        status: station.status,
        pc_spec_id: String(station.pc_spec_id),
    });

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
            <div className="max-w-xxl mx-auto mt-8">
                <h2 className="text-xxl font-semibold mb-4">Edit Station</h2>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
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
                            <Input
                                value={data.station_number}
                                onChange={e => setData("station_number", e.target.value.toUpperCase())}
                                placeholder="Station Number"
                                required
                            />
                            {errors.station_number && <p className="text-red-600 text-sm mt-1">{errors.station_number}</p>}
                        </div>
                        <div>
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
                    </div>
                    <div>
                        <div className="flex items-center justify-between mb-2">
                            <label className="block font-medium">Select PC Spec (Optional)</label>
                            {data.pc_spec_id && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setData("pc_spec_id", "")}
                                    className="text-xs"
                                >
                                    Clear Selection
                                </Button>
                            )}
                        </div>
                        <p className="text-xs text-gray-500 mb-2">
                            💡 Leave blank to keep station without PC spec
                        </p>
                        <PcSpecTable
                            pcSpecs={pcSpecs}
                            selectedId={data.pc_spec_id}
                            onSelect={(id) => setData("pc_spec_id", id)}
                            usedPcSpecIds={usedPcSpecIds?.filter(id => id !== station.pc_spec_id)}
                        />
                        {errors.pc_spec_id && <p className="text-red-600 text-sm mt-1">{errors.pc_spec_id}</p>}
                    </div>
                    <div className="flex gap-2 mt-4">
                        <Button type="submit" disabled={processing}>
                            {processing ? "Saving..." : "Save"}
                        </Button>
                        <Button variant="outline" type="button" onClick={() => router.get("/stations")}>Cancel</Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
