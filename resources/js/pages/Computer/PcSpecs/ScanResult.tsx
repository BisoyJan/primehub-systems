import { Head, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';
import { index as pcSpecsIndexRoute, edit as pcSpecsEditRoute } from '@/routes/pcspecs';
import { transferPage } from '@/routes/pc-transfers';
import { Cpu, HardDrive, MemoryStick, Monitor, Pencil, ArrowLeft, ArrowLeftRight, AlertTriangle } from 'lucide-react';

interface ProcessorSpec {
    id: number;
    manufacturer: string;
    model: string;
    core_count: number | null;
    thread_count: number | null;
    base_clock_ghz: number | null;
    boost_clock_ghz: number | null;
}

interface Station {
    id: number;
    station_number: string | number;
    status: string;
}

interface PcSpec {
    id: number;
    pc_number?: string | null;
    manufacturer: string;
    memory_type: string;
    ram_gb: number;
    disk_gb: number;
    available_ports?: string | null;
    notes?: string | null;
    bios_release_date?: string | null;
    issue?: string | null;
    processorSpecs: ProcessorSpec[];
    stations: Station[];
}

export default function ScanResult({ pcspec, error }: { pcspec?: PcSpec; error?: string }) {
    useFlashMessage();
    const isPageLoading = usePageLoading();

    const pcLabel = pcspec?.pc_number ?? (pcspec ? `PC #${pcspec.id}` : '');

    const { title, breadcrumbs } = usePageMeta({
        title: pcspec ? `PC: ${pcLabel}` : 'PC Spec Scan',
        breadcrumbs: [{ title: 'PC Specs', href: pcSpecsIndexRoute().url }],
    });

    if (error) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Scan Error" />
                <div className="flex items-center justify-center min-h-[60vh] p-4">
                    <Card className="w-full max-w-md text-center">
                        <CardContent className="pt-8 pb-6 space-y-4">
                            <AlertTriangle className="mx-auto h-12 w-12 text-destructive" />
                            <p className="text-lg text-destructive font-medium">{error}</p>
                            <Button variant="outline" onClick={() => router.get(pcSpecsIndexRoute().url)}>
                                <ArrowLeft className="mr-2 h-4 w-4" /> Back to List
                            </Button>
                        </CardContent>
                    </Card>
                </div>
            </AppLayout>
        );
    }

    if (!pcspec) {
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
                <LoadingOverlay isLoading={isPageLoading} />

                <PageHeader
                    title={`PC: ${pcLabel}`}
                    description="PC specification details from QR scan"
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
                                        <CardTitle className="text-xl sm:text-2xl">{pcLabel}</CardTitle>
                                        <p className="text-sm text-muted-foreground">{pcspec.manufacturer}</p>
                                    </div>
                                </div>
                                <Badge variant={pcspec.issue ? 'destructive' : 'secondary'}>
                                    {pcspec.issue ? 'Has Issue' : 'No Issues'}
                                </Badge>
                            </div>
                        </CardHeader>
                    </Card>

                    {/* Issue alert */}
                    {pcspec.issue && (
                        <Card className="border-destructive/50 bg-destructive/5">
                            <CardContent className="flex items-start gap-3 pt-4">
                                <AlertTriangle className="h-5 w-5 text-destructive shrink-0 mt-0.5" />
                                <div>
                                    <p className="font-semibold text-destructive">Issue Reported</p>
                                    <p className="text-sm text-muted-foreground">{pcspec.issue}</p>
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* Bios Release Date */}
                    {pcspec.bios_release_date && (
                        <Card>
                            <CardContent className="pt-4 flex items-center gap-3">
                                <Monitor className="h-5 w-5 text-muted-foreground" />
                                <div>
                                    <p className="text-xs text-muted-foreground">Bios Release Date</p>
                                    <p className="font-medium">{pcspec.bios_release_date}</p>
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* Specs grid */}
                    <div className="grid grid-cols-2 sm:grid-cols-2 gap-3">
                        <Card>
                            <CardContent className="pt-4 text-center">
                                <MemoryStick className="mx-auto h-5 w-5 text-muted-foreground mb-1" />
                                <p className="text-2xl font-bold">{pcspec.ram_gb} GB</p>
                                <p className="text-xs text-muted-foreground">RAM ({pcspec.memory_type})</p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardContent className="pt-4 text-center">
                                <HardDrive className="mx-auto h-5 w-5 text-muted-foreground mb-1" />
                                <p className="text-2xl font-bold">{pcspec.disk_gb} GB</p>
                                <p className="text-xs text-muted-foreground">Storage</p>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Available ports */}
                    {pcspec.available_ports && (
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">Available Ports</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p>{pcspec.available_ports}</p>
                            </CardContent>
                        </Card>
                    )}

                    {/* Notes */}
                    {pcspec.notes && (
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">Notes</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-sm whitespace-pre-wrap">{pcspec.notes}</p>
                            </CardContent>
                        </Card>
                    )}

                    {/* Processor(s) */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Cpu className="h-4 w-4" /> Processor(s)
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {pcspec.processorSpecs.length > 0 ? (
                                <div className="space-y-2">
                                    {pcspec.processorSpecs.map((p) => (
                                        <div key={p.id} className="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-3 rounded-md border px-3 py-2">
                                            <span className="font-medium">{p.manufacturer} {p.model}</span>
                                            <div className="flex flex-wrap gap-1.5">
                                                {p.core_count && (
                                                    <Badge variant="outline">{p.core_count}C / {p.thread_count ?? '?'}T</Badge>
                                                )}
                                                {p.base_clock_ghz && (
                                                    <Badge variant="outline">{p.base_clock_ghz} GHz</Badge>
                                                )}
                                                {p.boost_clock_ghz && (
                                                    <Badge variant="outline">Boost: {p.boost_clock_ghz} GHz</Badge>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">No processor assigned</p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Assigned stations */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Monitor className="h-4 w-4" /> Assigned Stations
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {pcspec.stations.length > 0 ? (
                                <div className="flex flex-wrap gap-2">
                                    {pcspec.stations.map((s) => (
                                        <Badge key={s.id} variant="secondary">
                                            Station #{s.station_number}
                                            <span className="ml-1 text-muted-foreground">({s.status})</span>
                                        </Badge>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">Not assigned to any station</p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Action buttons */}
                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <Button className="w-full" onClick={() => router.get(pcSpecsEditRoute(pcspec.id).url)}>
                            <Pencil className="mr-2 h-4 w-4" /> Edit PC Spec
                        </Button>
                        <Button className="w-full" variant="outline" onClick={() => router.visit(transferPage(undefined, { query: { pc: pcspec.id, filter: 'available' } }).url)}>
                            <ArrowLeftRight className="mr-2 h-4 w-4" /> Assign to Station
                        </Button>
                        <Button className="w-full" variant="outline" onClick={() => router.get(pcSpecsIndexRoute().url)}>
                            <ArrowLeft className="mr-2 h-4 w-4" /> Back to List
                        </Button>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
