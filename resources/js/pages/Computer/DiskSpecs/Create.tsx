import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';

import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { FormField } from '@/components/FormField';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ArrowLeft } from 'lucide-react';

import { useFlashMessage, usePageMeta, usePageLoading } from '@/hooks';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { store, create, index } from '@/routes/diskspecs';

export default function Create() {
    useFlashMessage(); // Automatically handles flash messages

    const { title, breadcrumbs } = usePageMeta({
        title: 'Create Disk Specification',
        breadcrumbs: [
            { title: 'Disk Specifications', href: index().url },
            { title: 'Create', href: create().url },
        ],
    });

    const isPageLoading = usePageLoading();

    const { data, setData, post, errors, processing } = useForm({
        manufacturer: '',
        model: '',
        capacity_gb: '' as number | '',
        stock_quantity: 0,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(store().url);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="relative mx-auto flex h-full w-full max-w-5xl flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3 md:p-6">
                <LoadingOverlay
                    isLoading={isPageLoading || processing}
                    message={processing ? 'Saving disk specification...' : undefined}
                />

                <PageHeader
                    title={title}
                    description="Record disk models with performance metrics and available stock."
                    actions={(
                        <Link href={index().url}>
                            <Button variant="outline">
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back to list
                            </Button>
                        </Link>
                    )}
                />

                <form onSubmit={handleSubmit} className="space-y-8">
                    {/* Core Info */}
                    <section>
                        <h2 className="text-lg font-semibold mb-2">Core Info</h2>
                        <div className="grid grid-cols-2 gap-6">
                            <FormField label="Manufacturer" htmlFor="manufacturer" error={errors.manufacturer}>
                                <Input
                                    id="manufacturer"
                                    name="manufacturer"
                                    placeholder="e.g. Samsung"
                                    value={data.manufacturer}
                                    onChange={(e) => setData("manufacturer", e.target.value)}
                                />
                            </FormField>
                            <FormField label="Model Number" htmlFor="model" error={errors.model}>
                                <Input
                                    id="model"
                                    name="model"
                                    placeholder="e.g. 980 Pro"
                                    value={data.model}
                                    onChange={(e) => setData("model", e.target.value)}
                                />
                            </FormField>
                        </div>
                    </section>

                    {/* Capacity */}
                    <section>
                        <h2 className="text-lg font-semibold mb-2">Capacity</h2>
                        <div className="grid grid-cols-2 gap-6">
                            <FormField label="Capacity (GB)" htmlFor="capacity_gb" error={errors.capacity_gb}>
                                <Select
                                    value={data.capacity_gb ? String(data.capacity_gb) : ""}
                                    onValueChange={(val) => setData("capacity_gb", Number(val))}
                                >
                                    <SelectTrigger id="capacity_gb" name="capacity_gb">
                                        <SelectValue placeholder="Select capacity" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {[128, 256, 512, 1024, 2048, 4096].map((size) => (
                                            <SelectItem key={size} value={String(size)}>
                                                {size} GB
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </FormField>
                        </div>
                    </section>

                    {/* Stock Quantity */}
                    <section>
                        <h2 className="text-lg font-semibold mb-2">Stock Information</h2>
                        <div className="grid grid-cols-2 gap-6">
                            <FormField label="Initial Stock Quantity" htmlFor="stock_quantity" error={errors.stock_quantity}>
                                <Input
                                    id="stock_quantity"
                                    name="stock_quantity"
                                    type="number"
                                    min={0}
                                    placeholder="e.g. 100"
                                    value={data.stock_quantity}
                                    onChange={(e) => setData("stock_quantity", Number(e.target.value))}
                                />
                            </FormField>
                        </div>
                    </section>

                    {/* Submit */}
                    <div className="flex justify-end">
                        <Button type="submit">Add Disk Spec</Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
