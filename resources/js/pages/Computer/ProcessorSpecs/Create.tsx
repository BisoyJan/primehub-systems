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
import { store, create, index } from '@/routes/processorspecs';

const intelSockets = ['LGA1151', 'LGA1200', 'LGA1700'];
const amdSockets = ['AM3+', 'AM4', 'AM5', 'TR4', 'sTRX4'];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Create a New Processor Specification', href: create().url },
];

export default function Create() {
    const { flash } = usePage<{ flash?: { message?: string; type?: string } }>().props;

    const { data, setData, post, errors } = useForm({
        manufacturer: '' as string,
        model: '',
        socket_type: '',
        core_count: '' as number | '',
        thread_count: '' as number | '',
        base_clock_ghz: '' as number | '',
        boost_clock_ghz: '' as number | '',
        integrated_graphics: '',
        tdp_watts: '' as number | '',
        stock_quantity: '' as number | '',
    });

    // pick sockets based on selected manufacturer
    const availableSockets = useMemo(() => {
        return data.manufacturer === 'Intel'
            ? intelSockets
            : data.manufacturer === 'AMD'
                ? amdSockets
                : [...intelSockets, ...amdSockets];
    }, [data.manufacturer]);

    useEffect(() => {
        if (!flash?.message) return;
        if (flash.type === "error") {
            toast.error(flash.message);
        } else {
            toast.success(flash.message);
        }
    }, [flash?.message, flash?.type]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(store.url());
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create a New Processor Specification" />

            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3 w-full md:w-10/12 lg:w-8/12 mx-auto">
                <div className="flex justify-start">
                    <Link href={index.url()}>
                        <Button>
                            <ArrowLeft /> Return
                        </Button>
                    </Link>
                </div>

                <form onSubmit={handleSubmit} className="grid grid-cols-2 gap-4">
                    {/* manufacturer */}
                    <div>
                        <Label htmlFor="manufacturer">manufacturer</Label>
                        <Select
                            value={data.manufacturer}
                            onValueChange={(val) => {
                                setData('manufacturer', val)
                                setData('socket_type', '') // reset socket when manufacturer changes
                            }}
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
                        {errors.manufacturer && <p className="text-red-600">{errors.manufacturer}</p>}
                    </div>

                    {/* model */}
                    <div>
                        <Label htmlFor="model">model</Label>
                        <Input
                            id="model"
                            name="model"
                            placeholder="e.g. Core i5-12400"
                            value={data.model}
                            onChange={(e) => setData('model', e.target.value)}
                        />
                        {errors.model && <p className="text-red-600">{errors.model}</p>}
                    </div>

                    {/* Socket Type (dependent) */}
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

                    {/* Row 3 */}
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
                            min={1}
                            placeholder="e.g. 2.50"
                            value={data.base_clock_ghz}
                            onChange={(e) => setData('base_clock_ghz', Number(e.target.value))}
                        />
                        {errors.base_clock_ghz && <p className="text-red-600">{errors.base_clock_ghz}</p>}
                    </div>

                    {/* Row 4 */}
                    <div>
                        <Label htmlFor="boost_clock_ghz">Boost Clock (GHz)</Label>
                        <Input
                            id="boost_clock_ghz"
                            name="boost_clock_ghz"
                            type="number"
                            step="0.01"
                            min={1}
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
                        {errors.integrated_graphics && (
                            <p className="text-red-600">{errors.integrated_graphics}</p>
                        )}
                    </div>

                    {/* Row 5 */}
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

                    {/* Add this input field for stock quantity */}
                    <div className="mb-4">
                        <Label htmlFor="stock_quantity">Initial Stock Quantity</Label>
                        <Input
                            id="stock_quantity"
                            name="stock_quantity"
                            type="number"
                            min={0}
                            placeholder="e.g. 10"
                            value={data.stock_quantity}
                            onChange={(e) => setData('stock_quantity', Number(e.target.value))}
                        />
                        {errors.stock_quantity && <p className="text-red-600">{errors.stock_quantity}</p>}
                    </div>

                    <div className="flex items-end justify-end">
                        <Button type="submit">Add Processor Spec</Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
