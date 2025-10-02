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
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { ArrowLeft, CircleAlert } from 'lucide-react';

import type { BreadcrumbItem } from '@/types';
import { update, index } from '@/routes/diskspecs';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Edit a Disk Specification',
        href: index().url,
    },
];

interface DiskSpec {
    id: number;
    manufacturer: string;
    model_number: string;
    capacity_gb: number;
    interface: string;
    drive_type: string;
    sequential_read_mb: number;
    sequential_write_mb: number;
}

interface Props {
    diskspec: DiskSpec;
}

export default function Edit({ diskspec }: Props) {

    const { data, setData, put, errors } = useForm({
        manufacturer: diskspec.manufacturer,
        model_number: diskspec.model_number,
        capacity_gb: diskspec.capacity_gb,
        interface: diskspec.interface,
        drive_type: diskspec.drive_type,
        sequential_read_mb: diskspec.sequential_read_mb,
        sequential_write_mb: diskspec.sequential_write_mb,
    });

    const { flash } = usePage().props as { flash?: { message?: string; type?: string } };

    useEffect(() => {
        if (!flash?.message) return;
        if (flash.type === "error") {
            toast.error(flash.message);
        } else {
            toast.success(flash.message);
        }
    }, [flash?.message, flash?.type]);

    const handleUpdate = (e: React.FormEvent) => {
        e.preventDefault();
        put(update.url(diskspec.id));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Edit Disk Specification" />

            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3 w-8/12">
                <div className="flex justify-start">
                    <Link href={index.url()}>
                        <Button>
                            <ArrowLeft /> Return
                        </Button>
                    </Link>
                </div>

                <form onSubmit={handleUpdate} className="grid grid-cols-2 gap-4">
                    {Object.keys(errors).length > 0 && (
                        <div className="col-span-2">
                            <Alert>
                                <CircleAlert className="h-4 w-4" />
                                <AlertTitle>Error!</AlertTitle>
                                <AlertDescription>
                                    <ul>
                                        {Object.entries(errors).map(([field, msg]) => (
                                            <li key={field}>{msg as string}</li>
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
                            placeholder="e.g. Samsung"
                            value={data.manufacturer}
                            onChange={(e) => setData('manufacturer', e.target.value)}
                        />
                    </div>
                    <div>
                        <Label htmlFor="model_number">Model Number</Label>
                        <Input
                            id="model_number"
                            name="model_number"
                            placeholder="e.g. 980 Pro"
                            value={data.model_number}
                            onChange={(e) => setData('model_number', e.target.value)}
                        />
                    </div>

                    {/* Row 2 */}
                    <div>
                        <Label htmlFor="capacity_gb">Capacity (GB)</Label>
                        <Select
                            value={String(data.capacity_gb)}
                            onValueChange={(val) => setData('capacity_gb', Number(val))}
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
                    </div>
                    <div>
                        <Label htmlFor="interface">Interface</Label>
                        <Select
                            value={data.interface}
                            onValueChange={(val) => setData('interface', val)}
                        >
                            <SelectTrigger id="interface" name="interface">
                                <SelectValue placeholder="e.g. SATA III" />
                            </SelectTrigger>
                            <SelectContent>
                                {['SATA III', 'PCIe 3.0 x4 NVMe', 'PCIe 4.0 x4 NVMe'].map((iface) => (
                                    <SelectItem key={iface} value={iface}>
                                        {iface}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    {/* Row 3 */}
                    <div>
                        <Label htmlFor="drive_type">Drive Type</Label>
                        <Select
                            value={data.drive_type}
                            onValueChange={(val) => setData('drive_type', val)}
                        >
                            <SelectTrigger id="drive_type" name="drive_type">
                                <SelectValue placeholder="e.g. SSD" />
                            </SelectTrigger>
                            <SelectContent>
                                {['HDD', 'SSD'].map((type) => (
                                    <SelectItem key={type} value={type}>
                                        {type}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div>
                        <Label htmlFor="sequential_read_mb">Read Speed (MB/s)</Label>
                        <Input
                            id="sequential_read_mb"
                            name="sequential_read_mb"
                            type="number"
                            min={1}
                            placeholder="e.g. 3500"
                            value={String(data.sequential_read_mb)}
                            onChange={(e) => setData('sequential_read_mb', Number(e.target.value))}
                        />
                    </div>

                    {/* Row 4 */}
                    <div>
                        <Label htmlFor="sequential_write_mb">Write Speed (MB/s)</Label>
                        <Input
                            id="sequential_write_mb"
                            name="sequential_write_mb"
                            type="number"
                            min={1}
                            placeholder="e.g. 2500"
                            value={String(data.sequential_write_mb)}
                            onChange={(e) => setData('sequential_write_mb', Number(e.target.value))}
                        />
                    </div>
                    <div className="flex items-end justify-end">
                        <Button type="submit">Edit Disk Spec</Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
