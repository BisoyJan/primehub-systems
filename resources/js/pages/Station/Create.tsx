import React, { useState } from "react";
import { router, usePage } from "@inertiajs/react";
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
    ram?: string;
    ram_gb?: number;
    disk?: string;
    disk_gb?: number;
    processor?: string;
    [key: string]: unknown;
}

export default function StationCreate() {
    const { sites, campaigns, pcSpecs, usedPcSpecIds } = usePage<{ sites: Site[]; campaigns: Campaign[]; pcSpecs: PcSpec[]; usedPcSpecIds: number[] }>().props;
    const [siteId, setSiteId] = useState("");
    const [stationNumber, setStationNumber] = useState("");
    const [campaignId, setCampaignId] = useState("");
    const [status, setStatus] = useState("");
    const [pcSpecId, setPcSpecId] = useState("");
    const [loading, setLoading] = useState(false);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setLoading(true);
        router.post("/stations", {
            site_id: siteId,
            station_number: stationNumber,
            campaign_id: campaignId,
            status,
            pc_spec_id: pcSpecId,
        }, {
            onFinish: () => setLoading(false),
            onSuccess: () => {
                toast.success("Station created");
                router.get("/stations");
            },
            onError: () => toast.error("Validation error"),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="max-w-xxl mx-auto mt-8">
                <h2 className="text-xxl font-semibold mb-4">Add Station</h2>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <Select value={siteId} onValueChange={setSiteId}>
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Select Site" />
                            </SelectTrigger>
                            <SelectContent>
                                {sites.map((s: Site) => (
                                    <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Input
                            value={stationNumber}
                            onChange={e => setStationNumber(e.target.value)}
                            placeholder="Station Number"
                            required
                        />
                        <Select value={campaignId} onValueChange={setCampaignId}>
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Select Campaign" />
                            </SelectTrigger>
                            <SelectContent>
                                {campaigns.map((c: Campaign) => (
                                    <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select value={status} onValueChange={setStatus}>
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
                    </div>
                    <div>
                        <label className="block font-medium mb-2">Select PC Spec</label>
                        <PcSpecTable
                            pcSpecs={pcSpecs}
                            selectedId={pcSpecId}
                            onSelect={setPcSpecId}
                            usedPcSpecIds={usedPcSpecIds}
                        />
                    </div>
                    <div className="flex gap-2 mt-4">
                        <Button type="submit" disabled={loading}>
                            {loading ? "Saving..." : "Save"}
                        </Button>
                        <Button variant="outline" type="button" onClick={() => router.get("/stations")}>Cancel</Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
