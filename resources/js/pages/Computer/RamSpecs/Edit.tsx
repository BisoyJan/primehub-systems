import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, CircleAlert } from 'lucide-react';

import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { useFlashMessage, usePageMeta, usePageLoading } from '@/hooks';
import {
    index as ramSpecsIndexRoute,
    edit as ramSpecsEditRoute,
    update as ramSpecsUpdateRoute,
} from '@/routes/ramspecs';

interface RamSpec {
    id: number;
    manufacturer: string;
    model: string;
    capacity_gb: number;
    type: string;
    speed: number;
    form_factor: string;
    voltage: number;
}

interface Props {
    ramspec: RamSpec;
}

export default function Edit({ ramspec }: Props) {
    useFlashMessage();

    const { title, breadcrumbs } = usePageMeta({
        title: 'Edit RAM Specification',
        breadcrumbs: [
            { title: 'RAM Specifications', href: ramSpecsIndexRoute().url },
            { title: 'Edit', href: ramSpecsEditRoute({ ramspec: ramspec.id }).url },
        ],
    });

    const { data, setData, put, errors, processing } = useForm({
        manufacturer: ramspec.manufacturer,
        model: ramspec.model,
        capacity_gb: ramspec.capacity_gb,
        type: ramspec.type,
        speed: ramspec.speed,
        form_factor: ramspec.form_factor,
        voltage: ramspec.voltage,
    });

    const isPageLoading = usePageLoading();

    const handleUpdate = (event: React.FormEvent) => {
        event.preventDefault();
        put(ramSpecsUpdateRoute({ ramspec: ramspec.id }).url);
    };

    const hasErrors = Object.keys(errors).length > 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="relative mx-auto flex w-full max-w-4xl flex-col gap-4 rounded-xl p-3 md:p-6">
                <LoadingOverlay
                    isLoading={isPageLoading || processing}
                    message={processing ? 'Updating RAM spec...' : undefined}
                />

                <PageHeader
                    title="Edit RAM Specification"
                    description={`${ramspec.manufacturer} ${ramspec.model}`}
                    actions={(
                        <Link href={ramSpecsIndexRoute().url}>
                            <Button variant="outline">
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back to list
                            </Button>
                        </Link>
                    )}
                />

                <form onSubmit={handleUpdate} className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    {hasErrors && (
                        <div className="md:col-span-2">
                            <Alert>
                                <CircleAlert className="h-4 w-4" />
                                <AlertTitle>Error</AlertTitle>
                                <AlertDescription>
                                    <ul className="list-disc pl-5 text-sm">
                                        {Object.entries(errors).map(([field, message]) => (
                                            <li key={field}>{message as string}</li>
                                        ))}
                                    </ul>
                                </AlertDescription>
                            </Alert>
                        </div>
                    )}

                    <div>
                        <Label htmlFor="manufacturer">Manufacturer</Label>
                        <Input
                            id="manufacturer"
                            name="manufacturer"
                            placeholder="e.g. Corsair"
                            value={data.manufacturer}
                            onChange={(e) => setData('manufacturer', e.target.value)}
                        />
                        {errors.manufacturer && (
                            <p className="mt-1 text-sm text-red-600">{errors.manufacturer}</p>
                        )}
                    </div>

                    <div>
                        <Label htmlFor="model">Model</Label>
                        <Input
                            id="model"
                            name="model"
                            placeholder="e.g. Vengeance LPX"
                            value={data.model}
                            onChange={(e) => setData('model', e.target.value)}
                        />
                        {errors.model && <p className="mt-1 text-sm text-red-600">{errors.model}</p>}
                    </div>

                    <div>
                        <Label htmlFor="capacity_gb">Capacity (GB)</Label>
                        <Select
                            value={data.capacity_gb ? String(data.capacity_gb) : ''}
                            onValueChange={(value) => setData('capacity_gb', Number(value))}
                        >
                            <SelectTrigger id="capacity_gb" name="capacity_gb">
                                <SelectValue placeholder="Select capacity" />
                            </SelectTrigger>
                            <SelectContent>
                                {[4, 8, 16, 32].map((size) => (
                                    <SelectItem key={size} value={String(size)}>
                                        {size} (GB)
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {errors.capacity_gb && (
                            <p className="mt-1 text-sm text-red-600">{errors.capacity_gb}</p>
                        )}
                    </div>

                    <div>
                        <Label htmlFor="type">Type</Label>
                        <Select
                            value={data.type}
                            onValueChange={(value) => setData('type', value)}
                        >
                            <SelectTrigger id="type" name="type">
                                <SelectValue placeholder="e.g. DDR4" />
                            </SelectTrigger>
                            <SelectContent>
                                {['DDR3', 'DDR4', 'DDR5'].map((option) => (
                                    <SelectItem key={option} value={option}>
                                        {option}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {errors.type && <p className="mt-1 text-sm text-red-600">{errors.type}</p>}
                    </div>

                    <div>
                        <Label htmlFor="speed">Speed (MHz)</Label>
                        <Input
                            id="speed"
                            name="speed"
                            type="number"
                            min={1000}
                            placeholder="e.g. 3200"
                            value={data.speed}
                            onChange={(e) => setData('speed', Number(e.target.value))}
                        />
                        {errors.speed && <p className="mt-1 text-sm text-red-600">{errors.speed}</p>}
                    </div>

                    <div>
                        <Label htmlFor="form_factor">Form Factor</Label>
                        <Select
                            value={data.form_factor}
                            onValueChange={(value) => setData('form_factor', value)}
                        >
                            <SelectTrigger id="form_factor" name="form_factor">
                                <SelectValue placeholder="e.g. SO-DIMM" />
                            </SelectTrigger>
                            <SelectContent>
                                {['SO-DIMM', 'DIMM'].map((formFactor) => (
                                    <SelectItem key={formFactor} value={formFactor}>
                                        {formFactor}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {errors.form_factor && (
                            <p className="mt-1 text-sm text-red-600">{errors.form_factor}</p>
                        )}
                    </div>

                    <div>
                        <Label htmlFor="voltage">Voltage (V)</Label>
                        <Input
                            id="voltage"
                            name="voltage"
                            type="number"
                            step="0.01"
                            min={0}
                            placeholder="e.g. 1.35"
                            value={data.voltage}
                            onChange={(e) => setData('voltage', Number(e.target.value))}
                        />
                        {errors.voltage && <p className="mt-1 text-sm text-red-600">{errors.voltage}</p>}
                    </div>

                    <div className="md:col-span-2 flex justify-end">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Update RAM Spec'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
