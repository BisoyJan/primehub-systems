import { useEffect, useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import { ArrowRight, Check, ChevronsUpDown, AlertCircle } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { transfer as pcTransfersTransferRoute } from '@/routes/pc-transfers';
import { cn } from '@/lib/utils';

export type PcSpecOption = {
    id: number;
    pc_number: string | null;
    label: string;
    details: {
        manufacturer?: string | null;
        processor?: string | null;
        memory_type?: string | null;
        ram_gb?: number | null;
        disk_gb?: number | null;
        available_ports?: string | null;
        issue?: string | null;
        notes?: string | null;
    };
    station: {
        id: number;
        station_number: string;
        site: string | null;
        site_id: number;
        campaign: string | null;
        campaign_id: number | null;
    } | null;
};

export type StationOption = {
    id: number;
    station_number: string;
    site: string | null;
    site_id: number;
    campaign_id: number | null;
    pc_spec_id: number | null;
    pc_number: string | null;
};

export type SiteOption = {
    id: number;
    name: string;
};

type Mode = 'assign' | 'move';
type TransferType = 'assign' | 'swap';

interface QuickTransferDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    station: StationOption | null;
    pcSpecs: PcSpecOption[];
    stationOptions: StationOption[];
    sites: SiteOption[];
    reloadProps: string[];
    onSuccess?: () => void;
}

const pcLabel = (pc: PcSpecOption | undefined | null) => pc?.pc_number || pc?.label || 'PC';

type StationRef = { station_number: string; site: string | null } | null;

/** One line of the review: a PC and where it goes. `to === null` means it ends up unassigned. */
type Movement = {
    pc: PcSpecOption | null;
    from: StationRef;
    to: StationRef;
    kind: 'move' | 'swap' | 'float';
};

/**
 * In-place dialog for the two most common operations:
 * put a PC into this station, or move this station's PC somewhere else.
 */
export function QuickTransferDialog({
    open,
    onOpenChange,
    station,
    pcSpecs,
    stationOptions,
    sites,
    reloadProps,
    onSuccess,
}: QuickTransferDialogProps) {
    const hasPc = Boolean(station?.pc_spec_id);
    const [mode, setMode] = useState<Mode>('assign');
    const [selectedPcId, setSelectedPcId] = useState<number | null>(null);
    const [selectedStationId, setSelectedStationId] = useState<number | null>(null);
    const [stationSiteFilter, setStationSiteFilter] = useState('all');
    const [pcSiteFilter, setPcSiteFilter] = useState('all');
    const [transferType, setTransferType] = useState<TransferType>('assign');
    const [notes, setNotes] = useState('');
    const [errors, setErrors] = useState<string[]>([]);
    const [processing, setProcessing] = useState(false);
    const [reviewOpen, setReviewOpen] = useState(false);

    useEffect(() => {
        if (!open) {
            return;
        }
        setMode(station?.pc_spec_id ? 'move' : 'assign');
        setSelectedPcId(null);
        setSelectedStationId(null);
        // Same-site moves are the common case; cross-site is one dropdown change away.
        setStationSiteFilter(station?.site_id ? String(station.site_id) : 'all');
        setPcSiteFilter('all');
        setTransferType('assign');
        setNotes('');
        setErrors([]);
        setProcessing(false);
        setReviewOpen(false);
    }, [open, station?.id, station?.pc_spec_id, station?.site_id]);

    const stationChoices = useMemo(
        () =>
            stationOptions.filter(
                (st) => st.id !== station?.id && (stationSiteFilter === 'all' || String(st.site_id) === stationSiteFilter),
            ),
        [stationOptions, station?.id, stationSiteFilter],
    );
    // Unassigned PCs belong to no site, so they always pass the site filter.
    const pcChoices = useMemo(
        () =>
            pcSpecs.filter(
                (pc) =>
                    pc.id !== station?.pc_spec_id &&
                    (pcSiteFilter === 'all' || !pc.station || String(pc.station.site_id) === pcSiteFilter),
            ),
        [pcSpecs, station?.pc_spec_id, pcSiteFilter],
    );

    // Drop a selection that the site filter no longer shows.
    useEffect(() => {
        if (selectedStationId && !stationChoices.some((st) => st.id === selectedStationId)) {
            setSelectedStationId(null);
        }
    }, [stationChoices, selectedStationId]);
    useEffect(() => {
        if (selectedPcId && !pcChoices.some((pc) => pc.id === selectedPcId)) {
            setSelectedPcId(null);
        }
    }, [pcChoices, selectedPcId]);

    const currentPc = useMemo(
        () => (station?.pc_spec_id ? pcSpecs.find((pc) => pc.id === station.pc_spec_id) ?? null : null),
        [pcSpecs, station?.pc_spec_id],
    );
    const selectedPc = useMemo(
        () => (selectedPcId ? pcSpecs.find((pc) => pc.id === selectedPcId) ?? null : null),
        [pcSpecs, selectedPcId],
    );
    const selectedStation = useMemo(
        () => (selectedStationId ? stationOptions.find((st) => st.id === selectedStationId) ?? null : null),
        [stationOptions, selectedStationId],
    );
    const destinationPc = useMemo(
        () => (selectedStation?.pc_spec_id ? pcSpecs.find((pc) => pc.id === selectedStation.pc_spec_id) ?? null : null),
        [pcSpecs, selectedStation?.pc_spec_id],
    );

    // Swap only makes sense when both sides currently have a PC with a home station.
    const swapAvailable =
        mode === 'assign'
            ? Boolean(hasPc && selectedPc?.station)
            : Boolean(selectedStation?.pc_spec_id && station?.pc_spec_id);

    useEffect(() => {
        if (!swapAvailable && transferType === 'swap') {
            setTransferType('assign');
        }
    }, [swapAvailable, transferType]);

    const isReady = mode === 'assign' ? Boolean(selectedPcId) : Boolean(selectedStationId);

    // Site the PC ends up at vs. where it comes from; non-null only for cross-site moves.
    const crossSite = (() => {
        if (!station) return null;
        if (mode === 'move' && selectedStation && selectedStation.site_id !== station.site_id) {
            return { from: station.site ?? '—', to: selectedStation.site ?? '—' };
        }
        if (mode === 'assign' && selectedPc?.station && selectedPc.station.site_id !== station.site_id) {
            return { from: selectedPc.station.site ?? '—', to: station.site ?? '—' };
        }
        return null;
    })();

    const summaryText = () => {
        if (!station) {
            return '';
        }
        if (mode === 'assign') {
            const main = `${pcLabel(selectedPc)} → ${station.station_number}`;
            if (!hasPc) {
                return main;
            }
            return transferType === 'swap' && selectedPc?.station
                ? `${main}; ${pcLabel(currentPc)} → ${selectedPc.station.station_number}`
                : `${main}; ${pcLabel(currentPc)} becomes unassigned`;
        }
        const main = `${pcLabel(currentPc)} → ${selectedStation?.station_number ?? '…'}`;
        if (!selectedStation?.pc_spec_id) {
            return main;
        }
        return transferType === 'swap'
            ? `${main}; ${pcLabel(destinationPc)} → ${station.station_number}`
            : `${main}; ${pcLabel(destinationPc)} becomes unassigned`;
    };

    // Every PC that changes place, for the review step.
    const movements: Movement[] = (() => {
        if (!station || !isReady) {
            return [];
        }
        const here: StationRef = { station_number: station.station_number, site: station.site };

        if (mode === 'assign') {
            const list: Movement[] = [
                { pc: selectedPc, from: selectedPc?.station ?? null, to: here, kind: 'move' },
            ];
            if (hasPc) {
                list.push(
                    transferType === 'swap' && selectedPc?.station
                        ? { pc: currentPc, from: here, to: selectedPc.station, kind: 'swap' }
                        : { pc: currentPc, from: here, to: null, kind: 'float' },
                );
            }
            return list;
        }

        const dest: StationRef = selectedStation
            ? { station_number: selectedStation.station_number, site: selectedStation.site }
            : null;
        const list: Movement[] = [{ pc: currentPc, from: here, to: dest, kind: 'move' }];
        if (selectedStation?.pc_spec_id) {
            list.push(
                transferType === 'swap'
                    ? { pc: destinationPc, from: dest, to: here, kind: 'swap' }
                    : { pc: destinationPc, from: dest, to: null, kind: 'float' },
            );
        }
        return list;
    })();

    const handleSubmit = () => {
        if (!station || !isReady) {
            return;
        }
        const data =
            mode === 'assign'
                ? { to_station_id: station.id, pc_spec_id: selectedPcId, transfer_type: transferType, notes: notes.trim() || null }
                : { to_station_id: selectedStationId, pc_spec_id: station.pc_spec_id, transfer_type: transferType, notes: notes.trim() || null };
        const summary = summaryText();

        setProcessing(true);
        setErrors([]);
        router.post(pcTransfersTransferRoute().url, data, {
            preserveScroll: true,
            preserveState: true,
            only: reloadProps,
            onSuccess: () => {
                toast.success(`Transferred: ${summary}`);
                setReviewOpen(false);
                onOpenChange(false);
                onSuccess?.();
            },
            onError: (serverErrors) => {
                setErrors(Object.values(serverErrors).flat().map(String));
                setReviewOpen(false);
                toast.error('Transfer could not be completed.');
            },
            onFinish: () => setProcessing(false),
        });
    };

    if (!station) {
        return null;
    }

    const showReplaceChoice =
        (mode === 'assign' && hasPc && Boolean(selectedPc)) || (mode === 'move' && Boolean(selectedStation?.pc_spec_id));

    return (
        <Dialog open={open} onOpenChange={(next) => !processing && onOpenChange(next)}>
            <DialogContent className="max-w-[95vw] sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        Station {station.station_number}
                        {station.site && <span className="font-normal text-muted-foreground"> · {station.site}</span>}
                    </DialogTitle>
                    <DialogDescription>
                        {hasPc ? (
                            <>Currently has <span className="font-medium text-foreground">{pcLabel(currentPc)}</span>.</>
                        ) : (
                            'This station has no PC assigned.'
                        )}
                    </DialogDescription>
                </DialogHeader>

                {hasPc && (
                    <Tabs value={mode} onValueChange={(value) => setMode(value as Mode)}>
                        <TabsList className="w-full">
                            <TabsTrigger value="move">Move this PC</TabsTrigger>
                            <TabsTrigger value="assign">Put another PC here</TabsTrigger>
                        </TabsList>
                    </Tabs>
                )}

                <div className="space-y-4">
                    {mode === 'assign' ? (
                        <div className="space-y-1.5">
                            <div className="flex items-center justify-between gap-2">
                                <Label>PC to assign</Label>
                                <SiteFilterSelect value={pcSiteFilter} onChange={setPcSiteFilter} sites={sites} allLabel="Any site / unassigned" />
                            </div>
                            <PcPicker
                                pcSpecs={pcChoices}
                                value={selectedPcId}
                                onChange={setSelectedPcId}
                                preferredSiteId={station.site_id}
                            />
                            {selectedPc?.station && (
                                <p className="text-xs text-muted-foreground">
                                    {pcLabel(selectedPc)} will leave station {selectedPc.station.station_number}
                                    {selectedPc.station.site ? ` (${selectedPc.station.site})` : ''}.
                                </p>
                            )}
                        </div>
                    ) : (
                        <div className="space-y-1.5">
                            <div className="flex items-center justify-between gap-2">
                                <Label>Destination station</Label>
                                <SiteFilterSelect value={stationSiteFilter} onChange={setStationSiteFilter} sites={sites} allLabel="All sites" />
                            </div>
                            <StationPicker
                                stations={stationChoices}
                                value={selectedStationId}
                                onChange={setSelectedStationId}
                                preferredSiteId={station.site_id}
                            />
                            {stationChoices.length === 0 && (
                                <p className="text-xs text-muted-foreground">No other stations at this site — pick another site above.</p>
                            )}
                        </div>
                    )}

                    {crossSite && (
                        <div className="flex items-center gap-2 rounded-md border border-amber-400/60 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
                            <AlertCircle className="h-3.5 w-3.5 shrink-0" />
                            <span>
                                Cross-site move: <span className="font-semibold">{crossSite.from}</span> → <span className="font-semibold">{crossSite.to}</span>
                            </span>
                        </div>
                    )}

                    {showReplaceChoice && (
                        <div className="space-y-2 rounded-md border p-3">
                            <Label className="text-xs text-muted-foreground">
                                {mode === 'assign'
                                    ? `${pcLabel(currentPc)} is already here. What should happen to it?`
                                    : `${selectedStation?.station_number} already has ${pcLabel(destinationPc)}. What should happen to it?`}
                            </Label>
                            <RadioGroup value={transferType} onValueChange={(value) => setTransferType(value as TransferType)}>
                                <label className="flex cursor-pointer items-start gap-2 text-sm">
                                    <RadioGroupItem value="assign" className="mt-0.5" />
                                    <span>
                                        <span className="font-medium">Replace</span>
                                        <span className="text-muted-foreground"> — it becomes unassigned (floating)</span>
                                    </span>
                                </label>
                                <label className={`flex items-start gap-2 text-sm ${swapAvailable ? 'cursor-pointer' : 'opacity-50'}`}>
                                    <RadioGroupItem value="swap" className="mt-0.5" disabled={!swapAvailable} />
                                    <span>
                                        <span className="font-medium">Swap</span>
                                        <span className="text-muted-foreground">
                                            {' — '}
                                            {mode === 'assign'
                                                ? selectedPc?.station
                                                    ? `send it to ${selectedPc.station.station_number}`
                                                    : 'not available, the selected PC has no station to swap with'
                                                : `send it to ${station.station_number}`}
                                        </span>
                                    </span>
                                </label>
                            </RadioGroup>
                        </div>
                    )}

                    <div className="space-y-1.5">
                        <Label htmlFor="quick-transfer-notes">
                            Notes <span className="text-muted-foreground">(optional)</span>
                        </Label>
                        <Textarea
                            id="quick-transfer-notes"
                            value={notes}
                            onChange={(e) => setNotes(e.target.value)}
                            maxLength={500}
                            rows={2}
                            placeholder="Reason or special instructions"
                        />
                    </div>

                    {isReady && (
                        <div className="flex items-center gap-2 rounded-md bg-muted/50 px-3 py-2 text-sm">
                            <ArrowRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                            <span>{summaryText()}</span>
                        </div>
                    )}

                    {errors.length > 0 && (
                        <Alert variant="destructive">
                            <AlertCircle className="h-4 w-4" />
                            <AlertDescription>
                                <ul className="list-disc space-y-1 pl-4">
                                    {errors.map((error, index) => (
                                        <li key={index}>{error}</li>
                                    ))}
                                </ul>
                            </AlertDescription>
                        </Alert>
                    )}
                </div>

                <DialogFooter className="gap-2">
                    <Button variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button onClick={() => setReviewOpen(true)} disabled={!isReady || processing}>
                        Review transfer
                        <ArrowRight className="ml-2 h-4 w-4" />
                    </Button>
                </DialogFooter>
            </DialogContent>

            <ReviewDialog
                open={reviewOpen}
                onOpenChange={(next) => !processing && setReviewOpen(next)}
                movements={movements}
                crossSite={crossSite}
                notes={notes.trim()}
                processing={processing}
                onConfirm={handleSubmit}
            />
        </Dialog>
    );
}

interface ReviewDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    movements: Movement[];
    crossSite: { from: string; to: string } | null;
    notes: string;
    processing: boolean;
    onConfirm: () => void;
}

/** Final confirmation: every PC that moves, with its full specs and route. */
function ReviewDialog({ open, onOpenChange, movements, crossSite, notes, processing, onConfirm }: ReviewDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-[95vw] sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Confirm transfer</DialogTitle>
                    <DialogDescription>
                        Review the details below. This updates station assignments immediately and is recorded in the transfer history.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-3">
                    {movements.map((movement, index) => (
                        <MovementCard key={`${movement.pc?.id ?? 'pc'}-${index}`} movement={movement} />
                    ))}

                    {crossSite && (
                        <div className="flex items-center gap-2 rounded-md border border-amber-400/60 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
                            <AlertCircle className="h-3.5 w-3.5 shrink-0" />
                            <span>
                                Cross-site move: <span className="font-semibold">{crossSite.from}</span> → <span className="font-semibold">{crossSite.to}</span>
                            </span>
                        </div>
                    )}

                    {notes && (
                        <div className="rounded-md border px-3 py-2 text-sm">
                            <div className="text-xs font-medium text-muted-foreground">Notes</div>
                            <div className="whitespace-pre-wrap">{notes}</div>
                        </div>
                    )}
                </div>

                <DialogFooter className="gap-2">
                    <Button variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Back
                    </Button>
                    <Button onClick={onConfirm} disabled={processing}>
                        <Check className="mr-2 h-4 w-4" />
                        {processing ? 'Transferring…' : 'Yes, transfer'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

const stationText = (ref: StationRef) => (ref ? `${ref.station_number}${ref.site ? ` · ${ref.site}` : ''}` : 'Unassigned');

function MovementCard({ movement }: { movement: Movement }) {
    const { pc, from, to, kind } = movement;
    const d = pc?.details ?? {};
    const specs: Array<[string, string | null | undefined]> = [
        ['Manufacturer', d.manufacturer],
        ['Processor', d.processor],
        ['RAM', d.ram_gb ? `${d.ram_gb} GB${d.memory_type ? ` ${d.memory_type}` : ''}` : null],
        ['Storage', d.disk_gb ? `${d.disk_gb} GB` : null],
        ['Ports', d.available_ports],
    ];
    const shown = specs.filter(([, value]) => Boolean(value));

    return (
        <div className={cn('rounded-lg border p-3', kind === 'float' && 'border-orange-400/60 bg-orange-50/60 dark:bg-orange-950/20')}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                    <span className="text-base font-semibold text-blue-600 dark:text-blue-400">{pcLabel(pc)}</span>
                    {d.issue && (
                        <Badge variant="outline" className="h-5 border-amber-500 px-1.5 text-[10px] text-amber-600" title={d.issue}>
                            Issue
                        </Badge>
                    )}
                </div>
                <Badge variant={kind === 'float' ? 'outline' : 'secondary'} className={cn('text-[10px] uppercase tracking-wide', kind === 'float' && 'border-orange-400 text-orange-700 dark:text-orange-300')}>
                    {kind === 'float' ? 'Becomes unassigned' : kind === 'swap' ? 'Swap' : 'Move'}
                </Badge>
            </div>

            <div className="mt-2 flex items-center gap-2 text-sm">
                <span className={cn('rounded bg-muted px-2 py-0.5', !from && 'text-muted-foreground')}>{stationText(from)}</span>
                <ArrowRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                <span className={cn('rounded px-2 py-0.5 font-medium', to ? 'bg-green-500/15 text-green-700 dark:text-green-300' : 'bg-orange-500/15 text-orange-700 dark:text-orange-300')}>
                    {stationText(to)}
                </span>
            </div>

            {shown.length > 0 ? (
                <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-3">
                    {shown.map(([label, value]) => (
                        <div key={label} className="min-w-0">
                            <dt className="text-muted-foreground">{label}</dt>
                            <dd className="truncate font-medium" title={String(value)}>{value}</dd>
                        </div>
                    ))}
                </dl>
            ) : (
                <p className="mt-3 text-xs text-muted-foreground">No spec details recorded for this PC.</p>
            )}

            {d.notes && (
                <p className="mt-2 text-xs text-muted-foreground">
                    <span className="font-medium">PC notes:</span> {d.notes}
                </p>
            )}
        </div>
    );
}

interface SiteFilterSelectProps {
    value: string;
    onChange: (value: string) => void;
    sites: SiteOption[];
    allLabel: string;
}

function SiteFilterSelect({ value, onChange, sites, allLabel }: SiteFilterSelectProps) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger className="h-7 w-auto min-w-28 gap-1 px-2 text-xs" aria-label="Filter by site">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="all">{allLabel}</SelectItem>
                {sites.map((site) => (
                    <SelectItem key={site.id} value={String(site.id)}>
                        {site.name}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

interface PcPickerProps {
    pcSpecs: PcSpecOption[];
    value: number | null;
    onChange: (id: number) => void;
    preferredSiteId?: number;
}

function PcPicker({ pcSpecs, value, onChange, preferredSiteId }: PcPickerProps) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');

    // Available PCs first, then same-site PCs, then everything else.
    const sorted = useMemo(() => {
        const rank = (pc: PcSpecOption) => {
            if (!pc.station) return 0;
            if (preferredSiteId && pc.station.site_id === preferredSiteId) return 1;
            return 2;
        };
        return [...pcSpecs].sort((a, b) => rank(a) - rank(b) || pcLabel(a).localeCompare(pcLabel(b)));
    }, [pcSpecs, preferredSiteId]);

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) return sorted.slice(0, 100);
        return sorted
            .filter((pc) =>
                [pc.pc_number, pc.label, pc.details.manufacturer, pc.details.processor, pc.station?.station_number, pc.station?.site]
                    .filter(Boolean)
                    .some((field) => String(field).toLowerCase().includes(q)),
            )
            .slice(0, 100);
    }, [sorted, query]);

    const selected = value ? pcSpecs.find((pc) => pc.id === value) : null;

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button variant="outline" role="combobox" aria-expanded={open} className="w-full justify-between font-normal">
                    <span className="truncate">
                        {selected ? (
                            <>
                                {pcLabel(selected)}
                                <span className="text-muted-foreground">
                                    {' · '}
                                    {selected.station
                                        ? `At ${selected.station.station_number}${selected.station.site ? ` (${selected.station.site})` : ''}`
                                        : 'Available'}
                                </span>
                            </>
                        ) : (
                            'Search PC number, model, processor…'
                        )}
                    </span>
                    <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-(--radix-popover-trigger-width) min-w-72 p-0" align="start">
                <Command shouldFilter={false}>
                    <CommandInput placeholder="Search PCs…" value={query} onValueChange={setQuery} />
                    <CommandList>
                        <CommandEmpty>No PC found.</CommandEmpty>
                        <CommandGroup>
                            {filtered.map((pc) => (
                                <CommandItem
                                    key={pc.id}
                                    value={String(pc.id)}
                                    onSelect={() => {
                                        onChange(pc.id);
                                        setOpen(false);
                                        setQuery('');
                                    }}
                                    className="cursor-pointer"
                                >
                                    <Check className={`mr-2 h-4 w-4 shrink-0 ${value === pc.id ? 'opacity-100' : 'opacity-0'}`} />
                                    <div className="flex min-w-0 flex-1 items-center justify-between gap-2">
                                        <div className="min-w-0">
                                            <div className="truncate font-medium">{pcLabel(pc)}</div>
                                            <div className="truncate text-xs text-muted-foreground">
                                                {[pc.details.manufacturer, pc.details.processor, pc.details.ram_gb ? `${pc.details.ram_gb}GB` : null]
                                                    .filter(Boolean)
                                                    .join(' · ') || '—'}
                                            </div>
                                        </div>
                                        {pc.station ? (
                                            <Badge variant="secondary" className="shrink-0 text-xs">
                                                At {pc.station.station_number}
                                                {pc.station.site && <span className="ml-1 font-normal opacity-75">· {pc.station.site}</span>}
                                            </Badge>
                                        ) : (
                                            <Badge variant="outline" className="shrink-0 border-green-500 text-xs text-green-600">Available</Badge>
                                        )}
                                    </div>
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}

interface StationPickerProps {
    stations: StationOption[];
    value: number | null;
    onChange: (id: number) => void;
    preferredSiteId?: number;
}

function StationPicker({ stations, value, onChange, preferredSiteId }: StationPickerProps) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');

    // Same-site empty stations first so the likeliest destination is one click away.
    const sorted = useMemo(() => {
        const rank = (st: StationOption) => {
            const sameSite = preferredSiteId ? st.site_id === preferredSiteId : true;
            if (sameSite && !st.pc_spec_id) return 0;
            if (sameSite) return 1;
            if (!st.pc_spec_id) return 2;
            return 3;
        };
        return [...stations].sort((a, b) => rank(a) - rank(b));
    }, [stations, preferredSiteId]);

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) return sorted.slice(0, 100);
        return sorted
            .filter((st) =>
                [st.station_number, st.site, st.pc_number].filter(Boolean).some((field) => String(field).toLowerCase().includes(q)),
            )
            .slice(0, 100);
    }, [sorted, query]);

    const selected = value ? stations.find((st) => st.id === value) : null;

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button variant="outline" role="combobox" aria-expanded={open} className="w-full justify-between font-normal">
                    <span className="truncate">
                        {selected ? (
                            <>
                                {selected.station_number}
                                <span className="text-muted-foreground">
                                    {' · '}
                                    {selected.site ?? '—'} · {selected.pc_number ?? 'Empty'}
                                </span>
                            </>
                        ) : (
                            'Search station or site…'
                        )}
                    </span>
                    <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-(--radix-popover-trigger-width) min-w-72 p-0" align="start">
                <Command shouldFilter={false}>
                    <CommandInput placeholder="Search stations…" value={query} onValueChange={setQuery} />
                    <CommandList>
                        <CommandEmpty>No station found.</CommandEmpty>
                        <CommandGroup>
                            {filtered.map((st) => (
                                <CommandItem
                                    key={st.id}
                                    value={String(st.id)}
                                    onSelect={() => {
                                        onChange(st.id);
                                        setOpen(false);
                                        setQuery('');
                                    }}
                                    className="cursor-pointer"
                                >
                                    <Check className={`mr-2 h-4 w-4 shrink-0 ${value === st.id ? 'opacity-100' : 'opacity-0'}`} />
                                    <div className="flex min-w-0 flex-1 items-center justify-between gap-2">
                                        <div className="min-w-0">
                                            <div className="truncate font-medium">{st.station_number}</div>
                                            <div className="truncate text-xs text-muted-foreground">{st.site ?? '—'}</div>
                                        </div>
                                        {st.pc_spec_id ? (
                                            <Badge variant="secondary" className="shrink-0 text-xs">{st.pc_number ?? 'Has PC'}</Badge>
                                        ) : (
                                            <Badge variant="outline" className="shrink-0 border-green-500 text-xs text-green-600">Empty</Badge>
                                        )}
                                    </div>
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
