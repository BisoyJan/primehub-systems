import { useEffect, useState } from "react";
import { Head, router } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import AppLayout from "@/layouts/app-layout";
import { PageHeader } from "@/components/PageHeader";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { useFlashMessage, usePageLoading, usePageMeta } from "@/hooks";
import {
    index as stationsIndexRoute,
    edit as stationsEditRoute,
} from "@/routes/stations";
import { transferPage } from "@/routes/pc-transfers";

interface Monitor {
    id: number;
    brand: string;
    model: string;
    screen_size: number;
    resolution: string;
    panel_type: string;
    quantity: number;
}
interface Site { id: number; name: string; }
interface Campaign { id: number; name: string; }
interface PcSpec {
    id: number;
    model: string;
    processor: string;
    ram: string;
    disk: string;
    pc_number?: string;
    ram_gb?: number;
    disk_gb?: number;
    ram_capacities?: string;
    disk_capacities?: string;
    ram_ddr?: string;
    disk_type?: string;
    issue?: string | null;
}
interface Station {
    id: number;
    station_number: string | number;
    site?: Site;
    campaign?: Campaign;
    status: string;
    monitor_type: string;
    pcSpec?: PcSpec;
    monitors?: Monitor[];
    created_at?: string;
    updated_at?: string;
}

export default function ScanResult({ stationId, station: initialStation, error: initialError }: { stationId?: number, station?: Station, error?: string }) {
    const [station, setStation] = useState<Station | null>(initialStation ?? null);
    const [error, setError] = useState<string | null>(initialError ?? null);
    const [isFetching, setIsFetching] = useState(false);

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const { title, breadcrumbs } = usePageMeta({
        title: station ? `Station #${station.station_number}` : "Station Scan",
        breadcrumbs: [{ title: "Stations", href: stationsIndexRoute().url }],
    });

    useEffect(() => {
        if (!station && !error && stationId) {
            setIsFetching(true);
            fetch(`/stations/${stationId}/json`)
                .then(res => {
                    if (!res.ok) throw new Error('Not found');
                    return res.json();
                })
                .then(setStation)
                .catch(() => setError('Station not found or you are not authorized.'))
                .finally(() => setIsFetching(false));
        }
    }, [stationId, station, error]);


    const renderContent = () => {
        if (error) {
            return (
                <div className="p-8 text-center text-red-600 text-base sm:text-lg md:text-xl">{error}</div>
            );
        }

        if (!station) {
            return (
                <div className="p-8 text-center text-base sm:text-lg md:text-xl">Loading...</div>
            );
        }

        return (
            <div className="mx-auto mt-4 sm:mt-8 w-full max-w-md sm:max-w-lg md:max-w-2xl lg:max-w-3xl px-1 sm:px-4 md:px-8">
                <div className="bg-card border rounded-lg shadow p-2 sm:p-4 md:p-6 space-y-4">
                    <div className="text-xl sm:text-2xl md:text-3xl font-bold text-blue-500 text-center">Station #{station.station_number}</div>
                    <div className="space-y-3 text-base sm:text-lg md:text-xl">
                        <div><span className="font-semibold">Site:</span> <span className="text-white/90">{station.site?.name}</span></div>
                        <div><span className="font-semibold">Campaign:</span> <span className="text-white/90">{station.campaign?.name}</span></div>
                        <div><span className="font-semibold">Status:</span> <span className="text-white/90">{station.status}</span></div>
                        <div><span className="font-semibold">Monitor Type:</span> <span className="text-white/90">{station.monitor_type}</span></div>
                        <div><span className="font-semibold">PC Spec:</span> <span className="text-white/90">{station.pcSpec?.model ?? "—"}</span></div>
                        {station.pcSpec && (
                            <div className="pl-2 text-base sm:text-lg md:text-xl text-gray-200 space-y-1">
                                <div><span className="font-semibold">Processor:</span> <span className="text-white/90">{station.pcSpec.processor}</span></div>
                                <div><span className="font-semibold">RAM:</span> <span className="text-white/90">{station.pcSpec.ram}</span> {station.pcSpec.ram_ddr && <span className="text-white/70">(DDR: {station.pcSpec.ram_ddr})</span>} {station.pcSpec.ram_capacities && <span className="text-white/70">({station.pcSpec.ram_capacities})</span>}</div>
                                <div><span className="font-semibold">Disk:</span> <span className="text-white/90">{station.pcSpec.disk}</span> {station.pcSpec.disk_type && <span className="text-white/70">(Type: {station.pcSpec.disk_type})</span>} {station.pcSpec.disk_capacities && <span className="text-white/70">({station.pcSpec.disk_capacities})</span>}</div>
                                {station.pcSpec.pc_number && <div><span className="font-semibold">PC Number:</span> <span className="text-white/90">{station.pcSpec.pc_number}</span></div>}
                                {station.pcSpec.issue && <div className="text-red-400 font-semibold">Issue: {station.pcSpec.issue}</div>}
                            </div>
                        )}
                        <div><span className="font-semibold">Monitors:</span></div>
                        {station.monitors && station.monitors.length > 0 ? (
                            <ul className="pl-4 text-base sm:text-lg md:text-xl text-white/90">
                                {station.monitors.map(m => (
                                    <li key={m.id} className="mb-2">
                                        <span className="font-semibold">{m.brand} {m.model}</span> - {m.screen_size}" {m.resolution} {m.panel_type} <span className="font-bold">x{m.quantity}</span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <div className="pl-4 text-base sm:text-lg md:text-xl text-gray-400">No monitors</div>
                        )}
                    </div>
                    <div className="flex flex-col sm:flex-row gap-3 mt-6">
                        <Button
                            className="w-full sm:w-auto sm:flex-1 text-base sm:text-lg md:text-xl py-3"
                            onClick={() => router.get(stationsEditRoute(station.id).url)}
                        >
                            Edit Station
                        </Button>
                        <Button
                            className="w-full sm:w-auto sm:flex-1 text-base sm:text-lg md:text-xl py-3"
                            variant="outline"
                            onClick={() => router.visit(transferPage(station.id).url)}
                        >
                            {station.pcSpec ? 'Transfer PC' : 'Assign PC'}
                        </Button>
                    </div>
                </div>
            </div>
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="relative">
                <LoadingOverlay isLoading={isPageLoading || isFetching} />
                <PageHeader
                    title={station ? `Station #${station.station_number}` : "Station Scan Result"}
                    description={station ? 'Live station details from QR scan' : 'Fetching station details...'}
                />
                {renderContent()}
            </div>
        </AppLayout>
    );
}
