import { LoadingOverlay } from '@/components/LoadingOverlay';
import { PageHeader } from '@/components/PageHeader';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';
import AppLayout from '@/layouts/app-layout';
import { transferPage } from '@/routes/pc-transfers';
import {
    edit as stationsEditRoute,
    index as stationsIndexRoute,
} from '@/routes/stations';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowLeftRight,
    Cpu,
    MapPin,
    Megaphone,
    Monitor,
    Pencil,
} from 'lucide-react';
import { useEffect, useState } from 'react';

interface Site {
    id: number;
    name: string;
}
interface Campaign {
    id: number;
    name: string;
}
interface ProcessorSpec {
    id: number;
    manufacturer: string;
    model: string;
    core_count: number;
    thread_count: number;
    base_clock_ghz: number;
    boost_clock_ghz: number;
}

interface PcSpec {
    id: number;
    pc_number?: string;
    manufacturer?: string | null;
    model: string;
    memory_type?: string | null;
    ram_gb?: number;
    disk_gb?: number;
    available_ports?: string | null;
    bios_release_date?: string | null;
    issue?: string | null;
    processorSpecs: ProcessorSpec[];
}
interface Station {
    id: number;
    station_number: string | number;
    site?: Site;
    campaign?: Campaign;
    status: string;
    monitor_type: string;
    pcSpec?: PcSpec;
    created_at?: string;
    updated_at?: string;
}

const statusColor = (status: string | null | undefined) => {
    switch (status?.toLowerCase()) {
        case 'active':
            return 'default';
        case 'inactive':
            return 'secondary';
        case 'maintenance':
            return 'destructive';
        default:
            return 'outline';
    }
};

export default function ScanResult({
    stationId,
    station: initialStation,
    error: initialError,
}: {
    stationId?: number;
    station?: Station;
    error?: string;
}) {
    const [station, setStation] = useState<Station | null>(
        initialStation ?? null,
    );
    const [error, setError] = useState<string | null>(initialError ?? null);
    const [isFetching, setIsFetching] = useState(false);

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const { title, breadcrumbs } = usePageMeta({
        title: station ? `Station #${station.station_number}` : 'Station Scan',
        breadcrumbs: [{ title: 'Stations', href: stationsIndexRoute().url }],
    });

    useEffect(() => {
        if (!station && !error && stationId) {
            setIsFetching(true);
            fetch(`/stations/${stationId}/json`)
                .then((res) => {
                    if (!res.ok) throw new Error('Not found');
                    return res.json();
                })
                .then(setStation)
                .catch(() =>
                    setError('Station not found or you are not authorized.'),
                )
                .finally(() => setIsFetching(false));
        }
    }, [stationId, station, error]);

    if (error) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Scan Error" />
                <div className="flex min-h-[60vh] items-center justify-center p-4">
                    <Card className="w-full max-w-md text-center">
                        <CardContent className="space-y-4 pt-8 pb-6">
                            <AlertTriangle className="mx-auto h-12 w-12 text-destructive" />
                            <p className="text-lg font-medium text-destructive">
                                {error}
                            </p>
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.get(stationsIndexRoute().url)
                                }
                            >
                                <ArrowLeft className="mr-2 h-4 w-4" /> Back to
                                List
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
                <div className="flex min-h-[60vh] items-center justify-center">
                    <p className="text-lg text-muted-foreground">Loading...</p>
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
                            <div className="flex flex-col items-start justify-between gap-2 sm:flex-row sm:items-center">
                                <div className="flex items-center gap-3">
                                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                                        <Monitor className="h-5 w-5 text-primary" />
                                    </div>
                                    <div>
                                        <CardTitle className="text-xl sm:text-2xl">
                                            Station #{station.station_number}
                                        </CardTitle>
                                        <Badge
                                            variant={
                                                station.monitor_type === 'dual'
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                        >
                                            {station.monitor_type === 'dual'
                                                ? 'Dual Monitor'
                                                : station.monitor_type ===
                                                    'none'
                                                  ? 'No Monitor'
                                                  : 'Single Monitor'}
                                        </Badge>
                                    </div>
                                </div>
                                <Badge variant={statusColor(station.status)}>
                                    {station.status}
                                </Badge>
                            </div>
                        </CardHeader>
                    </Card>

                    {/* Location info */}
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Card>
                            <CardContent className="flex items-center gap-3 pt-4">
                                <MapPin className="h-5 w-5 shrink-0 text-muted-foreground" />
                                <div>
                                    <p className="text-xs text-muted-foreground">
                                        Site
                                    </p>
                                    <p className="font-medium">
                                        {station.site?.name ?? '—'}
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardContent className="flex items-center gap-3 pt-4">
                                <Megaphone className="h-5 w-5 shrink-0 text-muted-foreground" />
                                <div>
                                    <p className="text-xs text-muted-foreground">
                                        Campaign
                                    </p>
                                    <p className="font-medium">
                                        {station.campaign?.name ?? '—'}
                                    </p>
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
                                    <div className="flex flex-col gap-1 rounded-md border px-3 py-2 sm:flex-row sm:items-center sm:gap-3">
                                        <span className="font-medium">
                                            {station.pcSpec.manufacturer || '—'}
                                        </span>
                                        {station.pcSpec.pc_number && (
                                            <Badge variant="outline">
                                                {station.pcSpec.pc_number}
                                            </Badge>
                                        )}
                                    </div>

                                    <div className="grid grid-cols-2 gap-x-4 gap-y-2 rounded-md border px-3 py-2 text-sm">
                                        <div>
                                            <span className="text-xs text-muted-foreground">
                                                Memory Type
                                            </span>
                                            <p className="font-medium">
                                                {station.pcSpec.memory_type ||
                                                    'N/A'}
                                            </p>
                                        </div>
                                        <div>
                                            <span className="text-xs text-muted-foreground">
                                                RAM
                                            </span>
                                            <p className="font-medium">
                                                {station.pcSpec.ram_gb
                                                    ? `${station.pcSpec.ram_gb} GB`
                                                    : 'N/A'}
                                            </p>
                                        </div>
                                        <div>
                                            <span className="text-xs text-muted-foreground">
                                                Disk
                                            </span>
                                            <p className="font-medium">
                                                {station.pcSpec.disk_gb
                                                    ? `${station.pcSpec.disk_gb} GB`
                                                    : 'N/A'}
                                            </p>
                                        </div>
                                        <div>
                                            <span className="text-xs text-muted-foreground">
                                                Ports
                                            </span>
                                            <p className="font-medium">
                                                {station.pcSpec
                                                    .available_ports || 'N/A'}
                                            </p>
                                        </div>
                                        {station.pcSpec.bios_release_date && (
                                            <div>
                                                <span className="text-xs text-muted-foreground">
                                                    Bios Release Date
                                                </span>
                                                <p className="font-medium">
                                                    {
                                                        station.pcSpec
                                                            .bios_release_date
                                                    }
                                                </p>
                                            </div>
                                        )}
                                    </div>

                                    {station.pcSpec.processorSpecs?.length ? (
                                        <div className="space-y-2">
                                            <h4 className="text-sm font-semibold">
                                                Processor
                                            </h4>
                                            {station.pcSpec.processorSpecs.map(
                                                (p) => (
                                                    <div
                                                        key={p.id}
                                                        className="grid grid-cols-2 gap-x-4 gap-y-2 rounded-md border px-3 py-2 text-sm"
                                                    >
                                                        <div className="col-span-2">
                                                            <span className="text-xs text-muted-foreground">
                                                                Processor
                                                            </span>
                                                            <p className="font-medium">
                                                                {p.manufacturer}{' '}
                                                                {p.model}
                                                            </p>
                                                        </div>
                                                        <div>
                                                            <span className="text-xs text-muted-foreground">
                                                                Cores / Threads
                                                            </span>
                                                            <p className="font-medium">
                                                                {p.core_count} /{' '}
                                                                {p.thread_count}
                                                            </p>
                                                        </div>
                                                        <div>
                                                            <span className="text-xs text-muted-foreground">
                                                                Clock (Base /
                                                                Boost)
                                                            </span>
                                                            <p className="font-medium">
                                                                {
                                                                    p.base_clock_ghz
                                                                }{' '}
                                                                /{' '}
                                                                {
                                                                    p.boost_clock_ghz
                                                                }{' '}
                                                                GHz
                                                            </p>
                                                        </div>
                                                    </div>
                                                ),
                                            )}
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            No processor specs available.
                                        </p>
                                    )}

                                    {station.pcSpec.issue && (
                                        <div className="rounded-md border border-red-200 bg-red-50 p-3 dark:border-red-800 dark:bg-red-900/20">
                                            <span className="text-xs text-muted-foreground">
                                                Issue
                                            </span>
                                            <p className="text-sm font-medium text-red-600 dark:text-red-400">
                                                {station.pcSpec.issue}
                                            </p>
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    No PC assigned to this station
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Action buttons */}
                    <div className="flex flex-col gap-3 sm:flex-row">
                        <Button
                            className="flex-1"
                            onClick={() =>
                                router.get(stationsEditRoute(station.id).url)
                            }
                        >
                            <Pencil className="mr-2 h-4 w-4" /> Edit Station
                        </Button>
                        <Button
                            className="flex-1"
                            variant="outline"
                            onClick={() =>
                                router.visit(transferPage(station.id).url)
                            }
                        >
                            <ArrowLeftRight className="mr-2 h-4 w-4" />{' '}
                            {station.pcSpec ? 'Transfer PC' : 'Assign PC'}
                        </Button>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
