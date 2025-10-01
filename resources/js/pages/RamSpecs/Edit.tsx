import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { update, create, index } from '@/routes/ramspecs';
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
import { ArrowLeft, CircleAlert } from 'lucide-react';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Edit a RAM Specification',
        href: create().url,
    },
];

interface ramSpec {
    id: number;
    manufacturer: string;
    model: string;
    capacity_gb: number;
    type: string;
    speed: string;
    form_factor: string;
    voltage: number;
}

interface Props {
    ramSpec: ramSpec
}

export default function Edit({ ramSpec }: Props) {

    const { data, setData, put, errors } = useForm({
        manufacturer: ramSpec.manufacturer,
        model: ramSpec.model,
        capacity_gb: ramSpec.capacity_gb,
        type: ramSpec.type,
        speed: ramSpec.speed,
        form_factor: ramSpec.form_factor,
        voltage: ramSpec.voltage,
    });

    const handleUpdate = (e: React.FormEvent) => {
        e.preventDefault();
        put(update.url(ramSpec.id));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Create a New RAM Specification" />

            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3 w-8/12">
                <div className="flex justify-start">
                    <Link href={index.url()}>
                        <Button>
                            <ArrowLeft /> Return
                        </Button>
                    </Link>
                </div>

                <form onSubmit={handleUpdate} className="grid grid-cols-2 gap-4">
                    {/* 1. Error Alert spans both columns */}
                    {Object.keys(errors).length > 0 && (
                        <div className="col-span-2">
                            <Alert>
                                <CircleAlert className="h-4 w-4" />
                                <AlertTitle>Error!</AlertTitle>
                                <AlertDescription>
                                    <ul>
                                        {Object.entries(errors).map(([key, message]) => (
                                            <li key={key}>{message as string}</li>
                                        ))}
                                    </ul>
                                </AlertDescription>
                            </Alert>
                        </div>
                    )}

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
                            onChange={(e) => setData('voltage', Number(e.target.value))}
                        />
                    </div>
                    <div className="flex items-end justify-end">
                        <Button type="submit">Edit RAM Spec</Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
