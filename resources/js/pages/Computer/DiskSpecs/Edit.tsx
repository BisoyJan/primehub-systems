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
    model: string;
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
        model: diskspec.model,
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

            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3 w-full md:w-10/12 lg:w-8/12 mx-auto">
                <div className="flex justify-start">
                    <Link href={index.url()}>
                        <Button>
                            <ArrowLeft /> Return
                        </Button>
                    </Link>
                </div>

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

                    {/* Capacity & Interface */}
                    <section>
                        <h2 className="text-lg font-semibold mb-2">Capacity & Interface</h2>
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
                            <div>
                                <Label htmlFor="interface">Interface</Label>
                                <Select
                                    value={data.interface}
                                    onValueChange={(val) => setData("interface", val)}
                                >
                                    <SelectTrigger id="interface" name="interface">
                                        <SelectValue placeholder="e.g. SATA III" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {["SATA III", "PCIe 3.0 x4 NVMe", "PCIe 4.0 x4 NVMe"].map((iface) => (
                                            <SelectItem key={iface} value={iface}>
                                                {iface}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.interface && <p className="text-red-600">{errors.interface}</p>}
                            </div>
                            <div>
                                <Label htmlFor="drive_type">Drive Type</Label>
                                <Select
                                    value={data.drive_type}
                                    onValueChange={(val) => setData("drive_type", val)}
                                >
                                    <SelectTrigger id="drive_type" name="drive_type">
                                        <SelectValue placeholder="e.g. SSD" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {["HDD", "SSD"].map((type) => (
                                            <SelectItem key={type} value={type}>
                                                {type}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.drive_type && <p className="text-red-600">{errors.drive_type}</p>}
                            </div>
                        </div>
                    </section>

                    {/* Performance */}
                    <section>
                        <h2 className="text-lg font-semibold mb-2">Performance</h2>
                        <div className="grid grid-cols-2 gap-6">
                            <div>
                                <Label htmlFor="sequential_read_mb">Read Speed (MB/s)</Label>
                                <Input
                                    id="sequential_read_mb"
                                    name="sequential_read_mb"
                                    type="number"
                                    min={1}
                                    placeholder="e.g. 3500"
                                    value={String(data.sequential_read_mb)}
                                    onChange={(e) => setData("sequential_read_mb", Number(e.target.value))}
                                />
                                {errors.sequential_read_mb && <p className="text-red-600">{errors.sequential_read_mb}</p>}
                            </div>
                            <div>
                                <Label htmlFor="sequential_write_mb">Write Speed (MB/s)</Label>
                                <Input
                                    id="sequential_write_mb"
                                    name="sequential_write_mb"
                                    type="number"
                                    min={1}
                                    placeholder="e.g. 2500"
                                    value={String(data.sequential_write_mb)}
                                    onChange={(e) => setData("sequential_write_mb", Number(e.target.value))}
                                />
                                {errors.sequential_write_mb && <p className="text-red-600">{errors.sequential_write_mb}</p>}
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
