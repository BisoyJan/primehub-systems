import React from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { PageProps as InertiaPageProps } from "@inertiajs/core";

import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ArrowLeft, Plus, X } from 'lucide-react';

import { useFlashMessage } from '@/hooks';
import { store, update, create, edit, index } from '@/routes/monitorspecs';

interface MonitorSpec {
    id: number;
    brand: string;
    model: string;
    screen_size: number;
    resolution: string;
    panel_type: string;
    ports: string[] | null;
    notes: string | null;
}

interface Props extends InertiaPageProps {
    monitorspec: MonitorSpec | null;
}

export default function Form() {
    useFlashMessage();

    const { monitorspec } = usePage<Props>().props;
    const isEditing = !!monitorspec;

    const { data, setData, post, put, errors } = useForm({
        brand: monitorspec?.brand || '',
        model: monitorspec?.model || '',
        screen_size: monitorspec?.screen_size || ('' as number | ''),
        resolution: monitorspec?.resolution || '',
        panel_type: monitorspec?.panel_type || '',
        ports: (monitorspec?.ports || []) as string[],
        notes: monitorspec?.notes || '',
    });

    const [portInput, setPortInput] = React.useState('');

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (isEditing && monitorspec) {
            put(update.url(monitorspec.id));
        } else {
            post(store.url());
        }
    };

    const addPort = () => {
        if (portInput.trim() && !data.ports.includes(portInput.trim())) {
            setData('ports', [...data.ports, portInput.trim()]);
            setPortInput('');
        }
    };

    const removePort = (port: string) => {
        setData('ports', data.ports.filter(p => p !== port));
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Monitor Specifications', href: index().url },
            { title: isEditing ? 'Edit' : 'Create', href: isEditing ? edit.url(monitorspec!.id) : create().url }
        ]}>
            <Head title={isEditing ? 'Edit Monitor Specification' : 'Create Monitor Specification'} />

            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3 w-full md:w-10/12 lg:w-8/12 mx-auto">
                <div className="flex justify-start">
                    <Link href={index.url()}>
                        <Button>
                            <ArrowLeft /> Return
                        </Button>
                    </Link>
                </div>

                <form onSubmit={handleSubmit} className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {/* Brand */}
                    <div>
                        <Label htmlFor="brand">Brand *</Label>
                        <Input
                            id="brand"
                            name="brand"
                            placeholder="e.g. Dell, LG, Samsung"
                            value={data.brand}
                            onChange={(e) => setData('brand', e.target.value)}
                        />
                        {errors.brand && <p className="text-red-600 text-sm mt-1">{errors.brand}</p>}
                    </div>

                    {/* Model */}
                    <div>
                        <Label htmlFor="model">Model *</Label>
                        <Input
                            id="model"
                            name="model"
                            placeholder="e.g. U2723DE"
                            value={data.model}
                            onChange={(e) => setData('model', e.target.value)}
                        />
                        {errors.model && <p className="text-red-600 text-sm mt-1">{errors.model}</p>}
                    </div>

                    {/* Screen Size */}
                    <div>
                        <Label htmlFor="screen_size">Screen Size (inches) *</Label>
                        <Select
                            value={data.screen_size ? String(data.screen_size) : ''}
                            onValueChange={(val) => setData('screen_size', Number(val))}
                        >
                            <SelectTrigger id="screen_size" name="screen_size">
                                <SelectValue placeholder="Select screen size" />
                            </SelectTrigger>
                            <SelectContent>
                                {[19, 21.5, 22, 24, 27, 32, 34, 43, 49].map((size) => (
                                    <SelectItem key={size} value={String(size)}>
                                        {size}"
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {errors.screen_size && <p className="text-red-600 text-sm mt-1">{errors.screen_size}</p>}
                    </div>

                    {/* Resolution */}
                    <div>
                        <Label htmlFor="resolution">Resolution *</Label>
                        <Select
                            value={data.resolution}
                            onValueChange={(val) => setData('resolution', val)}
                        >
                            <SelectTrigger id="resolution" name="resolution">
                                <SelectValue placeholder="Select resolution" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="1366x768">1366x768 (HD)</SelectItem>
                                <SelectItem value="1920x1080">1920x1080 (Full HD)</SelectItem>
                                <SelectItem value="1920x1200">1920x1200 (WUXGA)</SelectItem>
                                <SelectItem value="2560x1080">2560x1080 (UW-FHD)</SelectItem>
                                <SelectItem value="2560x1440">2560x1440 (QHD)</SelectItem>
                                <SelectItem value="3440x1440">3440x1440 (UW-QHD)</SelectItem>
                                <SelectItem value="3840x2160">3840x2160 (4K UHD)</SelectItem>
                            </SelectContent>
                        </Select>
                        {errors.resolution && <p className="text-red-600 text-sm mt-1">{errors.resolution}</p>}
                    </div>

                    {/* Panel Type */}
                    <div>
                        <Label htmlFor="panel_type">Panel Type *</Label>
                        <Select
                            value={data.panel_type}
                            onValueChange={(val) => setData('panel_type', val)}
                        >
                            <SelectTrigger id="panel_type" name="panel_type">
                                <SelectValue placeholder="Select panel type" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="IPS">IPS (In-Plane Switching)</SelectItem>
                                <SelectItem value="VA">VA (Vertical Alignment)</SelectItem>
                                <SelectItem value="TN">TN (Twisted Nematic)</SelectItem>
                                <SelectItem value="OLED">OLED</SelectItem>
                            </SelectContent>
                        </Select>
                        {errors.panel_type && <p className="text-red-600 text-sm mt-1">{errors.panel_type}</p>}
                    </div>

                    {/* Ports */}
                    <div className="md:col-span-2">
                        <Label htmlFor="port_input">Ports</Label>
                        <div className="flex gap-2">
                            <Input
                                id="port_input"
                                placeholder="e.g. HDMI, DisplayPort, USB-C"
                                value={portInput}
                                onChange={(e) => setPortInput(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                        addPort();
                                    }
                                }}
                            />
                            <Button type="button" onClick={addPort} variant="outline" size="icon">
                                <Plus className="h-4 w-4" />
                            </Button>
                        </div>
                        {errors.ports && <p className="text-red-600 text-sm mt-1">{errors.ports}</p>}

                        {/* Display added ports */}
                        {data.ports.length > 0 && (
                            <div className="flex flex-wrap gap-2 mt-2">
                                {data.ports.map((port, idx) => (
                                    <div key={idx} className="flex items-center gap-1 bg-secondary text-secondary-foreground px-3 py-1 rounded-md text-sm">
                                        {port}
                                        <button
                                            type="button"
                                            onClick={() => removePort(port)}
                                            className="ml-1 hover:text-destructive"
                                        >
                                            <X className="h-3 w-3" />
                                        </button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Notes */}
                    <div className="md:col-span-2">
                        <Label htmlFor="notes">Notes</Label>
                        <Textarea
                            id="notes"
                            name="notes"
                            placeholder="Additional notes or specifications..."
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                            rows={4}
                        />
                        {errors.notes && <p className="text-red-600 text-sm mt-1">{errors.notes}</p>}
                    </div>

                    {/* Submit Button */}
                    <div className="md:col-span-2 flex justify-end gap-2">
                        <Link href={index.url()}>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit">
                            {isEditing ? 'Update Monitor Spec' : 'Create Monitor Spec'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
