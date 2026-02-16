import React, { useState } from 'react';
import { motion } from 'framer-motion';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import {
    Monitor,
    AlertCircle,
    Wrench,
    MapPin,
    Server,
    XCircle,
} from 'lucide-react';
import { StatCard } from '../components/StatCard';
import { DetailDialog } from '../components/DetailDialog';
import type { DashboardProps } from '../types';

export interface InfrastructureTabProps {
    totalStations: DashboardProps['totalStations'];
    noPcs: DashboardProps['noPcs'];
    vacantStations: DashboardProps['vacantStations'];

    dualMonitor: DashboardProps['dualMonitor'];
    maintenanceDue: DashboardProps['maintenanceDue'];
    unassignedPcSpecs: DashboardProps['unassignedPcSpecs'];
}

export const InfrastructureTab: React.FC<InfrastructureTabProps> = ({
    totalStations,
    noPcs,
    vacantStations,

    dualMonitor,
    maintenanceDue,
    unassignedPcSpecs,
}) => {
    const [activeDialog, setActiveDialog] = useState<string | null>(null);
    const [selectedVacantSite, setSelectedVacantSite] = useState<string | null>(null);
    const [selectedNoPcSite, setSelectedNoPcSite] = useState<string | null>(null);

    const closeDialog = () => {
        setActiveDialog(null);
        setSelectedVacantSite(null);
        setSelectedNoPcSite(null);
    };

    const VacantStationNumbers: React.FC<{ site: string; onBack: () => void }> = ({ site, onBack }) => {
        const stationNumbers = vacantStations?.stations
            ? vacantStations.stations
                .filter((s) => s.site === site)
                .map((s) => s.station_number)
            : [];

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
        const stationNumbers = noPcs?.stations
            ? noPcs.stations
                .filter((s) => s.site === site)
                .map((s) => s.station)
            : [];

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
        <>
            <motion.div
                initial={{ opacity: 0, y: 20 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.3 }}
            >
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                    {/* Total Stations */}
                    <StatCard
                        title="Total Stations"
                        value={totalStations?.total || 0}
                        icon={Server}
                        description="Click for breakdown by site"
                        onClick={() => setActiveDialog('stations')}
                    />

                    {/* Available PC Specs */}
                    <StatCard
                        title="Available PCs"
                        value={unassignedPcSpecs?.length || 0}
                        icon={Server}
                        description="PC specs not assigned to any station"
                        onClick={() => setActiveDialog('availablePcs')}
                        variant={(unassignedPcSpecs?.length || 0) > 0 ? "success" : "default"}
                    />

                    {/* No PCs */}
                    <StatCard
                        title="Stations Without PCs"
                        value={noPcs?.total || 0}
                        icon={AlertCircle}
                        description="Requires PC assignment"
                        onClick={() => setActiveDialog('noPcs')}
                        variant="warning"
                    />

                    {/* Vacant Stations */}
                    <StatCard
                        title="Vacant Stations"
                        value={vacantStations?.total || 0}
                        icon={XCircle}
                        description="Available for deployment"
                        onClick={() => setActiveDialog('vacantStations')}
                    />


                    {/* Dual Monitor */}
                    <StatCard
                        title="Dual Monitor Setups"
                        value={dualMonitor?.total || 0}
                        icon={Monitor}
                        description="Stations with 2 monitors"
                        onClick={() => setActiveDialog('dualMonitor')}
                    />

                    {/* Maintenance Due */}
                    <StatCard
                        title="Maintenance Due"
                        value={maintenanceDue?.total || 0}
                        icon={Wrench}
                        description="Requires attention"
                        onClick={() => setActiveDialog('maintenanceDue')}
                        variant={(maintenanceDue?.total || 0) > 0 ? "danger" : "default"}
                    />
                </div>
            </motion.div>

            {/* ─── Dialogs ───────────────────────────────────────────────── */}

            <DetailDialog
                open={activeDialog === 'stations'}
                onClose={closeDialog}
                title="Stations Breakdown by Site"
                description="Breakdown of all stations by site"
            >
                <div className="space-y-3">
                    {(totalStations?.bysite || []).map((site, idx) => (
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
                        <Badge>{totalStations?.total || 0} stations</Badge>
                    </div>
                </div>
            </DetailDialog>

            <DetailDialog
                open={activeDialog === 'availablePcs'}
                onClose={closeDialog}
                title="Available PC Specs"
                description="PC specs not assigned to any station"
            >
                <div className="space-y-2">
                    {(!unassignedPcSpecs || unassignedPcSpecs.length === 0) ? (
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
            </DetailDialog>

            <DetailDialog
                open={activeDialog === 'noPcs'}
                onClose={closeDialog}
                title={selectedNoPcSite ? `Stations Without PCs in ${selectedNoPcSite}` : "Stations Without PCs"}
                description={selectedNoPcSite ? "Station numbers needing PC assignment" : "Stations that need PC assignment"}
            >
                {!selectedNoPcSite ? (
                    <div className="space-y-2">
                        {(!noPcs || noPcs.total === 0) ? (
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
                        {(!vacantStations?.bysite || vacantStations.bysite.length === 0) ? (
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
                open={activeDialog === 'dualMonitor'}
                onClose={closeDialog}
                title="Dual Monitor Setups by Site"
                description="Stations with dual monitor configuration"
            >
                <div className="space-y-3">
                    {(!dualMonitor?.bysite || dualMonitor.bysite.length === 0) ? (
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
                    {(!maintenanceDue || maintenanceDue.total === 0) ? (
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
        </>
    );
};
