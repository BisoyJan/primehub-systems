import { LoadingOverlay } from '@/components/LoadingOverlay';
import { PageHeader } from '@/components/PageHeader';
import PcSpecTable from '@/components/PcSpecTable';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
    edit as stationsEditRoute,
    index as stationsIndexRoute,
    update as stationsUpdateRoute,
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
    ram: string;
    ram_gb?: number;
    disk: string;
    disk_gb?: number;
    processor: string;
    [key: string]: unknown;
}
interface StationEditProps {
    station: {
        id: number;
        site_id: number;
        station_number: string;
        campaign_id: number | null;
        status: string | null;
        monitor_type: string;
        pc_spec_id: number;
    };
    sites: Site[];
    campaigns: Campaign[];
    pcSpecs: PcSpec[];
    usedPcSpecIds: number[];
    flash?: { message?: string; type?: string };
    [key: string]: unknown;
}

export default function StationEdit({
    station,
    sites,
    campaigns,
    pcSpecs,
    usedPcSpecIds,
}: StationEditProps) {
    const [showNoSpecWarning, setShowNoSpecWarning] = useState(false);
    const [showSpecSelectedInfo, setShowSpecSelectedInfo] = useState(false);
    const { returnTo, backUrl } = useReturnTo(stationsIndexRoute().url);

    const { data, setData, processing, errors } = useForm({
        site_id: String(station.site_id),
        station_number: station.station_number,
        campaign_id: station.campaign_id ? String(station.campaign_id) : '',
        status: station.status || '',
        monitor_type: station.monitor_type || 'single',
        pc_spec_id: String(station.pc_spec_id),
        _return_to: returnTo ?? '',
    });

    const { title, breadcrumbs } = usePageMeta({
        title: 'Edit Station',
        breadcrumbs: [
            { title: 'Stations', href: stationsIndexRoute().url },
            { title: 'Edit', href: stationsEditRoute(station.id).url },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    // Auto-hide messages after 6 seconds when PC spec selection changes
    useEffect(() => {
        const hasSpec = !!data.pc_spec_id;
        setShowNoSpecWarning(!hasSpec);
        setShowSpecSelectedInfo(hasSpec);

        const timer = setTimeout(() => {
            setShowNoSpecWarning(false);
            setShowSpecSelectedInfo(false);
        }, 4000);

        return () => clearTimeout(timer);
    }, [data.pc_spec_id]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        // Use router.put with transformed data to properly handle null values
        router.put(
            stationsUpdateRoute(station.id).url,
            {
                ...data,
                pc_spec_id: data.pc_spec_id || null,
            },
            {
                onSuccess: () => {
                    toast.success('Station updated');
                },
                onError: (errors) => {
                    const firstError = Object.values(errors)[0] as string;
                    toast.error(firstError || 'Validation error');
                },
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="relative mx-auto mt-4 max-w-7xl p-3 md:p-6">
                <LoadingOverlay
                    isLoading={isPageLoading || processing}
                    message={processing ? 'Saving station...' : undefined}
                />
                <PageHeader
                    title="Edit Station"
                    description="Update assignment details, PC specs, and monitor configuration"
                />
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label className="mb-1 block text-sm font-medium">
                                Site
                            </label>
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
                        <div>
                            <label className="mb-1 block text-sm font-medium">
                                Station Number
                            </label>
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
                        <div>
                            <label className="mb-1 block text-sm font-medium">
                                Campaign (Optional)
                            </label>
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
                            <label className="mb-1 block text-sm font-medium">
                                Status (Optional)
                            </label>
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
                            <label className="mb-1 block text-sm font-medium">
                                Monitor
                            </label>
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
                    <div>
                        <div className="mb-2 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <label className="block text-sm font-medium sm:text-base">
                                Select PC Spec (Optional)
                            </label>
                            {data.pc_spec_id && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setData('pc_spec_id', '')}
                                    className="w-full text-xs text-red-600 hover:bg-red-50 hover:text-red-700 sm:w-auto"
                                >
                                    ✕ Remove PC Spec
                                </Button>
                            )}
                        </div>
                        {!data.pc_spec_id && showNoSpecWarning && (
                            <div className="mb-3 rounded-md border border-yellow-200 bg-yellow-50 p-3 transition-opacity duration-300">
                                <p className="text-xs break-words text-yellow-800 sm:text-sm">
                                    ⚠️ No PC spec selected - Station will be
                                    saved without a PC specification
                                </p>
                            </div>
                        )}
                        {data.pc_spec_id && showSpecSelectedInfo && (
                            <div className="mb-3 rounded-md border border-blue-200 bg-blue-50 p-3 transition-opacity duration-300">
                                <p className="text-xs break-words text-blue-800 sm:text-sm">
                                    ✓ PC spec selected - Click "Remove PC Spec"
                                    above to deselect
                                </p>
                            </div>
                        )}
                        <p className="mb-2 text-xs break-words text-gray-500">
                            💡 You can deselect the PC spec by clicking the
                            "Remove PC Spec" button above
                        </p>
                        <PcSpecTable
                            pcSpecs={pcSpecs}
                            selectedId={data.pc_spec_id}
                            onSelect={(id) => setData('pc_spec_id', id)}
                            usedPcSpecIds={usedPcSpecIds?.filter(
                                (id) => id !== station.pc_spec_id,
                            )}
                        />
                        {errors.pc_spec_id && (
                            <p className="mt-1 text-sm text-red-600">
                                {errors.pc_spec_id}
                            </p>
                        )}
                    </div>

                    <div className="mt-4 flex flex-col gap-2 sm:flex-row">
                        <Button
                            type="submit"
                            disabled={processing}
                            className="w-full sm:w-auto"
                        >
                            {processing ? 'Saving...' : 'Save'}
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
