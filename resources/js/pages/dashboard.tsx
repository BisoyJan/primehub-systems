import React, { useState, useEffect } from 'react';
import { motion } from 'framer-motion';
import { Head } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import {
    Monitor,
    AlertCircle,
    HardDrive,
    Wrench,
    Calendar,
    MapPin,
    Server,
    CheckCircle,
    XCircle
} from 'lucide-react';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];

interface DashboardProps {
    totalStations: {
        total: number;
        bysite: Array<{ site: string; count: number }>;
    };
    noPcs: {
        total: number;
        stations: Array<{ station: string; site: string; campaign: string }>;
    };
    vacantStations: {
        total: number;
        bysite: Array<{ site: string; count: number }>;
        stations: Array<{ site: string; station_number: string }>; // <-- add this
    };
    ssdPcs: {
        total: number;
        details: Array<{ site: string; count: number }>;
    };
    hddPcs: {
        total: number;
        details: Array<{ site: string; count: number }>;
    };
    dualMonitor: {
        total: number;
        bysite: Array<{ site: string; count: number }>;
    };
    maintenanceDue: {
        total: number;
        stations: Array<{ station: string; site: string; dueDate: string; daysOverdue: number }>;
    };
    lastMaintenance: {
        date: string;
        station: string;
        site: string;
        performedBy: string;
    };
    avgDaysOverdue: {
        average: number;
        bySite: Array<{ site: string; days: number }>;
    };
    unassignedPcSpecs: Array<{
        id: number;
        pc_number: string;
        model: string;
        ram: string;
        ram_gb: number;
        ram_count: number;
        disk: string;
        disk_tb: number;
        disk_count: number;
        processor: string;
        cpu_count: number;
        issue: string | null;
    }>;
}

interface StatCardProps {
    title: string;
    value: React.ReactNode;
    icon: React.ComponentType<{ className?: string }>;
    description?: string;
    onClick: () => void;
    variant?: 'default' | 'warning' | 'danger' | 'success';
}

const StatCard: React.FC<StatCardProps> = ({ title, value, icon: Icon, description, onClick, variant = 'default' }) => {
    const variantStyles = {
        default: 'hover:border-primary/50',
        warning: 'border-orange-500/30 hover:border-orange-500/50',
        danger: 'border-red-500/30 hover:border-red-500/50',
        success: 'border-green-500/30 hover:border-green-500/50'
    };

    return (
        <motion.div
            layout
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            whileHover={{ scale: 1.04, boxShadow: '0 4px 24px rgba(0,0,0,0.08)' }}
            transition={{ duration: 0.4, type: 'spring', stiffness: 120 }}
        >
            <Card
                className={`cursor-pointer transition-all hover:shadow-md ${variantStyles[variant]}`}
                onClick={onClick}
            >
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle className="text-sm font-medium">{title}</CardTitle>
                    <Icon className="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                    <div className="text-2xl font-bold">{value}</div>
                    {description && (
                        <p className="text-xs text-muted-foreground mt-1">{description}</p>
                    )}
                </CardContent>
            </Card>
        </motion.div>
    );
};

interface DetailDialogProps {
    open: boolean;
    onClose: () => void;
    title: string;
    description?: string;
    children: React.ReactNode;
}

const DetailDialog: React.FC<DetailDialogProps> = ({ open, onClose, title, description, children }) => {
    return (
        <Dialog open={open} onOpenChange={onClose}>
            <motion.div
                layout
                initial={{ opacity: 0, scale: 0.95 }}
                animate={{ opacity: 1, scale: 1 }}
                exit={{ opacity: 0, scale: 0.95 }}
                transition={{ duration: 0.3, type: 'spring', stiffness: 120 }}
            >
                <DialogContent className="max-w-2xl max-h-[80vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        {description && <DialogDescription>{description}</DialogDescription>}
                    </DialogHeader>
                    <div className="mt-4">{children}</div>
                </DialogContent>
            </motion.div>
        </Dialog>
    );
};

// Extend the Window interface to include vacantStationsData
declare global {
    interface Window {
        vacantStationsData?: Array<{ site: string; station_number: string }>;
    }
}

export default function Dashboard({
    totalStations,
    noPcs,
    vacantStations,
    ssdPcs,
    hddPcs,
    dualMonitor,
    maintenanceDue,
    lastMaintenance,
    avgDaysOverdue,
    unassignedPcSpecs,
}: DashboardProps) {
    const [activeDialog, setActiveDialog] = useState<string | null>(null);
    const [selectedVacantSite, setSelectedVacantSite] = useState<string | null>(null);
    const [selectedNoPcSite, setSelectedNoPcSite] = useState<string | null>(null);

    const closeDialog = () => {
        setActiveDialog(null);
        setSelectedVacantSite(null);
        setSelectedNoPcSite(null);
    };

    const [currentDateTime, setCurrentDateTime] = useState<string>("");

    useEffect(() => {
        const interval = setInterval(() => {
            const now = new Date();
            setCurrentDateTime(
                now.toLocaleString("en-US", {
                    weekday: "short",
                    year: "numeric",
                    month: "short",
                    day: "numeric",
                    hour: "2-digit",
                    minute: "2-digit",
                    second: "2-digit",
                })
            );
        }, 1000);

        return () => clearInterval(interval);
    }, []);

    const VacantStationNumbers: React.FC<{ site: string; onBack: () => void }> = ({ site, onBack }) => {
        const stationNumbers = vacantStations.stations
            .filter((s) => s.site === site)
            .map((s) => s.station_number);

        return (
            <div>
                <button className="mb-4 text-sm text-primary underline" onClick={onBack}>&larr; Back to sites</button>
                {stationNumbers.length === 0 ? (
                    <div className="text-center text-muted-foreground py-8">No vacant stations found for {site}</div>
                ) : (
                    <div>
                        <div className="mb-2 font-semibold">Vacant Station Numbers:</div>
                        <div className="flex flex-wrap gap-2">
                            {stationNumbers.map((num, idx) => (
                                <Badge key={idx} variant="outline">{num}</Badge>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        );
    };

    const NoPcStationNumbers: React.FC<{ site: string; onBack: () => void }> = ({ site, onBack }) => {
        const stationNumbers = noPcs.stations
            .filter((s) => s.site === site)
            .map((s) => s.station);

        return (
            <div>
                <button className="mb-4 text-sm text-primary underline" onClick={onBack}>&larr; Back to sites</button>
                {stationNumbers.length === 0 ? (
                    <div className="text-center text-muted-foreground py-8">No stations without PCs found for {site}</div>
                ) : (
                    <div>
                        <div className="mb-2 font-semibold">Stations Without PCs:</div>
                        <div className="flex flex-wrap gap-2">
                            {stationNumbers.map((num, idx) => (
                                <Badge key={idx} variant="outline">{num}</Badge>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="p-4 md:p-6 space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Dashboard</h1>
                        <p className="text-muted-foreground">
                            Overview of all stations and PC specifications
                        </p>
                    </div>
                    <div className="text-lg text-muted-foreground">
                        {currentDateTime}
                    </div>
                </div>

                {/* Stats Grid */}
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">

                    {/* Total Stations & Available PC Specs Combined */}
                    <StatCard
                        title="Stations & Available PCs"
                        value={
                            <div className="flex flex-row gap-2">
                                <span>
                                    <span className="font-semibold">{totalStations.total}</span>
                                    <span className="text-xs text-muted-foreground ml-1">Stations</span>
                                </span>
                                <span>
                                    <span className="font-semibold text-green-600 dark:text-green-400">{unassignedPcSpecs.length}</span>
                                    <span className="text-xs text-muted-foreground ml-1">Available PCs</span>
                                </span>
                            </div>
                        }
                        icon={Server}
                        description="Click for breakdowns"
                        onClick={() => setActiveDialog('stationsAndAvailablePcs')}
                        variant={unassignedPcSpecs.length > 0 ? "success" : "default"}
                    />

                    {/* No PCs */}
                    <StatCard
                        title="Stations Without PCs"
                        value={noPcs.total}
                        icon={AlertCircle}
                        description="Requires PC assignment"
                        onClick={() => setActiveDialog('noPcs')}
                        variant="warning"
                    />

                    {/* Vacant Stations */}
                    <StatCard
                        title="Vacant Stations"
                        value={vacantStations.total}
                        icon={XCircle}
                        description="Available for deployment"
                        onClick={() => setActiveDialog('vacantStations')}
                    />

                    {/* PCs with SSD & HDD Combined */}
                    <StatCard
                        title="PCs with SSD & HDD"
                        value={
                            <div className="flex flex-row gap-2">
                                <span>
                                    <span className="font-semibold">{hddPcs.total}</span>
                                    <span className="text-xs text-muted-foreground ml-1">HDD</span>
                                </span>
                                <span>
                                    <span className="font-semibold text-green-600 dark:text-green-400">{ssdPcs.total}</span>
                                    <span className="text-xs text-muted-foreground ml-1">SSD</span>
                                </span>
                            </div>
                        }
                        icon={HardDrive}
                        description="Solid State & Hard Disk Drives"
                        onClick={() => setActiveDialog('diskPcs')}
                        variant="success"
                    />

                    {/* Dual Monitor */}
                    <StatCard
                        title="Dual Monitor Setups"
                        value={dualMonitor.total}
                        icon={Monitor}
                        description="Stations with 2 monitors"
                        onClick={() => setActiveDialog('dualMonitor')}
                    />

                    {/* Maintenance Due */}
                    <StatCard
                        title="Maintenance Due"
                        value={maintenanceDue.total}
                        icon={Wrench}
                        description="Requires attention"
                        onClick={() => setActiveDialog('maintenanceDue')}
                        variant={maintenanceDue.total > 0 ? "danger" : "default"}
                    />

                    {/* Last Maintenance */}
                    <StatCard
                        title="Last Maintenance"
                        value={lastMaintenance.date}
                        icon={Calendar}
                        description={`Station ${lastMaintenance.station}`}
                        onClick={() => setActiveDialog('lastMaintenance')}
                    />

                    {/* Avg Days Overdue */}
                    <StatCard
                        title="Avg Days Overdue"
                        value={`${avgDaysOverdue.average} days`}
                        icon={AlertCircle}
                        description="Maintenance past due"
                        onClick={() => setActiveDialog('avgDaysOverdue')}
                        variant={avgDaysOverdue.average > 0 ? "warning" : "default"}
                    />
                </div>

                <DetailDialog
                    open={activeDialog === 'stationsAndAvailablePcs'}
                    onClose={closeDialog}
                    title="Stations & Available PCs Breakdown"
                    description="Breakdown of all stations and available PC specs not assigned to any station"
                >
                    <div className="space-y-6">
                        <div>
                            <div className="text-lg font-semibold mb-2 flex items-center gap-2">
                                <Server className="h-5 w-5 text-muted-foreground" />
                                Stations by Site
                            </div>
                            <div className="space-y-3">
                                {totalStations.bysite.map((site, idx) => (
                                    <div key={idx} className="flex items-center justify-between p-3 rounded-lg border">
                                        <div className="flex items-center gap-2">
                                            <MapPin className="h-4 w-4 text-muted-foreground" />
                                            <span className="font-medium">{site.site}</span>
                                        </div>
                                        <Badge variant="secondary">{site.count} stations</Badge>
                                    </div>
                                ))}
                                <Separator />
                                <div className="flex items-center justify-between p-3 bg-muted rounded-lg">
                                    <span className="font-semibold">Total</span>
                                    <Badge>{totalStations.total} stations</Badge>
                                </div>
                            </div>
                        </div>
                        <div>
                            <div className="text-lg font-semibold mb-2 flex items-center gap-2">
                                <Server className="h-5 w-5 text-green-600 dark:text-green-400" />
                                Available PC Specs
                            </div>
                            <div className="space-y-2">
                                {unassignedPcSpecs.length === 0 ? (
                                    <div className="text-center text-muted-foreground py-8">
                                        All PC specs are assigned to stations
                                    </div>
                                ) : (
                                    unassignedPcSpecs.map((pc) => (
                                        <div key={pc.id} className="flex flex-col md:flex-row md:items-center justify-between p-3 rounded-lg border">
                                            <div>
                                                <div className="font-medium">{pc.pc_number} - {pc.model}</div>
                                                <div className="text-xs text-muted-foreground">
                                                    RAM: {pc.ram_gb} GB ({pc.ram_count} module{pc.ram_count !== 1 ? 's' : ''})
                                                    | Disk: {pc.disk_tb} TB ({pc.disk_count} drive{pc.disk_count !== 1 ? 's' : ''})
                                                    | CPU: {pc.processor} ({pc.cpu_count} processor{pc.cpu_count !== 1 ? 's' : ''})
                                                </div>
                                                {pc.issue && <div className="text-xs text-red-500">Issue: {pc.issue}</div>}
                                            </div>
                                        </div>
                                    ))
                                )}
                            </div>
                        </div>
                    </div>
                </DetailDialog>

                <DetailDialog
                    open={activeDialog === 'noPcs'}
                    onClose={closeDialog}
                    title={selectedNoPcSite ? `Stations Without PCs in ${selectedNoPcSite}` : "Stations Without PCs"}
                    description={selectedNoPcSite ? "Station numbers needing PC assignment" : "Stations that need PC assignment"}
                >
                    {!selectedNoPcSite ? (
                        <div className="space-y-2">
                            {noPcs.total === 0 ? (
                                <div className="text-center text-muted-foreground py-8">
                                    All stations have PCs assigned
                                </div>
                            ) : (
                                Array.from(new Set(noPcs.stations.map(s => s.site))).map((site, idx) => (
                                    <div
                                        key={idx}
                                        className="flex items-center justify-between p-3 rounded-lg border cursor-pointer hover:bg-muted"
                                        onClick={() => setSelectedNoPcSite(site)}
                                    >
                                        <div className="flex items-center gap-2">
                                            <MapPin className="h-4 w-4 text-muted-foreground" />
                                            <span className="font-medium">{site}</span>
                                        </div>
                                        <Badge variant="secondary">{
                                            noPcs.stations.filter(s => s.site === site).length
                                        } without PC</Badge>
                                    </div>
                                ))
                            )}
                        </div>
                    ) : (
                        <NoPcStationNumbers site={selectedNoPcSite} onBack={() => setSelectedNoPcSite(null)} />
                    )}
                </DetailDialog>

                <DetailDialog
                    open={activeDialog === 'vacantStations'}
                    onClose={closeDialog}
                    title={selectedVacantSite ? `Vacant Stations in ${selectedVacantSite}` : "Vacant Stations by Site"}
                    description={selectedVacantSite ? "Station numbers available for deployment" : "Available stations ready for deployment"}
                >
                    {!selectedVacantSite ? (
                        <div className="space-y-3">
                            {vacantStations.bysite.length === 0 ? (
                                <div className="text-center text-muted-foreground py-8">
                                    No vacant stations
                                </div>
                            ) : (
                                vacantStations.bysite.map((site, idx) => (
                                    <div
                                        key={idx}
                                        className="flex items-center justify-between p-3 rounded-lg border cursor-pointer hover:bg-muted"
                                        onClick={() => setSelectedVacantSite(site.site)}
                                    >
                                        <div className="flex items-center gap-2">
                                            <MapPin className="h-4 w-4 text-muted-foreground" />
                                            <span className="font-medium">{site.site}</span>
                                        </div>
                                        <Badge variant="secondary">{site.count} vacant</Badge>
                                    </div>
                                ))
                            )}
                        </div>
                    ) : (
                        <VacantStationNumbers site={selectedVacantSite} onBack={() => setSelectedVacantSite(null)} />
                    )}
                </DetailDialog>

                <DetailDialog
                    open={activeDialog === 'diskPcs'}
                    onClose={closeDialog}
                    title="PCs with SSD & HDD by Site"
                    description="Stations equipped with Solid State Drives and Hard Disk Drives"
                >
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <div className="text-lg font-semibold mb-2 flex items-center gap-2">
                                <HardDrive className="h-5 w-5 text-green-600 dark:text-green-400" />
                                SSD Breakdown
                            </div>
                            <div className="space-y-3">
                                {ssdPcs.details.length === 0 ? (
                                    <div className="text-center text-muted-foreground py-8">
                                        No PCs with SSD found
                                    </div>
                                ) : (
                                    ssdPcs.details.map((site, idx) => (
                                        <div key={idx} className="flex items-center justify-between p-3 rounded-lg border">
                                            <div className="flex items-center gap-2">
                                                <span className="font-medium">{site.site}</span>
                                            </div>
                                            <Badge variant="secondary">{site.count} PCs</Badge>
                                        </div>
                                    ))
                                )}
                            </div>
                        </div>
                        <div>
                            <div className="text-lg font-semibold mb-2 flex items-center gap-2">
                                <HardDrive className="h-5 w-5 text-muted-foreground" />
                                HDD Breakdown
                            </div>
                            <div className="space-y-3">
                                {hddPcs.details.length === 0 ? (
                                    <div className="text-center text-muted-foreground py-8">
                                        No PCs with HDD found
                                    </div>
                                ) : (
                                    hddPcs.details.map((site, idx) => (
                                        <div key={idx} className="flex items-center justify-between p-3 rounded-lg border">
                                            <div className="flex items-center gap-2">
                                                <span className="font-medium">{site.site}</span>
                                            </div>
                                            <Badge variant="secondary">{site.count} PCs</Badge>
                                        </div>
                                    ))
                                )}
                            </div>
                        </div>
                    </div>
                </DetailDialog>

                <DetailDialog
                    open={activeDialog === 'dualMonitor'}
                    onClose={closeDialog}
                    title="Dual Monitor Setups by Site"
                    description="Stations with dual monitor configuration"
                >
                    <div className="space-y-3">
                        {dualMonitor.bysite.length === 0 ? (
                            <div className="text-center text-muted-foreground py-8">
                                No dual monitor setups found
                            </div>
                        ) : (
                            dualMonitor.bysite.map((site, idx) => (
                                <div key={idx} className="flex items-center justify-between p-3 rounded-lg border">
                                    <div className="flex items-center gap-2">
                                        <Monitor className="h-4 w-4 text-muted-foreground" />
                                        <span className="font-medium">{site.site}</span>
                                    </div>
                                    <Badge variant="secondary">{site.count} setups</Badge>
                                </div>
                            ))
                        )}
                    </div>
                </DetailDialog>

                <DetailDialog
                    open={activeDialog === 'maintenanceDue'}
                    onClose={closeDialog}
                    title="Maintenance Due"
                    description="Stations requiring maintenance attention"
                >
                    <div className="space-y-2">
                        {maintenanceDue.total === 0 ? (
                            <div className="text-center text-muted-foreground py-8">
                                No overdue maintenance
                            </div>
                        ) : (
                            <>
                                {maintenanceDue.stations.slice(0, 10).map((station, idx) => (
                                    <div key={idx} className="flex items-center justify-between p-3 rounded-lg border border-red-500/30">
                                        <div>
                                            <div className="font-medium">{station.station}</div>
                                            <div className="text-sm text-muted-foreground">
                                                {station.site} • Due: {station.dueDate}
                                            </div>
                                        </div>
                                        <Badge variant="destructive">{station.daysOverdue}</Badge>
                                    </div>
                                ))}
                                {maintenanceDue.stations.length > 10 && (
                                    <div className="text-center text-sm text-muted-foreground pt-2">
                                        ... and {maintenanceDue.total - 10} more
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                </DetailDialog>

                <DetailDialog
                    open={activeDialog === 'lastMaintenance'}
                    onClose={closeDialog}
                    title="Last Maintenance Record"
                    description="Most recent maintenance activity"
                >
                    {lastMaintenance.date === 'N/A' ? (
                        <div className="text-center text-muted-foreground py-8">
                            No maintenance records found
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <div className="text-sm text-muted-foreground">Date</div>
                                    <div className="font-medium">{lastMaintenance.date}</div>
                                </div>
                                <div>
                                    <div className="text-sm text-muted-foreground">Station</div>
                                    <div className="font-medium">{lastMaintenance.station}</div>
                                </div>
                                <div>
                                    <div className="text-sm text-muted-foreground">Site</div>
                                    <div className="font-medium">{lastMaintenance.site}</div>
                                </div>
                                <div>
                                    <div className="text-sm text-muted-foreground">Performed By</div>
                                    <div className="font-medium">{lastMaintenance.performedBy}</div>
                                </div>
                            </div>
                            <div className="flex items-center gap-2 p-3 bg-green-500/10 border border-green-500/30 rounded-lg">
                                <CheckCircle className="h-4 w-4 text-green-600 dark:text-green-400" />
                                <span className="text-sm">Maintenance completed successfully</span>
                            </div>
                        </div>
                    )}
                </DetailDialog>

                <DetailDialog
                    open={activeDialog === 'avgDaysOverdue'}
                    onClose={closeDialog}
                    title="Average Days Overdue by Site"
                    description="Average maintenance delay across sites"
                >
                    <div className="space-y-3">
                        {avgDaysOverdue.bySite.length === 0 ? (
                            <div className="text-center text-muted-foreground py-8">
                                No overdue maintenance
                            </div>
                        ) : (
                            <>
                                {avgDaysOverdue.bySite.map((site, idx) => (
                                    <div key={idx} className="flex items-center justify-between p-3 rounded-lg border">
                                        <div className="flex items-center gap-2">
                                            <Calendar className="h-4 w-4 text-muted-foreground" />
                                            <span className="font-medium">{site.site}</span>
                                        </div>
                                        <Badge variant="outline">{site.days} days</Badge>
                                    </div>
                                ))}
                                <Separator />
                                <div className="flex items-center justify-between p-3 bg-muted rounded-lg">
                                    <span className="font-semibold">Overall Average</span>
                                    <Badge>{avgDaysOverdue.average} days</Badge>
                                </div>
                            </>
                        )}
                    </div>
                </DetailDialog>
            </div>
        </AppLayout>
    );
}
