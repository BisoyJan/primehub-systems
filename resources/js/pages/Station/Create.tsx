import { LoadingOverlay } from '@/components/LoadingOverlay';
import { PageHeader } from '@/components/PageHeader';
import PcSpecTable from '@/components/PcSpecTable';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    useFlashMessage,
    usePageLoading,
    usePageMeta,
    useReturnTo,
} from '@/hooks';
import AppLayout from '@/layouts/app-layout';
import {
    bulk as stationsBulkRoute,
    create as stationsCreateRoute,
    index as stationsIndexRoute,
    store as stationsStoreRoute,
} from '@/routes/stations';
import { Head, router, useForm } from '@inertiajs/react';
import React, { useEffect, useState } from 'react';
import { toast } from 'sonner';

interface Site {
    id: number;
    name: string;
}
interface Campaign {
    id: number;
    name: string;
}
interface PcSpec {
    id: number;
    pc_number?: string;
    model: string;
    ram?: string;
    ram_gb?: number;
    disk?: string;
    disk_gb?: number;
    processor?: string;
    [key: string]: unknown;
}

interface StationCreateProps {
    sites: Site[];
    campaigns: Campaign[];
    pcSpecs: PcSpec[];
    usedPcSpecIds: number[];
    flash?: { message?: string; type?: string };
}

export default function StationCreate({
    sites,
    campaigns,
    pcSpecs,
    usedPcSpecIds,
}: StationCreateProps) {
    const [bulkMode, setBulkMode] = useState(false);
    const [showNoSpecWarning, setShowNoSpecWarning] = useState(false);
    const [showSpecSelectedInfo, setShowSpecSelectedInfo] = useState(false);
    const { returnTo, backUrl } = useReturnTo(stationsIndexRoute().url);

    const { data, setData, post, processing, errors } = useForm({
        site_id: '',
        station_number: '',
        campaign_id: '',
        status: '',
        monitor_type: 'single',
        pc_spec_id: '',
        pc_spec_ids: [] as string[], // For bulk mode
        quantity: '1',
        starting_number: '',
        increment_type: 'number', // "number", "letter", or "both"
        _return_to: returnTo ?? '',
    });

    const { title, breadcrumbs } = usePageMeta({
        title: 'Create Station',
        breadcrumbs: [
            { title: 'Stations', href: stationsIndexRoute().url },
            { title: 'Create', href: stationsCreateRoute().url },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

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

        const endpoint = bulkMode
            ? stationsBulkRoute().url
            : stationsStoreRoute().url;

        post(endpoint, {
            onSuccess: () => {
                const message = bulkMode
                    ? `Successfully created ${data.quantity} station(s)`
                    : 'Station created';
                toast.success(message);
            },
            onError: (errors) => {
                const firstError = Object.values(errors)[0] as string;
                toast.error(firstError || 'Validation error');
            },
        });
    };

    // Generate preview of station numbers
    const generatePreview = () => {
        if (!data.starting_number || !data.quantity) return '';

        const qty = parseInt(data.quantity);
        if (qty < 1) return '';

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

                if (data.increment_type === 'number') {
                    newNum = numPart + i;
                } else if (data.increment_type === 'letter' && letterPart) {
                    const charCode = letterPart.charCodeAt(0);
                    const isUpper = letterPart === letterPart.toUpperCase();
                    const baseCode = isUpper ? 65 : 97; // 'A' or 'a'
                    const newCharCode =
                        baseCode + ((charCode - baseCode + i) % 26);
                    newLetter = String.fromCharCode(newCharCode);
                } else if (data.increment_type === 'both') {
                    newNum = numPart + i;
                    if (letterPart) {
                        const charCode = letterPart.charCodeAt(0);
                        const isUpper = letterPart === letterPart.toUpperCase();
                        const baseCode = isUpper ? 65 : 97;
                        const newCharCode =
                            baseCode + ((charCode - baseCode + i) % 26);
                        newLetter = String.fromCharCode(newCharCode);
                    }
                }

                const paddedNum = String(newNum).padStart(numLength, '0');
                previews.push(`${prefix}${paddedNum}${newLetter}${suffix}`);
            }
        }

        return previews.join(', ') + (qty > 3 ? ', ...' : '');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="relative mx-auto mt-4 max-w-7xl p-3 md:p-6">
                <LoadingOverlay
                    isLoading={isPageLoading || processing}
                    message={processing ? 'Saving stations...' : undefined}
                />

                <PageHeader
                    title="Add Station"
                    description="Create single or bulk stations and optionally link PC or monitor specs"
                >
                    <div className="flex items-center space-x-2">
                        <Checkbox
                            id="bulk-mode"
                            checked={bulkMode}
                            onCheckedChange={(checked) =>
                                setBulkMode(checked as boolean)
                            }
                        />
                        <Label
                            htmlFor="bulk-mode"
                            className="cursor-pointer text-sm sm:text-base"
                        >
                            Create Multiple Stations
                        </Label>
                    </div>
                </PageHeader>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <Label>Site</Label>
                            <Select
                                value={data.site_id}
                                onValueChange={(val) => setData('site_id', val)}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Select Site" />
                                </SelectTrigger>
                                <SelectContent>
                                    {sites.map((s: Site) => (
                                        <SelectItem
                                            key={s.id}
                                            value={String(s.id)}
                                        >
                                            {s.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {errors.site_id && (
                                <p className="mt-1 text-sm text-red-600">
                                    {errors.site_id}
                                </p>
                            )}
                        </div>

                        {!bulkMode ? (
                            <div>
                                <Label>Station Number</Label>
                                <Input
                                    value={data.station_number}
                                    onChange={(e) =>
                                        setData(
                                            'station_number',
                                            e.target.value.toUpperCase(),
                                        )
                                    }
                                    placeholder="Station Number"
                                    required
                                />
                                {errors.station_number && (
                                    <p className="mt-1 text-sm text-red-600">
                                        {errors.station_number}
                                    </p>
                                )}
                            </div>
                        ) : (
                            <>
                                <div>
                                    <Label>Starting Station Number</Label>
                                    <Input
                                        value={data.starting_number}
                                        onChange={(e) =>
                                            setData(
                                                'starting_number',
                                                e.target.value.toUpperCase(),
                                            )
                                        }
                                        placeholder="e.g., PC-1A, ST-001, PC-10B"
                                        required
                                    />
                                    <p className="mt-1 text-xs text-gray-500">
                                        💡 Supports:{' '}
                                        <code className="rounded bg-gray-100 px-1 text-xs">
                                            PC-1A
                                        </code>
                                        ,{' '}
                                        <code className="rounded bg-gray-100 px-1 text-xs">
                                            ST-001
                                        </code>
                                        ,{' '}
                                        <code className="rounded bg-gray-100 px-1 text-xs">
                                            WS-10B
                                        </code>
                                        , etc.
                                    </p>
                                    {errors.starting_number && (
                                        <p className="mt-1 text-sm text-red-600">
                                            {errors.starting_number}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label>Quantity</Label>
                                    <Input
                                        type="number"
                                        min="1"
                                        max="100"
                                        value={data.quantity}
                                        onChange={(e) =>
                                            setData('quantity', e.target.value)
                                        }
                                        placeholder="Number of stations to create"
                                        required
                                    />
                                    {errors.quantity && (
                                        <p className="mt-1 text-sm text-red-600">
                                            {errors.quantity}
                                        </p>
                                    )}
                                </div>
                                <div className="md:col-span-2">
                                    <Label>Increment Type</Label>
                                    <Select
                                        value={data.increment_type}
                                        onValueChange={(val) =>
                                            setData('increment_type', val)
                                        }
                                    >
                                        <SelectTrigger className="w-full">
                                            <SelectValue placeholder="Select increment type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="number">
                                                Number Only (PC-1A, PC-2A,
                                                PC-3A)
                                            </SelectItem>
                                            <SelectItem value="letter">
                                                Letter Only (PC-1A, PC-1B,
                                                PC-1C)
                                            </SelectItem>
                                            <SelectItem value="both">
                                                Both (PC-1A, PC-2B, PC-3C)
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <div className="mt-2 rounded-md border border-amber-200 bg-amber-50 p-3">
                                        <p className="mb-1 text-xs font-medium text-amber-900">
                                            📋 Increment Examples:
                                        </p>
                                        <ul className="space-y-0.5 text-xs text-amber-800">
                                            <li className="break-words">
                                                <strong>Number Only:</strong>{' '}
                                                Use when stations are in same
                                                row/section (PC-1A → PC-2A →
                                                PC-3A)
                                            </li>
                                            <li className="break-words">
                                                <strong>Letter Only:</strong>{' '}
                                                Use when stations share same
                                                number (PC-1A → PC-1B → PC-1C)
                                            </li>
                                            <li className="break-words">
                                                <strong>Both:</strong> Use for
                                                diagonal or combined patterns
                                                (PC-1A → PC-2B → PC-3C)
                                            </li>
                                        </ul>
                                    </div>
                                    {errors.increment_type && (
                                        <p className="mt-1 text-sm text-red-600">
                                            {errors.increment_type}
                                        </p>
                                    )}
                                </div>
                            </>
                        )}

                        <div>
                            <Label>Campaign (Optional)</Label>
                            <Select
                                value={data.campaign_id}
                                onValueChange={(val) =>
                                    setData(
                                        'campaign_id',
                                        val === '__none__' ? '' : val,
                                    )
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Select Campaign" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">
                                        None
                                    </SelectItem>
                                    {campaigns.map((c: Campaign) => (
                                        <SelectItem
                                            key={c.id}
                                            value={String(c.id)}
                                        >
                                            {c.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {errors.campaign_id && (
                                <p className="mt-1 text-sm text-red-600">
                                    {errors.campaign_id}
                                </p>
                            )}
                        </div>
                        <div>
                            <Label>Status (Optional)</Label>
                            <Select
                                value={data.status}
                                onValueChange={(val) =>
                                    setData(
                                        'status',
                                        val === '__none__' ? '' : val,
                                    )
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Select Status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">
                                        None
                                    </SelectItem>
                                    <SelectItem value="Admin">Admin</SelectItem>
                                    <SelectItem value="Occupied">
                                        Occupied
                                    </SelectItem>
                                    <SelectItem value="Vacant">
                                        Vacant
                                    </SelectItem>
                                    <SelectItem value="No PC">No PC</SelectItem>
                                </SelectContent>
                            </Select>
                            {errors.status && (
                                <p className="mt-1 text-sm text-red-600">
                                    {errors.status}
                                </p>
                            )}
                        </div>
                        <div>
                            <Label>Monitor</Label>
                            <Select
                                value={data.monitor_type}
                                onValueChange={(val) =>
                                    setData('monitor_type', val)
                                }
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Select Monitor" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="single">
                                        Single Monitor
                                    </SelectItem>
                                    <SelectItem value="dual">
                                        Dual Monitor
                                    </SelectItem>
                                    <SelectItem value="none">
                                        No Monitor
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            {errors.monitor_type && (
                                <p className="mt-1 text-sm text-red-600">
                                    {errors.monitor_type}
                                </p>
                            )}
                        </div>
                    </div>

                    {bulkMode && (
                        <div className="rounded border border-blue-200 bg-blue-50 p-3 sm:p-4">
                            <p className="text-xs break-words text-blue-800 sm:text-sm">
                                <strong>Preview:</strong> This will create{' '}
                                {data.quantity || 0} station(s) with numbers:
                                <span className="mt-1 ml-2 block font-mono sm:mt-0 sm:inline">
                                    {generatePreview() ||
                                        '[Enter starting number]'}
                                </span>
                            </p>
                        </div>
                    )}

                    <div>
                        <div className="mb-2 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <label className="block text-sm font-medium sm:text-base">
                                Select PC Spec{bulkMode ? 's' : ''} (Optional)
                                {bulkMode && (
                                    <span className="ml-2 text-sm text-gray-500">
                                        ({data.pc_spec_ids.length} selected)
                                    </span>
                                )}
                            </label>
                            {((!bulkMode && data.pc_spec_id) ||
                                (bulkMode && data.pc_spec_ids.length > 0)) && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        if (bulkMode) {
                                            setData('pc_spec_ids', []);
                                        } else {
                                            setData('pc_spec_id', '');
                                        }
                                    }}
                                    className="w-full text-xs text-red-600 hover:bg-red-50 hover:text-red-700 sm:w-auto"
                                >
                                    ✕ Clear Selection{bulkMode ? 's' : ''}
                                </Button>
                            )}
                        </div>
                        {((bulkMode && data.pc_spec_ids.length === 0) ||
                            (!bulkMode && !data.pc_spec_id)) &&
                            showNoSpecWarning && (
                                <div className="mb-3 rounded-md border border-yellow-200 bg-yellow-50 p-3 transition-opacity duration-300">
                                    <p className="text-xs break-words text-yellow-800 sm:text-sm">
                                        ⚠️ No PC spec{bulkMode ? 's' : ''}{' '}
                                        selected - Station{bulkMode ? 's' : ''}{' '}
                                        will be saved without PC specifications
                                    </p>
                                </div>
                            )}
                        {((bulkMode && data.pc_spec_ids.length > 0) ||
                            (!bulkMode && data.pc_spec_id)) &&
                            showSpecSelectedInfo && (
                                <div className="mb-3 rounded-md border border-blue-200 bg-blue-50 p-3 transition-opacity duration-300">
                                    <p className="text-xs break-words text-blue-800 sm:text-sm">
                                        ✓{' '}
                                        {bulkMode
                                            ? `${data.pc_spec_ids.length} PC spec(s)`
                                            : 'PC spec'}{' '}
                                        selected
                                        {bulkMode &&
                                            data.pc_spec_ids.length <
                                                parseInt(data.quantity) && (
                                                <span className="mt-1 ml-2 block text-amber-700 sm:mt-0 sm:inline">
                                                    (Note:{' '}
                                                    {parseInt(data.quantity) -
                                                        data.pc_spec_ids
                                                            .length}{' '}
                                                    station(s) will have no PC
                                                    spec)
                                                </span>
                                            )}
                                    </p>
                                </div>
                            )}
                        <p className="mb-2 text-xs break-words text-gray-500">
                            💡{' '}
                            {bulkMode
                                ? `Select up to ${data.quantity} PC specs (one per station). You can select fewer - remaining stations will have no PC spec.`
                                : 'Leave blank to create stations without PC specs (useful for reserving station numbers or "No PC" status)'}
                        </p>
                        <PcSpecTable
                            pcSpecs={pcSpecs}
                            selectedId={bulkMode ? undefined : data.pc_spec_id}
                            selectedIds={
                                bulkMode ? data.pc_spec_ids : undefined
                            }
                            multiSelect={bulkMode}
                            maxSelections={
                                bulkMode
                                    ? parseInt(data.quantity) || 1
                                    : undefined
                            }
                            onSelect={(id) => {
                                if (bulkMode) {
                                    const currentIds = data.pc_spec_ids;
                                    const maxSelections =
                                        parseInt(data.quantity) || 1;
                                    if (currentIds.includes(id)) {
                                        // Deselect
                                        setData(
                                            'pc_spec_ids',
                                            currentIds.filter((i) => i !== id),
                                        );
                                    } else if (
                                        currentIds.length < maxSelections
                                    ) {
                                        // Select (if under limit)
                                        setData('pc_spec_ids', [
                                            ...currentIds,
                                            id,
                                        ]);
                                    }
                                } else {
                                    setData('pc_spec_id', id);
                                }
                            }}
                            usedPcSpecIds={usedPcSpecIds}
                        />
                        {errors.pc_spec_id && (
                            <p className="mt-1 text-sm text-red-600">
                                {errors.pc_spec_id}
                            </p>
                        )}
                        {errors.pc_spec_ids && (
                            <p className="mt-1 text-sm text-red-600">
                                {errors.pc_spec_ids}
                            </p>
                        )}
                    </div>

                    <div className="mt-4 mb-4 flex flex-col gap-2 sm:flex-row">
                        <Button
                            type="submit"
                            disabled={processing}
                            className="w-full sm:w-auto"
                        >
                            {processing
                                ? 'Saving...'
                                : bulkMode
                                  ? `Create ${data.quantity} Station(s)`
                                  : 'Save'}
                        </Button>
                        <Button
                            variant="outline"
                            type="button"
                            onClick={() => router.visit(backUrl)}
                            className="w-full sm:w-auto"
                        >
                            Cancel
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
