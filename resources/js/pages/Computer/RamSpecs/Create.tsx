import { useEffect } from 'react';
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
import { store, create, index } from '@/routes/ramspecs';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Create a New RAM Specification',
        href: create().url,
    },
];

export default function Create() {
    const { data, setData, post, errors } = useForm({
        manufacturer: '',
        model: '',
        capacity_gb: '' as number | '',
        type: '',
        speed: '',
        form_factor: '',
        voltage: '',
    });

    const { flash } = usePage<{ flash?: { message?: string; type?: string } }>().props;

    // show backend flash once on mount
    useEffect(() => {
        if (!flash?.message) return;

        if (flash.type === 'error') {
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
            <Head title="Create a New RAM Specification" />

            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3 w-full md:w-10/12 lg:w-8/12 mx-auto">
                <div className="flex justify-start">
                    <Link href={index.url()}>
                        <Button>
                            <ArrowLeft /> Return
                        </Button>
                    </Link>
                </div>

                <form onSubmit={handleSubmit} className="grid grid-cols-2 gap-4">
                    {/* Row 1 */}
                    <div>
                        <Label htmlFor="manufacturer">Manufacturer</Label>
                        <Input
                            id="manufacturer"
                            name="manufacturer"
                            placeholder="e.g. Corsair"
                            value={data.manufacturer}
                            onChange={(e) => setData('manufacturer', e.target.value)}
                        />
                        {errors.manufacturer && <p className="text-red-600">{errors.manufacturer}</p>}
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
                        {errors.model && <p className="text-red-600">{errors.model}</p>}
                    </div>

                    {/* Row 2 */}
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
                        {errors.capacity_gb && <p className="text-red-600">{errors.capacity_gb}</p>}
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
                        {errors.type && <p className="text-red-600">{errors.type}</p>}
                    </div>

                    {/* Row 3 */}
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
                        {errors.speed && <p className="text-red-600">{errors.speed}</p>}
                    </div>
                    <div>
                        <Label htmlFor="form_factor">Form Factor</Label>
                        <Select
                            value={data.form_factor}
                            onValueChange={(val) => setData('form_factor', val)}
                        >
                            <SelectTrigger id="form_factor" name="form_factor">
                                <SelectValue placeholder="e.g. SO-DIMM" />
                            </SelectTrigger>
                            <SelectContent>
                                {['SO-DIMM', 'DIMM'].map((t) => (
                                    <SelectItem key={t} value={t}>
                                        {t}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {errors.form_factor && <p className="text-red-600">{errors.form_factor}</p>}
                    </div>

                    {/* Row 4 */}
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
                            onChange={(e) => setData('voltage', e.target.value)}
                        />
                        {errors.voltage && <p className="text-red-600">{errors.voltage}</p>}
                    </div>
                    <div className="flex items-end justify-end">
                        <Button type="submit">Add RAM Spec</Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
