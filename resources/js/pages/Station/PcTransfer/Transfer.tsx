import { useState, useEffect } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { toast } from 'sonner';
import { ArrowLeft, Search, ArrowRight, Check, AlertCircle, X, MousePointer, CheckSquare, ListChecks } from 'lucide-react';
import UseAnimations from 'react-useanimations';
import help from 'react-useanimations/lib/help';

import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui/select';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';

import { index as pcTransferIndex, bulk as bulkTransferRoute } from '@/routes/pc-transfers';

// New reusable components and hooks
import { usePageMeta, useFlashMessage, usePageLoading } from '@/hooks';
import { LoadingOverlay } from '@/components/LoadingOverlay';

interface PcSpec {
    id: number;
    pc_number?: string | null;
    label: string;
    details: {
        pc_number?: string | null;
        model?: string;
        manufacturer?: string;
        processor?: string;
        ram_ddr?: string;
        ram_gb?: number;
        disk_type?: string;
        disk_gb?: number;
    };
    station?: {
        id: number;
        station_number: string;
        site: string;
        site_id: number;
        campaign: string;
        campaign_id: number;
    } | null;
    is_floating?: boolean; // Indicates PC was recently unassigned
}

interface Station {
    id: number;
    station_number: string;
    site: string;
    site_id: number;
    campaign: string;
    campaign_id: number;
    pc_spec_id: number | null;
    pc_spec_details: {
        pc_number?: string | null;
        model?: string;
        processor?: string;
        ram_ddr?: string;
        ram_gb?: number;
        disk_type?: string;
        disk_gb?: number;
    } | null;
}

interface Site {
    id: number;
    name: string;
}

interface Campaign {
    id: number;
    name: string;
}

interface Props {
    stations: Station[];
    pcSpecs: PcSpec[];
    filters: {
        sites: Site[];
        campaigns: Campaign[];
    };
    preselectedStationId?: number | null;
    flash?: { message?: string; type?: string } | null;
}

// Generate random hex color for unlimited color support
function generateRandomColor(): string {
    const hue = Math.floor(Math.random() * 360);
    const saturation = 65 + Math.random() * 20; // 65-85%
    const lightness = 75 + Math.random() * 10; // 75-85% for light backgrounds
    return `hsl(${hue}, ${saturation}%, ${lightness}%)`;
}

function generateDarkColor(baseColor: string): string {
    // Extract HSL values and create darker version for text/borders
    const match = baseColor.match(/hsl\((\d+),\s*([\d.]+)%,\s*([\d.]+)%\)/);
    if (match) {
        const [, h, s] = match;
        return `hsl(${h}, ${s}%, 35%)`; // Darker for text/borders
    }
    return baseColor;
}

interface Transfer {
    pcId: number;
    stationId: number;
    color: string; // HSL color string
    replacedPcId?: number; // ID of PC being replaced/made floating
}

export default function Transfer(props: Props) {
    const { stations, pcSpecs, preselectedStationId } = props;

    // Read 'pc' query param from URL
    const [autoSelectedPcId, setAutoSelectedPcId] = useState<number | null>(null);
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const pcIdParam = params.get('pc');
        if (pcIdParam) {
            const pcId = parseInt(pcIdParam, 10);
            if (!isNaN(pcId)) {
                setAutoSelectedPcId(pcId);
            }
        }
    }, []);

    // Use new hooks for cleaner code
    const { title, breadcrumbs } = usePageMeta({
        title: 'Bulk PC Transfer',
        breadcrumbs: [
            { title: 'PC Transfer', href: pcTransferIndex().url },
            { title: 'Transfer', href: '#' }
        ]
    });

    useFlashMessage(); // Automatically handles flash messages
    const isPageLoading = usePageLoading(); // Track page loading state

    // Ensure unique sites and campaigns (in case backend sends duplicates)
    const uniqueSites = Array.from(
        new Map(props.filters.sites.map(site => [site.id, site])).values()
    );
    const uniqueCampaigns = Array.from(
        new Map(props.filters.campaigns.map(campaign => [campaign.id, campaign])).values()
    );

    const [pcSearch, setPcSearch] = useState('');
    const [stationSearch, setStationSearch] = useState('');
    const [pcSiteFilter, setPcSiteFilter] = useState('all');
    const [pcCampaignFilter, setPcCampaignFilter] = useState('all');
    const [stationSiteFilter, setStationSiteFilter] = useState('all');
    const [stationCampaignFilter, setStationCampaignFilter] = useState('all');
    const [transfers, setTransfers] = useState<Transfer[]>([]);
    const [errors, setErrors] = useState<string[]>([]);
    const [processing, setProcessing] = useState(false);
    const [notes, setNotes] = useState('');
    const [highlightedStationId, setHighlightedStationId] = useState<number | null>(null);
    const [helpDialogOpen, setHelpDialogOpen] = useState(false);

    // Auto-select PC from preselected station OR highlight empty station
    useEffect(() => {
        // Existing logic for preselectedStationId
        if (preselectedStationId) {
            const preselectedStation = stations.find(s => s.id === preselectedStationId);
            if (preselectedStation?.pc_spec_id) {
                const pcId = preselectedStation.pc_spec_id;
                const color = generateRandomColor();
                setTransfers([{
                    pcId,
                    stationId: 0,
                    color
                }]);
                setTimeout(() => {
                    const pcRow = document.querySelector(`[data-pc-id="${pcId}"]`);
                    if (pcRow) {
                        pcRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }, 100);
            } else if (preselectedStation) {
                setHighlightedStationId(preselectedStationId);
                setTimeout(() => {
                    const stationRow = document.querySelector(`[data-station-id="${preselectedStationId}"]`);
                    if (stationRow) {
                        stationRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }, 100);
            }
        }
        // New logic for autoSelectedPcId from query param
        else if (autoSelectedPcId) {
            // Only auto-select if not already selected
            setTransfers(prev => {
                if (prev.some(t => t.pcId === autoSelectedPcId)) return prev;
                const color = generateRandomColor();
                return [{ pcId: autoSelectedPcId, stationId: 0, color }];
            });
            setTimeout(() => {
                const pcRow = document.querySelector(`[data-pc-id="${autoSelectedPcId}"]`);
                if (pcRow) {
                    pcRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }, 100);
        }
    }, [preselectedStationId, stations, autoSelectedPcId]);

    const filteredPcSpecs = pcSpecs.filter(pc => {
        const search = pcSearch.toLowerCase();
        const matchesSearch = (
            (pc.pc_number && pc.pc_number.toLowerCase().includes(search)) ||
            pc.label.toLowerCase().includes(search) ||
            (pc.details.model && pc.details.model.toLowerCase().includes(search)) ||
            (pc.details.processor && pc.details.processor.toLowerCase().includes(search))
        );

        const matchesSite = pcSiteFilter === 'all' || (pc.station && String(pc.station.site_id) === pcSiteFilter);
        const matchesCampaign = pcCampaignFilter === 'all' || (pc.station && String(pc.station.campaign_id) === pcCampaignFilter);

        return matchesSearch && matchesSite && matchesCampaign;
    });

    const filteredStations = stations.filter(station => {
        const search = stationSearch.toLowerCase();
        const matchesSearch = (
            station.station_number.toLowerCase().includes(search) ||
            station.site.toLowerCase().includes(search) ||
            station.campaign.toLowerCase().includes(search)
        );

        const matchesSite = stationSiteFilter === 'all' || String(station.site_id) === stationSiteFilter;
        const matchesCampaign = stationCampaignFilter === 'all' || String(station.campaign_id) === stationCampaignFilter;

        return matchesSearch && matchesSite && matchesCampaign;
    });

    function getTransferByPc(pcId: number): Transfer | undefined {
        return transfers.find(t => t.pcId === pcId);
    }

    function getTransferByStation(stationId: number): Transfer | undefined {
        return transfers.find(t => t.stationId === stationId);
    }

    function togglePcSelection(pcId: number) {
        const existing = getTransferByPc(pcId);
        if (existing) {
            // Prevent deselecting PC that was auto-selected from "Transfer PC" button
            if (preselectedStationId) {
                const preselectedStation = stations.find(s => s.id === preselectedStationId);
                if (preselectedStation?.pc_spec_id === pcId) {
                    toast.error('Cannot deselect this PC. It was auto-selected from the station. Use "Back" to cancel this transfer.');
                    return;
                }
            }

            // Remove this transfer
            setTransfers(prev => prev.filter(t => t.pcId !== pcId));
        } else {
            // Only allow one PC selection at a time when assigning to a preselected station
            const preselectedStation = preselectedStationId
                ? stations.find(s => s.id === preselectedStationId)
                : null;

            // If station is preselected (either empty OR has existing PC for transfer)
            if (preselectedStation && preselectedStationId) {
                // Block selection if there's already a PC selected for this preselected station
                if (transfers.length > 0) {
                    toast.error('Cannot select multiple PCs for a single station transfer. Deselect the current PC first.');
                    return;
                }

                const color = generateRandomColor();

                // Single selection mode - assign directly to preselected station if empty
                if (!preselectedStation.pc_spec_id) {
                    setTransfers([{
                        pcId,
                        stationId: preselectedStationId,
                        color
                    }]);
                    // Remove station highlight once PC is selected
                    setHighlightedStationId(null);
                } else {
                    // Station has PC - select this PC for transfer (station selection comes later)
                    setTransfers([{
                        pcId,
                        stationId: 0,
                        color
                    }]);
                }
            } else {
                // For bulk/multi-select mode, add new transfer
                const color = generateRandomColor();
                setTransfers(prev => [...prev, { pcId, stationId: 0, color }]);
            }
        }
        setErrors([]);
    }

    function selectStationForPc(stationId: number) {
        // Find the first incomplete transfer (where stationId is 0)
        const incompleteTransfer = transfers.find(t => t.stationId === 0);
        if (incompleteTransfer) {
            const targetStation = stations.find(s => s.id === stationId);

            // Check if station has a PC that will be replaced (made floating)
            const replacedPcId = targetStation?.pc_spec_id || undefined;

            setTransfers(prev => prev.map(t =>
                t.pcId === incompleteTransfer.pcId
                    ? { ...t, stationId, replacedPcId }
                    : t
            ));
        }
        setErrors([]);
    }

    function deselectStation(stationId: number) {
        // Prevent deselection of preselected station (from "Assign PC" or "Transfer PC" button)
        if (preselectedStationId && stationId === preselectedStationId) {
            toast.error('Cannot deselect the target station. Use "Back" to cancel this assignment.');
            return;
        }

        // Find transfer with this station and reset its stationId to 0
        setTransfers(prev => prev.map(t =>
            t.stationId === stationId
                ? { ...t, stationId: 0, replacedPcId: undefined }
                : t
        ));
        setErrors([]);
    }

    function clearTransfer(pcId: number) {
        // Prevent clearing transfer when PC was auto-selected from "Transfer PC" button
        if (preselectedStationId) {
            const preselectedStation = stations.find(s => s.id === preselectedStationId);
            if (preselectedStation?.pc_spec_id === pcId) {
                toast.error('Cannot deselect this PC. It was auto-selected from the station. Use "Back" to cancel this transfer.');
                return;
            }
        }

        setTransfers(prev => prev.filter(t => t.pcId !== pcId));
        setErrors([]);
    }

    // Create a transfer entry for a floating PC with a new random color
    function assignFloatingPc(pcId: number) {
        // If a transfer for this PC already exists, do nothing
        if (getTransferByPc(pcId)) return;

        // Generate new random color for this floating PC
        const newColor = generateRandomColor();

        // Add transfer with stationId = 0 (will require user to select station)
        setTransfers(prev => [...prev, { pcId, stationId: 0, color: newColor }]);
        setErrors([]);

        // Auto-scroll to the newly added transfer in Selected Transfers card
        setTimeout(() => {
            const transferCard = document.querySelector('[data-transfer-id="' + pcId + '"]');
            if (transferCard) {
                transferCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }, 100);
    }

    function validateTransfers(): string[] {
        const validationErrors: string[] = [];
        const usedStations = new Set<number>();
        const usedPcs = new Set<number>();

        transfers.forEach((transfer, index) => {
            const pc = pcSpecs.find(p => p.id === transfer.pcId);
            const station = stations.find(s => s.id === transfer.stationId);

            if (!transfer.stationId) {
                validationErrors.push(
                    `⚠️ Transfer #${index + 1}: PC "${pc?.pc_number || pc?.label}" has no station selected. Please select a destination station.`
                );
            }

            if (pc?.station && pc.station.id === transfer.stationId) {
                validationErrors.push(
                    `⚠️ Transfer #${index + 1}: PC "${pc.pc_number || pc.label}" is already at station "${station?.station_number}". No transfer needed.`
                );
            }

            if (usedStations.has(transfer.stationId) && transfer.stationId !== 0) {
                validationErrors.push(
                    `⚠️ Transfer #${index + 1}: Station "${station?.station_number}" is already assigned to another PC in this batch. Each station can only receive one PC.`
                );
            }

            if (usedPcs.has(transfer.pcId)) {
                validationErrors.push(
                    `⚠️ Transfer #${index + 1}: PC "${pc?.pc_number || pc?.label}" is selected multiple times. Each PC can only be transferred once.`
                );
            }

            usedStations.add(transfer.stationId);
            usedPcs.add(transfer.pcId);
        });

        return validationErrors;
    }

    // Check if a PC will become floating (be replaced by another PC)
    function isPcBecomingFloating(pcId: number): boolean {
        return transfers.some(t => t.replacedPcId === pcId);
    }

    function handleSubmit() {
        const validationErrors = validateTransfers();
        if (validationErrors.length > 0) {
            setErrors(validationErrors);
            toast.error(`Cannot proceed: ${validationErrors.length} validation error(s) found`);
            return;
        }

        setProcessing(true);
        setErrors([]);

        const payload = {
            transfers: transfers.map(t => {
                // Determine transfer type based on context
                const pc = pcSpecs.find(p => p.id === t.pcId);
                const fromStation = pc?.station;

                // If PC is moving from one station to another, it's an assign
                // (In bulk mode, we don't support swap - replaced PCs just become floating)
                const transferType = 'assign';

                return {
                    to_station_id: t.stationId,
                    pc_spec_id: t.pcId,
                    from_station_id: fromStation?.id || null,
                    transfer_type: transferType,
                };
            }),
            notes: notes.trim() || undefined, // Include notes if provided
        };

        router.post(bulkTransferRoute().url, payload, {
            preserveScroll: true,
            onSuccess: () => {
                const transferCount = transfers.length;
                const successToast = toast.success(`${transferCount} PC(s) transferred successfully!`, {
                    duration: 1500, // Show for 1.5 seconds before countdown
                });
                setTransfers([]);

                // Wait for success toast to finish, then show countdown
                setTimeout(() => {
                    toast.dismiss(successToast);

                    let countdown = 3;
                    const countdownToast = toast.loading(`Redirecting in ${countdown} seconds...`);

                    const countdownInterval = setInterval(() => {
                        countdown--;
                        if (countdown > 0) {
                            toast.loading(`Redirecting in ${countdown} seconds...`, { id: countdownToast });
                        } else {
                            clearInterval(countdownInterval);
                            toast.dismiss(countdownToast);
                            setProcessing(false);
                            router.visit(pcTransferIndex().url);
                        }
                    }, 1000);
                }, 1500);
            },
            onError: (errors) => {
                const errorMessages = Object.values(errors).flat().map(err => `❌ ${err}`);
                setErrors(errorMessages);
                toast.error('Transfer failed. Please check the errors below.');
                setProcessing(false);
            },
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 space-y-6 relative">
                {/* Loading overlay for page transitions */}
                <LoadingOverlay isLoading={isPageLoading} />

                {/* Header with Back Button and Transfer Action */}
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <Link href={pcTransferIndex().url}>
                            <Button variant="outline">
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back to Transfers
                            </Button>
                        </Link>
                        <Button
                            variant="outline"
                            size="icon"
                            onClick={() => setHelpDialogOpen(true)}
                            title="How to use PC Transfer"
                        >
                            <UseAnimations animation={help} size={25} strokeColor="currentColor" />
                        </Button>
                    </div>

                    {transfers.length > 0 && (
                        <div className="flex flex-col sm:flex-row items-center justify-end gap-2 sm:gap-4">
                            <span className="text-sm text-muted-foreground">
                                {transfers.filter(t => t.stationId !== 0).length} of {transfers.length} transfers ready
                            </span>
                            <div>
                                <Button
                                    onClick={handleSubmit}
                                    disabled={processing || transfers.some(t => t.stationId === 0)}
                                    className="w-full sm:w-auto flex items-center justify-center gap-2"
                                >
                                    <Check className="h-4 w-4" />
                                    Transfer {transfers.length} PC{transfers.length > 1 ? 's' : ''}
                                </Button>
                            </div>
                        </div>
                    )}
                </div>

                {/* Validation Errors */}
                {errors.length > 0 && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-4 w-4" />
                        <AlertDescription>
                            <div className="font-semibold mb-2">Transfer Validation Errors:</div>
                            <ul className="list-disc pl-5 space-y-1">
                                {errors.map((error, index) => (
                                    <li key={index} className="text-sm">{error}</li>
                                ))}
                            </ul>
                        </AlertDescription>
                    </Alert>
                )}

                {/* Selected Transfers Summary */}
                {transfers.length > 0 && (
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">Selected Transfers</CardTitle>
                            <CardDescription className="text-xs">Review and manage your selections</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-1.5">
                                {transfers.map((transfer) => {
                                    const pc = pcSpecs.find(p => p.id === transfer.pcId);
                                    const station = stations.find(s => s.id === transfer.stationId);
                                    const replacedPc = transfer.replacedPcId
                                        ? pcSpecs.find(p => p.id === transfer.replacedPcId)
                                        : null;
                                    const darkColor = generateDarkColor(transfer.color);

                                    return (
                                        <div key={transfer.pcId} data-transfer-id={transfer.pcId}>
                                            <div
                                                className="flex flex-col sm:flex-row items-start sm:items-center justify-between p-2 rounded border-2 space-y-2 sm:space-y-0"
                                                style={{
                                                    backgroundColor: transfer.color,
                                                    borderColor: darkColor,
                                                }}
                                            >
                                                <div className="flex items-center gap-2">
                                                    <div className="text-sm font-semibold" style={{ color: darkColor }}>
                                                        {pc?.pc_number || pc?.label}
                                                    </div>
                                                    <ArrowRight className="h-3 w-3" style={{ color: darkColor }} />
                                                    <div className="text-sm font-semibold" style={{ color: darkColor }}>
                                                        {station ? station.station_number : 'No station selected'}
                                                    </div>
                                                </div>
                                                <div className="w-full sm:w-auto flex justify-end">
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() => clearTransfer(transfer.pcId)}
                                                        className="h-6 w-6 p-0"
                                                    >
                                                        <X className="h-3 w-3" />
                                                    </Button>
                                                </div>
                                            </div>

                                            {replacedPc && (
                                                <div className="mt-1 p-2 bg-orange-50 border-l-2 border-orange-400 rounded text-xs">
                                                    <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2">
                                                        <div className="flex items-center gap-1.5 text-orange-700">
                                                            <AlertCircle className="h-3 w-3 flex-shrink-0" />
                                                            <div>
                                                                <span className="font-medium">Floating PC: </span>
                                                                <span className="font-semibold">{replacedPc.pc_number || replacedPc.label}</span>
                                                                <span className="text-orange-600"> - will be unassigned</span>
                                                            </div>
                                                        </div>
                                                        <div className="w-full sm:w-auto">
                                                            <Button
                                                                size="sm"
                                                                onClick={() => assignFloatingPc(replacedPc.id)}
                                                                className="h-6 text-xs px-2 w-full sm:w-auto"
                                                            >
                                                                Assign
                                                            </Button>
                                                        </div>
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-sm font-medium">Transfer Notes (Optional)</CardTitle>
                    </CardHeader>
                    <CardContent className="p-3 pt-0">
                        <Textarea
                            placeholder="Add optional notes about this transfer (e.g., reason, special instructions)..."
                            value={notes}
                            onChange={(e) => setNotes(e.target.value)}
                            rows={3}
                            maxLength={500}
                            className="resize-none"
                        />
                        <div className="text-xs text-muted-foreground mt-1">
                            {notes.length}/500 characters
                        </div>
                    </CardContent>
                </Card>

                {/* Split View: PC Specs (Left) and Stations (Right) */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {/* PC Specs Table - Left Side */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Available PC Specs</CardTitle>
                            <CardDescription>
                                {preselectedStationId && !stations.find(s => s.id === preselectedStationId)?.pc_spec_id
                                    ? `Select a PC to assign to station ${stations.find(s => s.id === preselectedStationId)?.station_number}`
                                    : 'Click the "Select" button to add PCs to your transfer list'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="mb-4 space-y-3">
                                <div className="relative">
                                    <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                                    <Input
                                        type="text"
                                        placeholder="Search PCs..."
                                        className="pl-10"
                                        value={pcSearch}
                                        onChange={(e) => setPcSearch(e.target.value)}
                                    />
                                </div>

                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <div>
                                        <Label className="text-xs text-muted-foreground">Site Filter</Label>
                                        <Select value={pcSiteFilter} onValueChange={setPcSiteFilter}>
                                            <SelectTrigger className="h-9">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">All Sites</SelectItem>
                                                {uniqueSites.map((site) => (
                                                    <SelectItem key={site.id} value={String(site.id)}>
                                                        {site.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div>
                                        <Label className="text-xs text-muted-foreground">Campaign Filter</Label>
                                        <Select value={pcCampaignFilter} onValueChange={setPcCampaignFilter}>
                                            <SelectTrigger className="h-9">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">All Campaigns</SelectItem>
                                                {uniqueCampaigns.map((campaign) => (
                                                    <SelectItem key={campaign.id} value={String(campaign.id)}>
                                                        {campaign.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                            </div>

                            <div className="border rounded-lg max-h-[600px] overflow-x-auto overflow-y-auto">
                                <Table>
                                    <TableHeader className="sticky top-0 bg-background z-10">
                                        <TableRow>
                                            <TableHead>PC Number</TableHead>
                                            <TableHead className="hidden md:table-cell">Model</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead className="text-right">Action</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {filteredPcSpecs.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={4} className="text-center py-8 text-muted-foreground">
                                                    No PC specs found
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            filteredPcSpecs.map((pc) => {
                                                const transfer = getTransferByPc(pc.id);
                                                const darkColor = transfer ? generateDarkColor(transfer.color) : null;
                                                const willBeFloating = isPcBecomingFloating(pc.id);

                                                // Disable other PCs when in single-station mode with a PC already selected
                                                const isSingleStationMode = !!preselectedStationId;
                                                const hasSelection = transfers.length > 0;
                                                const isDisabled = isSingleStationMode && hasSelection && !transfer && !willBeFloating;

                                                return (
                                                    <TableRow
                                                        key={pc.id}
                                                        data-pc-id={pc.id}
                                                        className="hover:bg-muted/50"
                                                        style={transfer ? {
                                                            backgroundColor: transfer.color,
                                                            borderLeftWidth: '4px',
                                                            borderLeftColor: darkColor || undefined,
                                                        } : willBeFloating ? {
                                                            backgroundColor: '#fff7ed',
                                                            borderLeftWidth: '4px',
                                                            borderLeftColor: '#fb923c',
                                                        } : {}}
                                                    >
                                                        <TableCell>
                                                            <span
                                                                className="font-medium"
                                                                style={{ color: darkColor || (willBeFloating ? '#ea580c' : '#2563eb') }}
                                                            >
                                                                {pc.pc_number || '-'}
                                                            </span>
                                                        </TableCell>
                                                        <TableCell className="hidden md:table-cell">
                                                            <div style={{ color: darkColor || undefined }}>
                                                                <div className="font-medium text-sm">{pc.details.model || '-'}</div>
                                                                <div className="text-xs text-muted-foreground">{pc.details.processor || '-'}</div>
                                                            </div>
                                                        </TableCell>
                                                        <TableCell>
                                                            {willBeFloating ? (
                                                                <div className="space-y-1">
                                                                    <Badge variant="outline" className="text-xs bg-orange-100 border-orange-400 text-orange-700">
                                                                        🔄 Will be floating
                                                                    </Badge>
                                                                    <div className="text-xs text-orange-600">
                                                                        Being replaced, can reassign
                                                                    </div>
                                                                    <div className="text-xs text-orange-600">
                                                                        assigned currently {pc.station?.station_number}
                                                                    </div>
                                                                </div>
                                                            ) : pc.station ? (
                                                                <Badge variant="secondary" className="text-xs">
                                                                    At {pc.station.station_number}
                                                                </Badge>
                                                            ) : (
                                                                <Badge variant="outline" className="text-xs">
                                                                    Available
                                                                </Badge>
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="text-right">
                                                            {transfer ? (
                                                                <Button
                                                                    size="sm"
                                                                    variant="outline"
                                                                    onClick={() => togglePcSelection(pc.id)}
                                                                    style={{
                                                                        borderColor: darkColor || undefined,
                                                                        color: darkColor || undefined
                                                                    }}
                                                                >
                                                                    <X className="h-4 w-4 mr-1" />
                                                                    Deselect
                                                                </Button>
                                                            ) : willBeFloating ? (
                                                                <Button
                                                                    size="sm"
                                                                    variant="ghost"
                                                                    disabled
                                                                    className="text-orange-600"
                                                                >
                                                                    Being Replaced
                                                                </Button>
                                                            ) : (
                                                                <Button
                                                                    size="sm"
                                                                    onClick={() => togglePcSelection(pc.id)}
                                                                    disabled={isDisabled}
                                                                >
                                                                    Select
                                                                </Button>
                                                            )}
                                                        </TableCell>
                                                    </TableRow>
                                                );
                                            })
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Stations Table - Right Side */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Destination Stations</CardTitle>
                            <CardDescription>
                                {preselectedStationId && !stations.find(s => s.id === preselectedStationId)?.pc_spec_id
                                    ? 'Select a PC from the left to assign to the highlighted station'
                                    : transfers.find(t => t.stationId === 0)
                                        ? 'Select a station for the highlighted PC'
                                        : 'Select PCs first, then choose destination stations'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="mb-4 space-y-3">
                                <div className="relative">
                                    <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                                    <Input
                                        type="text"
                                        placeholder="Search stations..."
                                        className="pl-10"
                                        value={stationSearch}
                                        onChange={(e) => setStationSearch(e.target.value)}
                                    />
                                </div>

                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <div>
                                        <Label className="text-xs text-muted-foreground">Site Filter</Label>
                                        <Select value={stationSiteFilter} onValueChange={setStationSiteFilter}>
                                            <SelectTrigger className="h-9">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">All Sites</SelectItem>
                                                {uniqueSites.map((site) => (
                                                    <SelectItem key={site.id} value={String(site.id)}>
                                                        {site.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div>
                                        <Label className="text-xs text-muted-foreground">Campaign Filter</Label>
                                        <Select value={stationCampaignFilter} onValueChange={setStationCampaignFilter}>
                                            <SelectTrigger className="h-9">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">All Campaigns</SelectItem>
                                                {uniqueCampaigns.map((campaign) => (
                                                    <SelectItem key={campaign.id} value={String(campaign.id)}>
                                                        {campaign.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                            </div>

                            <div className="border rounded-lg max-h-[600px] overflow-x-auto overflow-y-auto">
                                <Table>
                                    <TableHeader className="sticky top-0 bg-background z-10">
                                        <TableRow>
                                            <TableHead>Station Number</TableHead>
                                            <TableHead className="hidden md:table-cell">Site</TableHead>
                                            <TableHead>Current PC</TableHead>
                                            <TableHead className="text-right">Action</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {filteredStations.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={4} className="text-center py-8 text-muted-foreground">
                                                    No stations found
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            filteredStations.map((station) => {
                                                const transfer = getTransferByStation(station.id);
                                                const darkColor = transfer ? generateDarkColor(transfer.color) : null;
                                                const hasIncomplete = transfers.some(t => t.stationId === 0);
                                                const isHighlighted = highlightedStationId === station.id;

                                                return (
                                                    <TableRow
                                                        key={station.id}
                                                        data-station-id={station.id}
                                                        className={`hover:bg-muted/50 transition-all duration-300 ${isHighlighted ? 'bg-blue-100 dark:bg-blue-900/30 ring-2 ring-blue-500 ring-inset' : ''
                                                            }`}
                                                        style={transfer ? {
                                                            backgroundColor: transfer.color,
                                                            borderLeftWidth: '4px',
                                                            borderLeftColor: darkColor || undefined,
                                                        } : {}}
                                                    >
                                                        <TableCell>
                                                            <span
                                                                className="font-medium"
                                                                style={{ color: darkColor || undefined }}
                                                            >
                                                                {station.station_number}
                                                            </span>
                                                        </TableCell>
                                                        <TableCell className="hidden md:table-cell" style={{ color: darkColor || undefined }}>
                                                            <div className="text-sm">{station.site}</div>
                                                            <div className="text-xs text-muted-foreground">{station.campaign}</div>
                                                        </TableCell>
                                                        <TableCell>
                                                            {station.pc_spec_details ? (
                                                                <div className="text-sm">
                                                                    <div className="font-medium text-blue-600">
                                                                        {station.pc_spec_details.pc_number || 'Assigned'}
                                                                    </div>
                                                                </div>
                                                            ) : (
                                                                <Badge variant="outline" className="text-xs">Empty</Badge>
                                                            )}
                                                        </TableCell>
                                                        <TableCell className="text-right">
                                                            {transfer ? (
                                                                <Button
                                                                    size="sm"
                                                                    variant="outline"
                                                                    onClick={() => deselectStation(station.id)}
                                                                    className="gap-2"
                                                                    style={{
                                                                        borderColor: darkColor || undefined,
                                                                        color: darkColor || undefined
                                                                    }}
                                                                >
                                                                    <X className="h-3 w-3" />
                                                                    Deselect
                                                                </Button>
                                                            ) : (
                                                                <Button
                                                                    size="sm"
                                                                    onClick={() => selectStationForPc(station.id)}
                                                                    disabled={!hasIncomplete}
                                                                >
                                                                    Select
                                                                </Button>
                                                            )}
                                                        </TableCell>
                                                    </TableRow>
                                                );
                                            })
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Help Dialog */}
                <Dialog open={helpDialogOpen} onOpenChange={setHelpDialogOpen}>
                    <DialogContent className="max-w-[95vw] sm:max-w-2xl max-h-[90vh] overflow-y-auto">
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2">
                                <UseAnimations animation={help} size={20} strokeColor="currentColor" />
                                How to Use PC Transfer
                            </DialogTitle>
                            <DialogDescription>
                                Follow these steps to transfer PCs to stations
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-6 py-4">
                            {/* Assign PC (Empty Station) */}
                            <div className="space-y-3">
                                <h3 className="font-semibold text-lg flex items-center gap-2">
                                    <MousePointer className="h-5 w-5 text-blue-600" />
                                    Assign PC (Empty Station)
                                </h3>
                                <div className="space-y-2 pl-7 text-sm">
                                    <div className="flex items-start gap-2">
                                        <span className="font-bold text-blue-600">1.</span>
                                        <div>
                                            <span className="font-medium">From Station Page:</span> Click <Badge variant="outline" className="mx-1">Assign PC</Badge> button on an empty station
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-2">
                                        <span className="font-bold text-blue-600">2.</span>
                                        <div>
                                            <span className="font-medium">Station Highlighted:</span> The target station will be <span className="bg-blue-100 px-2 py-0.5 rounded">highlighted in blue</span>
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-2">
                                        <span className="font-bold text-blue-600">3.</span>
                                        <div>
                                            <span className="font-medium">Select PC:</span> Click <Badge variant="outline" className="mx-1">Select</Badge> on any PC from the left table (only one PC allowed)
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-2">
                                        <span className="font-bold text-blue-600">4.</span>
                                        <div>
                                            <span className="font-medium">Auto-Assignment:</span> PC automatically assigns to the highlighted station. Other PCs become unselectable.
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-2">
                                        <span className="font-bold text-blue-600">5.</span>
                                        <div>
                                            <span className="font-medium">Submit:</span> Click <Badge className="mx-1 bg-blue-600">Transfer 1 PC</Badge> to complete
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div className="border-t pt-4" />

                            {/* Transfer PC (Station with PC) */}
                            <div className="space-y-3">
                                <h3 className="font-semibold text-lg flex items-center gap-2">
                                    <ArrowRight className="h-5 w-5 text-indigo-600" />
                                    Transfer PC (Station with Existing PC)
                                </h3>
                                <div className="space-y-2 pl-7 text-sm">
                                    <div className="flex items-start gap-2">
                                        <span className="font-bold text-indigo-600">1.</span>
                                        <div>
                                            <span className="font-medium">From Station Page:</span> Click <Badge variant="outline" className="mx-1">Transfer PC</Badge> button on a station with a PC
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-2">
                                        <span className="font-bold text-indigo-600">2.</span>
                                        <div>
                                            <span className="font-medium">Auto-Selection:</span> The PC currently at that station is automatically selected and <span className="font-semibold">locked</span>
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-2">
                                        <span className="font-bold text-indigo-600">3.</span>
                                        <div>
                                            <span className="font-medium">Cannot Deselect:</span> The selected PC cannot be deselected (use "Back" to cancel entire transfer)
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-2">
                                        <span className="font-bold text-indigo-600">4.</span>
                                        <div>
                                            <span className="font-medium">Choose Destination:</span> Click <Badge variant="outline" className="mx-1">Select</Badge> on a destination station from the right table
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-2">
                                        <span className="font-bold text-indigo-600">5.</span>
                                        <div>
                                            <span className="font-medium">Submit:</span> Click <Badge className="mx-1 bg-blue-600">Transfer 1 PC</Badge> to move the PC to the new station
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div className="border-t pt-4" />

                            {/* Multiple Station Assignment */}
                            <div className="space-y-3">
                                <h3 className="font-semibold text-lg flex items-center gap-2">
                                    <CheckSquare className="h-5 w-5 text-green-600" />
                                    Multiple Stations (Bulk Mode)
                                </h3>
                                <div className="space-y-2 pl-7 text-sm">
                                    <div className="flex items-start gap-2">
                                        <span className="font-bold text-green-600">1.</span>
                                        <div>
                                            <span className="font-medium">From Station Page:</span> Check <Badge variant="outline" className="mx-1">☑</Badge> multiple empty stations
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-2">
                                        <span className="font-bold text-green-600">2.</span>
                                        <div>
                                            <span className="font-medium">Bulk Action:</span> Click <Badge variant="outline" className="mx-1">Assign PCs to Selected</Badge>
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-2">
                                        <span className="font-bold text-green-600">3.</span>
                                        <div>
                                            <span className="font-medium">Select PCs:</span> Click <Badge variant="outline" className="mx-1">Select</Badge> on each PC you want to assign
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-2">
                                        <span className="font-bold text-green-600">4.</span>
                                        <div>
                                            <span className="font-medium">Choose Stations:</span> For each PC, click <Badge variant="outline" className="mx-1">Select</Badge> on a destination station (right side)
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-2">
                                        <span className="font-bold text-green-600">5.</span>
                                        <div>
                                            <span className="font-medium">Review:</span> Check <span className="bg-purple-50 border border-purple-300 px-2 py-0.5 rounded">Selected Transfers</span> card for accuracy
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-2">
                                        <span className="font-bold text-green-600">6.</span>
                                        <div>
                                            <span className="font-medium">Submit:</span> Click <Badge className="mx-1 bg-blue-600">Transfer X PCs</Badge> to complete all at once
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div className="border-t pt-4" />

                            {/* Color Coding System */}
                            <div className="space-y-3">
                                <h3 className="font-semibold text-lg flex items-center gap-2">
                                    <ListChecks className="h-5 w-5 text-purple-600" />
                                    Understanding Color Codes
                                </h3>
                                <div className="space-y-2 pl-7 text-sm">
                                    <div className="flex items-start gap-3">
                                        <div className="w-6 h-6 rounded border-2 border-blue-500 bg-blue-100 flex-shrink-0 mt-0.5" />
                                        <div>
                                            <span className="font-medium">Blue Highlight:</span> Station waiting for PC assignment (single mode)
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-3">
                                        <div className="w-6 h-6 rounded border-2 border-purple-500 bg-purple-100 flex-shrink-0 mt-0.5" />
                                        <div>
                                            <span className="font-medium">Random Colors:</span> Each PC-to-station transfer pair gets a unique color
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-3">
                                        <div className="w-6 h-6 rounded border-2 border-orange-400 bg-orange-50 flex-shrink-0 mt-0.5" />
                                        <div>
                                            <span className="font-medium">Orange Alert:</span> A PC will become "floating" (unassigned) when replaced
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div className="border-t pt-4" />

                            {/* Quick Tips */}
                            <div className="space-y-3">
                                <h3 className="font-semibold text-lg flex items-center gap-2">
                                    <AlertCircle className="h-5 w-5 text-amber-600" />
                                    Quick Tips
                                </h3>
                                <div className="space-y-2 pl-7 text-sm">
                                    <div className="flex items-start gap-2">
                                        <ArrowRight className="h-4 w-4 text-amber-600 flex-shrink-0 mt-0.5" />
                                        <div><span className="font-medium">Deselect:</span> Click <Badge variant="outline" className="mx-1">Deselect</Badge> to remove a PC or station from transfer</div>
                                    </div>
                                    <div className="flex items-start gap-2">
                                        <ArrowRight className="h-4 w-4 text-amber-600 flex-shrink-0 mt-0.5" />
                                        <div><span className="font-medium">Floating PCs:</span> Click <Badge className="mx-1 bg-orange-600">Assign</Badge> to reassign a floating PC</div>
                                    </div>
                                    <div className="flex items-start gap-2">
                                        <ArrowRight className="h-4 w-4 text-amber-600 flex-shrink-0 mt-0.5" />
                                        <div><span className="font-medium">Notes:</span> Add optional notes before transferring for record-keeping</div>
                                    </div>
                                    <div className="flex items-start gap-2">
                                        <ArrowRight className="h-4 w-4 text-amber-600 flex-shrink-0 mt-0.5" />
                                        <div><span className="font-medium">Search:</span> Use search boxes to quickly find specific PCs or stations</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div className="flex justify-end pt-4 border-t">
                            <Button onClick={() => setHelpDialogOpen(false)}>
                                Got it!
                            </Button>
                        </div>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
