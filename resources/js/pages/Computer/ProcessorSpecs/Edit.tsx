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
import { useFlashMessage, usePageMeta, usePageLoading } from '@/hooks';
import {
    update as processorSpecsUpdateRoute,
    index as processorSpecsIndexRoute,
    edit as processorSpecsEditRoute,
} from '@/routes/processorspecs';

interface ProcessorSpec {
    id: number;
    manufacturer: string;
    model: string;
    core_count: number;
    thread_count: number;
    base_clock_ghz: number;
    boost_clock_ghz: number;
}

interface Props {
    processorspec: ProcessorSpec;
}

export default function Edit({ processorspec }: Props) {
    useFlashMessage();

    const { title, breadcrumbs } = usePageMeta({
        title: 'Edit Processor Specification',
        breadcrumbs: [
            { title: 'Processor Specifications', href: processorSpecsIndexRoute().url },
            { title: 'Edit', href: processorSpecsEditRoute({ processorspec: processorspec.id }).url },
        ],
    });

    const { data, setData, put, errors, processing } = useForm({
        manufacturer: processorspec.manufacturer,
        model: processorspec.model,
        core_count: processorspec.core_count,
        thread_count: processorspec.thread_count,
        base_clock_ghz: processorspec.base_clock_ghz,
        boost_clock_ghz: processorspec.boost_clock_ghz,
    });

    const handleUpdate = (e: React.FormEvent) => {
        e.preventDefault();
        put(processorSpecsUpdateRoute({ processorspec: processorspec.id }).url);
    };

    const isPageLoading = usePageLoading();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="relative mx-auto flex w-full max-w-4xl flex-col gap-4 rounded-xl p-3 md:p-6">
                <LoadingOverlay
                    isLoading={isPageLoading || processing}
                    message={processing ? 'Updating processor spec...' : undefined}
                />

                <PageHeader
                    title="Edit Processor Specification"
                    description={`${processorspec.manufacturer} ${processorspec.model}`}
                    actions={(
                        <Link href={processorSpecsIndexRoute().url}>
                            <Button variant="outline">
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back to list
                            </Button>
                        </Link>
                    )}
                />

                <form onSubmit={handleUpdate} className="grid grid-cols-1 gap-4 md:grid-cols-2">


                    {/* Row 1: manufacturer & model */}
                    <div>
                        <Label htmlFor="manufacturer">Manufacturer</Label>
                        <Select
                            value={data.manufacturer}
                            onValueChange={(val) => setData('manufacturer', val)}
                        >
                            <SelectTrigger id="manufacturer" name="manufacturer">
                                <SelectValue placeholder="Select manufacturer" />
                            </SelectTrigger>
                            <SelectContent>
                                {['Intel', 'AMD'].map((b) => (
                                    <SelectItem key={b} value={b}>{b}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {errors.manufacturer && <p className="mt-1 text-sm text-red-600">{errors.manufacturer}</p>}
                    </div>
                    <div>
                        <Label htmlFor="model">Model</Label>
                        <Input
                            id="model"
                            name="model"
                            placeholder="e.g. Core i5-12400"
                            value={data.model}
                            onChange={(e) => setData('model', e.target.value)}
                        />
                        {errors.model && <p className="mt-1 text-sm text-red-600">{errors.model}</p>}
                    </div>

                    {/* Row 2: Cores */}
                    <div>
                        <Label htmlFor="core_count">Core Count</Label>
                        <Input
                            id="core_count"
                            name="core_count"
                            type="number"
                            min={1}
                            placeholder="e.g. 6"
                            value={data.core_count}
                            onChange={(e) => setData('core_count', Number(e.target.value))}
                        />
                        {errors.core_count && <p className="mt-1 text-sm text-red-600">{errors.core_count}</p>}
                    </div>

                    {/* Row 3: Threads & Base Clock */}
                    <div>
                        <Label htmlFor="thread_count">Thread Count</Label>
                        <Input
                            id="thread_count"
                            name="thread_count"
                            type="number"
                            min={1}
                            placeholder="e.g. 12"
                            value={data.thread_count}
                            onChange={(e) => setData('thread_count', Number(e.target.value))}
                        />
                        {errors.thread_count && <p className="mt-1 text-sm text-red-600">{errors.thread_count}</p>}
                    </div>
                    <div>
                        <Label htmlFor="base_clock_ghz">Base Clock (GHz)</Label>
                        <Input
                            id="base_clock_ghz"
                            name="base_clock_ghz"
                            type="number"
                            step="0.01"
                            min={0}
                            placeholder="e.g. 2.50"
                            value={data.base_clock_ghz}
                            onChange={(e) => setData('base_clock_ghz', Number(e.target.value))}
                        />
                        {errors.base_clock_ghz && <p className="mt-1 text-sm text-red-600">{errors.base_clock_ghz}</p>}
                    </div>

                    {/* Row 4: Boost Clock & Integrated Graphics */}
                    <div>
                        <Label htmlFor="boost_clock_ghz">Boost Clock (GHz)</Label>
                        <Input
                            id="boost_clock_ghz"
                            name="boost_clock_ghz"
                            type="number"
                            step="0.01"
                            min={0}
                            placeholder="e.g. 4.40"
                            value={data.boost_clock_ghz}
                            onChange={(e) => setData('boost_clock_ghz', Number(e.target.value))}
                        />
                        {errors.boost_clock_ghz && <p className="mt-1 text-sm text-red-600">{errors.boost_clock_ghz}</p>}
                    </div>

                    <div className="md:col-span-2 flex justify-end">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : 'Update Processor Spec'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
