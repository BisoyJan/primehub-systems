import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';

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
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { usePageMeta, useFlashMessage, usePageLoading } from '@/hooks';
import {
    store as ramSpecsStoreRoute,
    create as ramSpecsCreateRoute,
    index as ramSpecsIndexRoute,
} from '@/routes/ramspecs';

export default function Create() {
    useFlashMessage(); // Automatically handles flash messages

    const { title, breadcrumbs } = usePageMeta({
        title: 'Create RAM Specification',
        breadcrumbs: [
            { title: 'RAM Specifications', href: ramSpecsIndexRoute().url },
            { title: 'Create', href: ramSpecsCreateRoute().url },
        ],
    });

    const { data, setData, post, errors, processing } = useForm({
        manufacturer: '',
        model: '',
        capacity_gb: '' as number | '',
        type: '',
        speed: '',
        stock_quantity: 0,
    });

    const isPageLoading = usePageLoading();

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(ramSpecsStoreRoute().url);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="relative mx-auto flex w-full max-w-4xl flex-col gap-4 rounded-xl p-3 md:p-6">
                <LoadingOverlay
                    isLoading={isPageLoading || processing}
                    message={processing ? 'Saving RAM spec...' : undefined}
                />

                <PageHeader
                    title="Create RAM Specification"
                    description="Add RAM specs with key details and initial stock quantity"
                    actions={(
                        <Link href={ramSpecsIndexRoute().url}>
                            <Button variant="outline">
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back to list
                            </Button>
                        </Link>
                    )}
                />

                <form onSubmit={handleSubmit} className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <Label htmlFor="manufacturer">Manufacturer</Label>
                        <Input
                            id="manufacturer"
                            name="manufacturer"
                            placeholder="e.g. Corsair"
                            value={data.manufacturer}
                            onChange={(e) => setData('manufacturer', e.target.value)}
                        />
                        {errors.manufacturer && <p className="text-red-600 text-sm mt-1">{errors.manufacturer}</p>}
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
                        {errors.model && <p className="text-red-600 text-sm mt-1">{errors.model}</p>}
                    </div>

                    <div>
                        <Label htmlFor="capacity_gb">Capacity (GB)</Label>
                        <Select
                            value={data.capacity_gb ? String(data.capacity_gb) : ''}
                            onValueChange={(val) => setData('capacity_gb', Number(val))}
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
                        {errors.capacity_gb && <p className="text-red-600 text-sm mt-1">{errors.capacity_gb}</p>}
                    </div>
                    <div>
                        <Label htmlFor="type">Type</Label>
                        <Select
                            value={data.type}
                            onValueChange={(val) => setData('type', val)}
                        >
                            <SelectTrigger id="type" name="type">
                                <SelectValue placeholder="e.g. DDR4" />
                            </SelectTrigger>
                            <SelectContent>
                                {['DDR3', 'DDR4', 'DDR5'].map((t) => (
                                    <SelectItem key={t} value={t}>
                                        {t}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {errors.type && <p className="text-red-600 text-sm mt-1">{errors.type}</p>}
                    </div>

                    <div>
                        <Label htmlFor="speed">Speed (MHz)</Label>
                        <Input
                            id="speed"
                            name="speed"
                            type="number"
                            min={1}
                            placeholder="e.g. 3200"
                            value={data.speed}
                            onChange={(e) => setData('speed', e.target.value)}
                        />
                        {errors.speed && <p className="text-red-600 text-sm mt-1">{errors.speed}</p>}
                    </div>

                    <div>
                        <Label htmlFor="stock_quantity">Initial Stock Quantity</Label>
                        <Input
                            type="number"
                            id="stock_quantity"
                            name="stock_quantity"
                            value={data.stock_quantity || 0}
                            min={0}
                            onChange={(e) => setData('stock_quantity', Number(e.target.value))}
                        />
                        {errors.stock_quantity && (
                            <p className="text-red-600 text-sm mt-1">{errors.stock_quantity}</p>
                        )}
                    </div>

                    <div className="md:col-span-2 flex justify-end">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Add RAM Spec'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
