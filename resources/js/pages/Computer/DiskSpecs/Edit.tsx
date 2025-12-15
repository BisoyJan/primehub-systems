import React from 'react';
import { Head, Link, useForm } from '@inertiajs/react';

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
import { ArrowLeft } from 'lucide-react';

import { useFlashMessage, usePageMeta, usePageLoading } from '@/hooks';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { update, index, edit } from '@/routes/diskspecs';

interface DiskSpec {
    id: number;
    manufacturer: string;
    model: string;
    capacity_gb: number;
}

interface Props {
    diskspec: DiskSpec;
}

export default function Edit({ diskspec }: Props) {
    useFlashMessage(); // Automatically handles flash messages

    const specLabel = `${diskspec.manufacturer} ${diskspec.model}`.trim() || `Disk Spec #${diskspec.id}`;

    const { title, breadcrumbs } = usePageMeta({
        title: `Edit ${specLabel}`,
        breadcrumbs: [
            { title: 'Disk Specifications', href: index().url },
            { title: specLabel, href: edit({ diskspec: diskspec.id }).url },
        ],
    });

    const isPageLoading = usePageLoading();

    const { data, setData, put, errors, processing } = useForm({
        manufacturer: diskspec.manufacturer,
        model: diskspec.model,
        capacity_gb: diskspec.capacity_gb,
    });

    const handleUpdate = (e: React.FormEvent) => {
        e.preventDefault();
        put(update({ diskspec: diskspec.id }).url);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="relative mx-auto flex h-full w-full max-w-5xl flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3 md:p-6">
                <LoadingOverlay
                    isLoading={isPageLoading || processing}
                    message={processing ? 'Updating disk specification...' : undefined}
                />

                <PageHeader
                    title={title}
                    description="Adjust disk specification details and performance metrics."
                    actions={(
                        <Link href={index().url}>
                            <Button variant="outline">
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back to list
                            </Button>
                        </Link>
                    )}
                />

                <form onSubmit={handleUpdate} className="space-y-8">
                    {/* Core Info */}
                    <section>
                        <h2 className="text-lg font-semibold mb-2">Core Info</h2>
                        <div className="grid grid-cols-2 gap-6">
                            <div>
                                <Label htmlFor="manufacturer">Manufacturer</Label>
                                <Input
                                    id="manufacturer"
                                    name="manufacturer"
                                    placeholder="e.g. Samsung"
                                    value={data.manufacturer}
                                    onChange={(e) => setData("manufacturer", e.target.value)}
                                />
                                {errors.manufacturer && <p className="text-red-600">{errors.manufacturer}</p>}
                            </div>
                            <div>
                                <Label htmlFor="model">Model Number</Label>
                                <Input
                                    id="model"
                                    name="model"
                                    placeholder="e.g. 980 Pro"
                                    value={data.model}
                                    onChange={(e) => setData("model", e.target.value)}
                                />
                                {errors.model && <p className="text-red-600">{errors.model}</p>}
                            </div>
                        </div>
                    </section>

                    {/* Capacity */}
                    <section>
                        <h2 className="text-lg font-semibold mb-2">Capacity</h2>
                        <div className="grid grid-cols-2 gap-6">
                            <div>
                                <Label htmlFor="capacity_gb">Capacity (GB)</Label>
                                <Select
                                    value={String(data.capacity_gb)}
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
                                {errors.capacity_gb && <p className="text-red-600">{errors.capacity_gb}</p>}
                            </div>
                        </div>
                    </section>

                    {/* Submit */}
                    <div className="flex justify-end">
                        <Button type="submit">Update Disk Spec</Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
