import React, { useState, useEffect } from "react";
import { router, usePage, useForm, Head } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import AppLayout from "@/layouts/app-layout";
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from "@/components/ui/select";
import { Input } from "@/components/ui/input";
import PcSpecTable from "@/components/PcSpecTable";
import MonitorSpecTable from "@/components/MonitorSpecTable";
import { toast } from "sonner";
import { Label } from "@/components/ui/label";
import { Checkbox } from "@/components/ui/checkbox";
import type { BreadcrumbItem } from "@/types";
import { index as stationsIndex } from "@/routes/stations";

const breadcrumbs: BreadcrumbItem[] = [
    { title: "Stations", href: stationsIndex().url }
];

interface Site { id: number; name: string; }
interface Campaign { id: number; name: string; }
interface MonitorSpec {
    id: number;
    brand: string;
    model: string;
    screen_size: number;
    resolution: string;
    panel_type: string;
}
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
    const { sites, campaigns, pcSpecs, usedPcSpecIds, monitorSpecs } = usePage<{
        sites: Site[];
        campaigns: Campaign[];
        pcSpecs: PcSpec[];
        usedPcSpecIds: number[];
        monitorSpecs: MonitorSpec[];
    }>().props;

    const [bulkMode, setBulkMode] = useState(false);
    const [showNoSpecWarning, setShowNoSpecWarning] = useState(false);
    const [showSpecSelectedInfo, setShowSpecSelectedInfo] = useState(false);

    const { data, setData, post, processing, errors } = useForm({
        site_id: "",
        station_number: "",
        campaign_id: "",
        status: "",
        monitor_type: "single",
        pc_spec_id: "",
        pc_spec_ids: [] as string[], // For bulk mode
        monitor_ids: [] as Array<{ id: number; quantity: number }>,
        quantity: "1",
        starting_number: "",
        increment_type: "number", // "number", "letter", or "both"
    });

    // Auto-hide messages after 6 seconds when PC spec selection changes
    useEffect(() => {
        const hasSpec = bulkMode
            ? data.pc_spec_ids.length > 0
            : !!data.pc_spec_id;
        setShowNoSpecWarning(!hasSpec);
        setShowSpecSelectedInfo(hasSpec);

        const timer = setTimeout(() => {
            setShowNoSpecWarning(false);
            setShowSpecSelectedInfo(false);
        }, 4000);

        return () => clearTimeout(timer);
    }, [data.pc_spec_id, data.pc_spec_ids, bulkMode]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        const endpoint = bulkMode ? "/stations/bulk" : "/stations";

        post(endpoint, {
            onSuccess: () => {
                const message = bulkMode
                    ? `Successfully created ${data.quantity} station(s)`
                    : "Station created";
                toast.success(message);
                router.get("/stations");
            },
            onError: (errors) => {
                const firstError = Object.values(errors)[0] as string;
                toast.error(firstError || "Validation error");
            },
        });
    };

    // Generate preview of station numbers
    const generatePreview = () => {
        if (!data.starting_number || !data.quantity) return "";

        const qty = parseInt(data.quantity);
        if (qty < 1) return "";

        const starting = data.starting_number;
        const previews: string[] = [];

        // Match pattern like "PC-1A" or "ST-10B"
        const match = starting.match(/^(.*?)(\d+)([A-Za-z]?)(.*)$/);

        if (!match) {
            // No numeric part, just append numbers
            for (let i = 0; i < Math.min(3, qty); i++) {
                previews.push(`${starting}${i + 1}`);
            }
        } else {
            const prefix = match[1];
            const numPart = parseInt(match[2]);
            const letterPart = match[3];
            const suffix = match[4];
            const numLength = match[2].length;

            for (let i = 0; i < Math.min(3, qty); i++) {
                let newNum = numPart;
                let newLetter = letterPart;

                if (data.increment_type === "number") {
                    newNum = numPart + i;
                } else if (data.increment_type === "letter" && letterPart) {
                    const charCode = letterPart.charCodeAt(0);
                    const isUpper = letterPart === letterPart.toUpperCase();
                    const baseCode = isUpper ? 65 : 97; // 'A' or 'a'
                    const newCharCode = baseCode + ((charCode - baseCode + i) % 26);
                    newLetter = String.fromCharCode(newCharCode);
                } else if (data.increment_type === "both") {
                    newNum = numPart + i;
                    if (letterPart) {
                        const charCode = letterPart.charCodeAt(0);
                        const isUpper = letterPart === letterPart.toUpperCase();
                        const baseCode = isUpper ? 65 : 97;
                        const newCharCode = baseCode + ((charCode - baseCode + i) % 26);
                        newLetter = String.fromCharCode(newCharCode);
                    }
                }

                const paddedNum = String(newNum).padStart(numLength, '0');
                previews.push(`${prefix}${paddedNum}${newLetter}${suffix}`);
            }
        }

        return previews.join(", ") + (qty > 3 ? ", ..." : "");
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create Station" />
            <div className="max-w-7xl mx-auto mt-4 p-3 md:p-6">
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-4 gap-3">
                    <h2 className="text-xl md:text-2xl font-semibold">Add Station</h2>
                    <div className="flex items-center space-x-2">
                        <Checkbox
                            id="bulk-mode"
                            checked={bulkMode}
                            onCheckedChange={(checked) => setBulkMode(checked as boolean)}
                        />
                        <Label htmlFor="bulk-mode" className="cursor-pointer text-sm sm:text-base">
                            Create Multiple Stations
                        </Label>
                    </div>
                </div>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <Label>Site</Label>
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

                        {!bulkMode ? (
                            <div>
                                <Label>Station Number</Label>
                                <Input
                                    value={data.station_number}
                                    onChange={e => setData("station_number", e.target.value.toUpperCase())}
                                    placeholder="Station Number"
                                    required
                                />
                                {errors.station_number && <p className="text-red-600 text-sm mt-1">{errors.station_number}</p>}
                            </div>
                        ) : (
                            <>
                                <div>
                                    <Label>Starting Station Number</Label>
                                    <Input
                                        value={data.starting_number}
                                        onChange={e => setData("starting_number", e.target.value.toUpperCase())}
                                        placeholder="e.g., PC-1A, ST-001, PC-10B"
                                        required
                                    />
                                    <p className="text-xs text-gray-500 mt-1">
                                        💡 Supports: <code className="bg-gray-100 px-1 rounded text-xs">PC-1A</code>, <code className="bg-gray-100 px-1 rounded text-xs">ST-001</code>, <code className="bg-gray-100 px-1 rounded text-xs">WS-10B</code>, etc.
                                    </p>
                                    {errors.starting_number && <p className="text-red-600 text-sm mt-1">{errors.starting_number}</p>}
                                </div>
                                <div>
                                    <Label>Quantity</Label>
                                    <Input
                                        type="number"
                                        min="1"
                                        max="100"
                                        value={data.quantity}
                                        onChange={e => setData("quantity", e.target.value)}
                                        placeholder="Number of stations to create"
                                        required
                                    />
                                    {errors.quantity && <p className="text-red-600 text-sm mt-1">{errors.quantity}</p>}
                                </div>
                                <div className="md:col-span-2">
                                    <Label>Increment Type</Label>
                                    <Select value={data.increment_type} onValueChange={(val) => setData("increment_type", val)}>
                                        <SelectTrigger className="w-full">
                                            <SelectValue placeholder="Select increment type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="number">Number Only (PC-1A, PC-2A, PC-3A)</SelectItem>
                                            <SelectItem value="letter">Letter Only (PC-1A, PC-1B, PC-1C)</SelectItem>
                                            <SelectItem value="both">Both (PC-1A, PC-2B, PC-3C)</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <div className="mt-2 p-3 bg-amber-50 border border-amber-200 rounded-md">
                                        <p className="text-xs text-amber-900 font-medium mb-1">📋 Increment Examples:</p>
                                        <ul className="text-xs text-amber-800 space-y-0.5">
                                            <li className="break-words"><strong>Number Only:</strong> Use when stations are in same row/section (PC-1A → PC-2A → PC-3A)</li>
                                            <li className="break-words"><strong>Letter Only:</strong> Use when stations share same number (PC-1A → PC-1B → PC-1C)</li>
                                            <li className="break-words"><strong>Both:</strong> Use for diagonal or combined patterns (PC-1A → PC-2B → PC-3C)</li>
                                        </ul>
                                    </div>
                                    {errors.increment_type && <p className="text-red-600 text-sm mt-1">{errors.increment_type}</p>}
                                </div>
                            </>
                        )}

                        <div>
                            <Label>Campaign</Label>
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
                            <Label>Status</Label>
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
                        <div>
                            <Label>Monitor Type</Label>
                            <Select value={data.monitor_type} onValueChange={(val) => setData("monitor_type", val)}>
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Select Monitor Type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="single">Single Monitor</SelectItem>
                                    <SelectItem value="dual">Dual Monitor</SelectItem>
                                </SelectContent>
                            </Select>
                            {errors.monitor_type && <p className="text-red-600 text-sm mt-1">{errors.monitor_type}</p>}
                        </div>
                    </div>

                    {bulkMode && (
                        <div className="bg-blue-50 border border-blue-200 rounded p-3 sm:p-4">
                            <p className="text-xs sm:text-sm text-blue-800 break-words">
                                <strong>Preview:</strong> This will create {data.quantity || 0} station(s) with numbers:
                                <span className="font-mono ml-2 block sm:inline mt-1 sm:mt-0">
                                    {generatePreview() || "[Enter starting number]"}
                                </span>
                            </p>
                        </div>
                    )}

                    <div>
                        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-2 gap-2">
                            <label className="block font-medium text-sm sm:text-base">
                                Select PC Spec{bulkMode ? 's' : ''} (Optional)
                                {bulkMode && <span className="text-sm text-gray-500 ml-2">({data.pc_spec_ids.length} selected)</span>}
                            </label>
                            {((!bulkMode && data.pc_spec_id) || (bulkMode && data.pc_spec_ids.length > 0)) && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        if (bulkMode) {
                                            setData("pc_spec_ids", []);
                                        } else {
                                            setData("pc_spec_id", "");
                                        }
                                    }}
                                    className="text-xs text-red-600 hover:text-red-700 hover:bg-red-50 w-full sm:w-auto"
                                >
                                    ✕ Clear Selection{bulkMode ? 's' : ''}
                                </Button>
                            )}
                        </div>
                        {((bulkMode && data.pc_spec_ids.length === 0) || (!bulkMode && !data.pc_spec_id)) && showNoSpecWarning && (
                            <div className="bg-yellow-50 border border-yellow-200 rounded-md p-3 mb-3 transition-opacity duration-300">
                                <p className="text-xs sm:text-sm text-yellow-800 break-words">
                                    ⚠️ No PC spec{bulkMode ? 's' : ''} selected - Station{bulkMode ? 's' : ''} will be saved without PC specifications
                                </p>
                            </div>
                        )}
                        {((bulkMode && data.pc_spec_ids.length > 0) || (!bulkMode && data.pc_spec_id)) && showSpecSelectedInfo && (
                            <div className="bg-blue-50 border border-blue-200 rounded-md p-3 mb-3 transition-opacity duration-300">
                                <p className="text-xs sm:text-sm text-blue-800 break-words">
                                    ✓ {bulkMode ? `${data.pc_spec_ids.length} PC spec(s)` : 'PC spec'} selected
                                    {bulkMode && data.pc_spec_ids.length < parseInt(data.quantity) && (
                                        <span className="ml-2 text-amber-700 block sm:inline mt-1 sm:mt-0">
                                            (Note: {parseInt(data.quantity) - data.pc_spec_ids.length} station(s) will have no PC spec)
                                        </span>
                                    )}
                                </p>
                            </div>
                        )}
                        <p className="text-xs text-gray-500 mb-2 break-words">
                            💡 {bulkMode
                                ? `Select up to ${data.quantity} PC specs (one per station). You can select fewer - remaining stations will have no PC spec.`
                                : 'Leave blank to create stations without PC specs (useful for reserving station numbers or "No PC" status)'}
                        </p>
                        <PcSpecTable
                            pcSpecs={pcSpecs}
                            selectedId={bulkMode ? undefined : data.pc_spec_id}
                            selectedIds={bulkMode ? data.pc_spec_ids : undefined}
                            multiSelect={bulkMode}
                            maxSelections={bulkMode ? parseInt(data.quantity) || 1 : undefined}
                            onSelect={(id) => {
                                if (bulkMode) {
                                    const currentIds = data.pc_spec_ids;
                                    const maxSelections = parseInt(data.quantity) || 1;
                                    if (currentIds.includes(id)) {
                                        // Deselect
                                        setData("pc_spec_ids", currentIds.filter(i => i !== id));
                                    } else if (currentIds.length < maxSelections) {
                                        // Select (if under limit)
                                        setData("pc_spec_ids", [...currentIds, id]);
                                    }
                                } else {
                                    setData("pc_spec_id", id);
                                }
                            }}
                            usedPcSpecIds={usedPcSpecIds}
                        />
                        {errors.pc_spec_id && <p className="text-red-600 text-sm mt-1">{errors.pc_spec_id}</p>}
                        {errors.pc_spec_ids && <p className="text-red-600 text-sm mt-1">{errors.pc_spec_ids}</p>}
                    </div>

                    {/* Monitor Selection Section */}
                    <div>
                        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-2 gap-2">
                            <label className="block font-medium text-sm sm:text-base">
                                Select Monitor(s) (Optional)
                            </label>
                            {data.monitor_ids.length > 0 && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setData("monitor_ids", [])}
                                    className="text-xs text-red-600 hover:text-red-700 hover:bg-red-50 w-full sm:w-auto"
                                >
                                    ✕ Clear Monitor Selection
                                </Button>
                            )}
                        </div>
                        <p className="text-xs text-gray-500 mb-2 break-words">
                            💡 Monitor Type: <strong>{data.monitor_type === 'single' ? 'Single' : 'Dual'}</strong>
                            {data.monitor_type === 'dual' ? ' - You can select up to 2 monitors' : ' - Select 1 monitor'}
                        </p>

                        <MonitorSpecTable
                            monitorSpecs={monitorSpecs}
                            selectedMonitors={data.monitor_ids}
                            monitorType={data.monitor_type as 'single' | 'dual'}
                            onSelect={(monitor) => {
                                setData("monitor_ids", [...data.monitor_ids, { id: monitor.id, quantity: 1 }]);
                            }}
                            onQuantityChange={(monitorId, quantity) => {
                                setData("monitor_ids", data.monitor_ids.map(m =>
                                    m.id === monitorId ? { ...m, quantity } : m
                                ));
                            }}
                            onDeselect={(monitorId) => {
                                setData("monitor_ids", data.monitor_ids.filter(m => m.id !== monitorId));
                            }}
                        />
                        {errors.monitor_ids && <p className="text-red-600 text-sm mt-1">{errors.monitor_ids}</p>}
                    </div>

                    <div className="flex flex-col sm:flex-row gap-2 mt-4 mb-4">
                        <Button type="submit" disabled={processing} className="w-full sm:w-auto">
                            {processing ? "Saving..." : bulkMode ? `Create ${data.quantity} Station(s)` : "Save"}
                        </Button>
                        <Button variant="outline" type="button" onClick={() => router.get("/stations")} className="w-full sm:w-auto">Cancel</Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
