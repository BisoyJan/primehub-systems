import { useEffect, useState } from "react";
import { Head, router } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import AppLayout from "@/layouts/app-layout";
import { PageHeader } from "@/components/PageHeader";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { useFlashMessage, usePageLoading, usePageMeta } from "@/hooks";
import {
    index as stationsIndexRoute,
    edit as stationsEditRoute,
} from "@/routes/stations";
import { transferPage } from "@/routes/pc-transfers";
import { Monitor, Cpu, HardDrive, MemoryStick, MapPin, Megaphone, Pencil, ArrowLeftRight, ArrowLeft, AlertTriangle, Layers } from "lucide-react";

interface MonitorSpec {
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
    monitors?: MonitorSpec[];
    created_at?: string;
    updated_at?: string;
}

const statusColor = (status: string) => {
    switch (status.toLowerCase()) {
        case 'active': return 'default';
        case 'inactive': return 'secondary';
        case 'maintenance': return 'destructive';
        default: return 'outline';
    }
};

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

    if (error) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Scan Error" />
                <div className="flex items-center justify-center min-h-[60vh] p-4">
                    <Card className="w-full max-w-md text-center">
                        <CardContent className="pt-8 pb-6 space-y-4">
                            <AlertTriangle className="mx-auto h-12 w-12 text-destructive" />
                            <p className="text-lg text-destructive font-medium">{error}</p>
                            <Button variant="outline" onClick={() => router.get(stationsIndexRoute().url)}>
                                <ArrowLeft className="mr-2 h-4 w-4" /> Back to List
                            </Button>
                        </CardContent>
                    </Card>
                </div>
            </AppLayout>
        );
    }

    if (!station) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Loading..." />
                <div className="flex items-center justify-center min-h-[60vh]">
                    <p className="text-muted-foreground text-lg">Loading...</p>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="relative flex flex-col gap-4 p-3">
                <LoadingOverlay isLoading={isPageLoading || isFetching} />

                <PageHeader
                    title={`Station #${station.station_number}`}
                    description="Live station details from QR scan"
                />

                <div className="mx-auto w-full max-w-3xl space-y-4">
                    {/* Header card */}
                    <Card>
                        <CardHeader className="pb-2">
                            <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2">
                                <div className="flex items-center gap-3">
                                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                                        <Monitor className="h-5 w-5 text-primary" />
                                    </div>
                                    <div>
                                        <CardTitle className="text-xl sm:text-2xl">Station #{station.station_number}</CardTitle>
                                        <p className="text-sm text-muted-foreground">{station.monitor_type} monitor setup</p>
                                    </div>
                                </div>
                                <Badge variant={statusColor(station.status)}>
                                    {station.status}
                                </Badge>
                            </div>
                        </CardHeader>
                    </Card>

                    {/* Location info */}
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <Card>
                            <CardContent className="flex items-center gap-3 pt-4">
                                <MapPin className="h-5 w-5 text-muted-foreground shrink-0" />
                                <div>
                                    <p className="text-xs text-muted-foreground">Site</p>
                                    <p className="font-medium">{station.site?.name ?? '—'}</p>
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardContent className="flex items-center gap-3 pt-4">
                                <Megaphone className="h-5 w-5 text-muted-foreground shrink-0" />
                                <div>
                                    <p className="text-xs text-muted-foreground">Campaign</p>
                                    <p className="font-medium">{station.campaign?.name ?? '—'}</p>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {/* PC Spec */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Cpu className="h-4 w-4" /> PC Specification
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {station.pcSpec ? (
                                <div className="space-y-3">
                                    <div className="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-3 rounded-md border px-3 py-2">
                                        <span className="font-medium">{station.pcSpec.model}</span>
                                        {station.pcSpec.pc_number && (
                                            <Badge variant="outline">{station.pcSpec.pc_number}</Badge>
                                        )}
                                    </div>
                                    <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                        <div className="flex items-center gap-2 rounded-md border px-3 py-2">
                                            <Cpu className="h-4 w-4 text-muted-foreground" />
                                            <div>
                                                <p className="text-xs text-muted-foreground">Processor</p>
                                                <p className="text-sm font-medium">{station.pcSpec.processor}</p>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2 rounded-md border px-3 py-2">
                                            <MemoryStick className="h-4 w-4 text-muted-foreground" />
                                            <div>
                                                <p className="text-xs text-muted-foreground">RAM</p>
                                                <p className="text-sm font-medium">{station.pcSpec.ram}</p>
                                                {station.pcSpec.ram_ddr && <p className="text-xs text-muted-foreground">{station.pcSpec.ram_ddr}</p>}
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2 rounded-md border px-3 py-2">
                                            <HardDrive className="h-4 w-4 text-muted-foreground" />
                                            <div>
                                                <p className="text-xs text-muted-foreground">Disk</p>
                                                <p className="text-sm font-medium">{station.pcSpec.disk}</p>
                                                {station.pcSpec.disk_type && <p className="text-xs text-muted-foreground">{station.pcSpec.disk_type}</p>}
                                            </div>
                                        </div>
                                    </div>
                                    {station.pcSpec.issue && (
                                        <div className="flex items-start gap-2 rounded-md border border-destructive/50 bg-destructive/5 px-3 py-2">
                                            <AlertTriangle className="h-4 w-4 text-destructive shrink-0 mt-0.5" />
                                            <p className="text-sm text-destructive">{station.pcSpec.issue}</p>
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">No PC assigned to this station</p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Monitors */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Layers className="h-4 w-4" /> Monitors
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {station.monitors && station.monitors.length > 0 ? (
                                <div className="space-y-2">
                                    {station.monitors.map(m => (
                                        <div key={m.id} className="flex flex-col sm:flex-row sm:items-center justify-between gap-1 rounded-md border px-3 py-2">
                                            <span className="font-medium">{m.brand} {m.model}</span>
                                            <div className="flex flex-wrap gap-1.5">
                                                <Badge variant="outline">{m.screen_size}"</Badge>
                                                <Badge variant="outline">{m.resolution}</Badge>
                                                <Badge variant="outline">{m.panel_type}</Badge>
                                                <Badge variant="secondary">x{m.quantity}</Badge>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">No monitors assigned</p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Action buttons */}
                    <div className="flex flex-col sm:flex-row gap-3">
                        <Button className="flex-1" onClick={() => router.get(stationsEditRoute(station.id).url)}>
                            <Pencil className="mr-2 h-4 w-4" /> Edit Station
                        </Button>
                        <Button className="flex-1" variant="outline" onClick={() => router.visit(transferPage(station.id).url)}>
                            <ArrowLeftRight className="mr-2 h-4 w-4" /> {station.pcSpec ? 'Transfer PC' : 'Assign PC'}
                        </Button>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
