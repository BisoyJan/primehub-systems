import { useEffect, useMemo } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { toast } from 'sonner';

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

import type { BreadcrumbItem } from '@/types';
import { update, index } from '@/routes/processorspecs';

const intelSockets = ['LGA1151', 'LGA1200', 'LGA1700'];
const amdSockets = ['AM3+', 'AM4', 'AM5', 'TR4', 'sTRX4'];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Edit a Processor Specification', href: index().url },
];

interface ProcessorSpec {
    id: number;
    brand: string;
    series: string;
    socket_type: string;
    core_count: number;
    thread_count: number;
    base_clock_ghz: number;
    boost_clock_ghz: number;
    integrated_graphics: string;
    tdp_watts: number;
}

interface Props {
    processorspec: ProcessorSpec;
}

export default function Edit({ processorspec }: Props) {
    const { flash } = usePage<{ flash?: { message?: string; type?: string } }>().props;

    const { data, setData, put, errors } = useForm({
        brand: processorspec.brand,
        series: processorspec.series,
        socket_type: processorspec.socket_type,
        core_count: processorspec.core_count,
        thread_count: processorspec.thread_count,
        base_clock_ghz: processorspec.base_clock_ghz,
        boost_clock_ghz: processorspec.boost_clock_ghz,
        integrated_graphics: processorspec.integrated_graphics,
        tdp_watts: processorspec.tdp_watts,
    });

    // dynamically choose sockets based on brand
    const availableSockets = useMemo(() => {
        if (data.brand === 'Intel') return intelSockets;
        if (data.brand === 'AMD') return amdSockets;
        return [...intelSockets, ...amdSockets];
    }, [data.brand]);

    useEffect(() => {
        if (!flash?.message) return;
        if (flash.type === 'error') {
            toast.error(flash.message);
        } else {
            toast.success(flash.message);
        }
    }, [flash?.message, flash?.type]);

    const handleUpdate = (e: React.FormEvent) => {
        e.preventDefault();
        put(update.url(processorspec.id));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Edit Processor Specification" />

            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3 w-8/12">
                <div className="flex justify-start">
                    <Link href={index.url()}>
                        <Button>
                            <ArrowLeft /> Return
                        </Button>
                    </Link>
                </div>

                <form onSubmit={handleUpdate} className="grid grid-cols-2 gap-4">


                    {/* Row 1: Brand & Series */}
                    <div>
                        <Label htmlFor="brand">Brand</Label>
                        <Select
                            value={data.brand}
                            onValueChange={(val) => {
                                setData('brand', val);
                                setData('socket_type', ''); // reset socket when brand changes
                            }}
                        >
                            <SelectTrigger id="brand" name="brand">
                                <SelectValue placeholder="Select brand" />
                            </SelectTrigger>
                            <SelectContent>
                                {['Intel', 'AMD'].map((b) => (
                                    <SelectItem key={b} value={b}>{b}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {errors.brand && <p className="text-red-600">{errors.brand}</p>}
                    </div>
                    <div>
                        <Label htmlFor="series">Series</Label>
                        <Input
                            id="series"
                            name="series"
                            placeholder="e.g. Core i5-12400"
                            value={data.series}
                            onChange={(e) => setData('series', e.target.value)}
                        />
                        {errors.series && <p className="text-red-600">{errors.series}</p>}
                    </div>

                    {/* Row 2: Socket & Cores */}
                    <div>
                        <Label htmlFor="socket_type">Socket Type</Label>
                        <Select
                            value={data.socket_type}
                            onValueChange={(val) => setData('socket_type', val)}
                        >
                            <SelectTrigger id="socket_type" name="socket_type">
                                <SelectValue placeholder="Select socket" />
                            </SelectTrigger>
                            <SelectContent>
                                {availableSockets.map((sock) => (
                                    <SelectItem key={sock} value={sock}>{sock}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {errors.socket_type && <p className="text-red-600">{errors.socket_type}</p>}
                    </div>
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
                        {errors.core_count && <p className="text-red-600">{errors.core_count}</p>}
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
                        {errors.thread_count && <p className="text-red-600">{errors.thread_count}</p>}
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
                        {errors.base_clock_ghz && <p className="text-red-600">{errors.base_clock_ghz}</p>}
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
                        {errors.boost_clock_ghz && <p className="text-red-600">{errors.boost_clock_ghz}</p>}
                    </div>
                    <div>
                        <Label htmlFor="integrated_graphics">Integrated Graphics</Label>
                        <Input
                            id="integrated_graphics"
                            name="integrated_graphics"
                            placeholder="e.g. Intel UHD 730"
                            value={data.integrated_graphics}
                            onChange={(e) => setData('integrated_graphics', e.target.value)}
                        />
                        {errors.integrated_graphics && (<p className="text-red-600">{errors.integrated_graphics}</p>)}
                    </div>

                    {/* Row 5: TDP */}
                    <div>
                        <Label htmlFor="tdp_watts">TDP (W)</Label>
                        <Input
                            id="tdp_watts"
                            name="tdp_watts"
                            type="number"
                            min={1}
                            placeholder="e.g. 65"
                            value={data.tdp_watts}
                            onChange={(e) => setData('tdp_watts', Number(e.target.value))}
                        />
                        {errors.tdp_watts && <p className="text-red-600">{errors.tdp_watts}</p>}
                    </div>
                    <div className="flex items-end justify-end">
                        <Button type="submit">Edit Processor Spec</Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
