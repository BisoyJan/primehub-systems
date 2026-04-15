import { useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, ChevronsUpDown, Plus } from 'lucide-react';

import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectTrigger,
    SelectValue,
    SelectContent,
    SelectItem,
} from '@/components/ui/select';
import { Popover, PopoverTrigger, PopoverContent } from '@/components/ui/popover';
import {
    Command,
    CommandInput,
    CommandList,
    CommandEmpty,
    CommandGroup,
    CommandItem,
} from '@/components/ui/command';
import { cn } from '@/lib/utils';

import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';

import {
    index as pcSpecIndex,
    store as pcSpecStore,
    create as pcSpecCreate,
} from '@/routes/pcspecs';

interface ProcessorOption {
    id: number;
    label: string;
    manufacturer: string;
    core_count: number;
    thread_count: number;
    base_clock_ghz: number;
    boost_clock_ghz: number;
}

type PageProps = {
    processorOptions: ProcessorOption[];
};

const memoryTypes = ['DDR3', 'DDR4', 'DDR5'];

export default function Create() {
    useFlashMessage();

    const { processorOptions } = usePage<PageProps>().props;

    const { title, breadcrumbs } = usePageMeta({
        title: 'Create PC Specification',
        breadcrumbs: [
            { title: 'PC Specifications', href: pcSpecIndex().url },
            { title: 'Create', href: pcSpecCreate().url },
        ],
    });

    const isPageLoading = usePageLoading();

    const form = useForm({
        pc_number: '',
        manufacturer: '',
        model: '',
        memory_type: '',
        ram_gb: 0,
        disk_gb: 0,
        available_ports: '',
        bios_release_date: '',
        processor_mode: 'existing' as 'existing' | 'new',
        processor_spec_id: 0,
        processor_manufacturer: '',
        processor_model: '',
        processor_core_count: '' as number | '',
        processor_thread_count: '' as number | '',
        processor_base_clock_ghz: '' as number | '',
        processor_boost_clock_ghz: '' as number | '',
        quantity: 1,
    });

    const [processorOpen, setProcessorOpen] = useState(false);

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        if (form.data.processor_mode === 'new') {
            form.setData('processor_spec_id', 0);
        }
        form.post(pcSpecStore().url);
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="relative mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3 md:p-6">
                <LoadingOverlay
                    isLoading={isPageLoading || form.processing}
                    message={form.processing ? 'Creating PC specification...' : undefined}
                />

                <PageHeader
                    title="Create PC Specification"
                    description="Add a new PC specification with hardware details."
                    actions={
                        <Link href={pcSpecIndex().url}>
                            <Button variant="outline">
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back to list
                            </Button>
                        </Link>
                    }
                />

                <form onSubmit={handleSubmit} className="space-y-8">
                    {/* Core Info */}
                    <section>
                        <h2 className="mb-2 text-lg font-semibold">Core Info</h2>
                        <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <div>
                                <Label htmlFor="pc_number">PC Number (Optional)</Label>
                                <Input
                                    id="pc_number"
                                    value={form.data.pc_number}
                                    onChange={(e) => form.setData('pc_number', e.target.value)}
                                    placeholder="e.g., PC-2025-001"
                                />
                                {form.errors.pc_number && <p className="text-sm text-red-600">{form.errors.pc_number}</p>}
                            </div>

                            <div>
                                <Label htmlFor="manufacturer">Manufacturer</Label>
                                <Input
                                    id="manufacturer"
                                    value={form.data.manufacturer}
                                    onChange={(e) => form.setData('manufacturer', e.target.value)}
                                />
                                {form.errors.manufacturer && <p className="text-sm text-red-600">{form.errors.manufacturer}</p>}
                            </div>

                            <div>
                                <Label htmlFor="model">Model</Label>
                                <Input
                                    id="model"
                                    value={form.data.model}
                                    onChange={(e) => form.setData('model', e.target.value)}
                                />
                                {form.errors.model && <p className="text-sm text-red-600">{form.errors.model}</p>}
                            </div>

                            <div>
                                <Label htmlFor="quantity">Quantity</Label>
                                <Input
                                    id="quantity"
                                    type="number"
                                    min="1"
                                    max="100"
                                    value={form.data.quantity}
                                    onChange={(e) => form.setData('quantity', parseInt(e.target.value) || 1)}
                                />
                                {form.errors.quantity && <p className="text-sm text-red-600">{form.errors.quantity}</p>}
                            </div>
                        </div>
                    </section>

                    {/* Specifications */}
                    <section>
                        <h2 className="mb-2 text-lg font-semibold">Specifications</h2>
                        <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <div>
                                <Label htmlFor="memory_type">Memory Type</Label>
                                <Select value={form.data.memory_type} onValueChange={(val) => form.setData('memory_type', val)}>
                                    <SelectTrigger id="memory_type" className="w-full">
                                        <SelectValue placeholder="Select memory type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {memoryTypes.map((type) => (
                                            <SelectItem key={type} value={type}>
                                                {type}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {form.errors.memory_type && <p className="text-sm text-red-600">{form.errors.memory_type}</p>}
                            </div>

                            <div>
                                <Label htmlFor="ram_gb">RAM (GB)</Label>
                                <Input
                                    id="ram_gb"
                                    type="number"
                                    min="0"
                                    value={form.data.ram_gb}
                                    onChange={(e) => form.setData('ram_gb', Number(e.target.value))}
                                />
                                {form.errors.ram_gb && <p className="text-sm text-red-600">{form.errors.ram_gb}</p>}
                            </div>

                            <div>
                                <Label htmlFor="disk_gb">Disk (GB)</Label>
                                <Input
                                    id="disk_gb"
                                    type="number"
                                    min="0"
                                    value={form.data.disk_gb}
                                    onChange={(e) => form.setData('disk_gb', Number(e.target.value))}
                                />
                                {form.errors.disk_gb && <p className="text-sm text-red-600">{form.errors.disk_gb}</p>}
                            </div>

                            <div>
                                <Label htmlFor="available_ports">Available Ports</Label>
                                <Input
                                    id="available_ports"
                                    value={form.data.available_ports}
                                    onChange={(e) => form.setData('available_ports', e.target.value)}
                                    placeholder="e.g., HDMI, DisplayPort, USB-C, VGA"
                                />
                                {form.errors.available_ports && <p className="text-sm text-red-600">{form.errors.available_ports}</p>}
                            </div>

                            <div>
                                <Label htmlFor="bios_release_date">Bios Release Date</Label>
                                <Input
                                    id="bios_release_date"
                                    type="date"
                                    value={form.data.bios_release_date}
                                    onChange={(e) => form.setData('bios_release_date', e.target.value)}
                                />
                                {form.errors.bios_release_date && <p className="text-sm text-red-600">{form.errors.bios_release_date}</p>}
                            </div>
                        </div>
                    </section>

                    {/* Processor */}
                    <section>
                        <div className="mb-3 flex items-center justify-between">
                            <h2 className="text-lg font-semibold">Processor</h2>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                    const newMode = form.data.processor_mode === 'existing' ? 'new' : 'existing';
                                    form.setData('processor_mode', newMode);
                                }}
                            >
                                {form.data.processor_mode === 'existing' ? (
                                    <>
                                        <Plus className="mr-1 h-4 w-4" />
                                        Create New
                                    </>
                                ) : (
                                    'Select Existing'
                                )}
                            </Button>
                        </div>

                        {form.data.processor_mode === 'existing' ? (
                            <div className="space-y-2">
                                <Label htmlFor="processor_spec_id">Select Processor</Label>
                                <Popover open={processorOpen} onOpenChange={setProcessorOpen}>
                                    <PopoverTrigger asChild>
                                        <Button
                                            variant="outline"
                                            role="combobox"
                                            aria-expanded={processorOpen}
                                            className={cn(
                                                'w-full justify-between',
                                                form.errors.processor_spec_id && 'border-destructive'
                                            )}
                                        >
                                            {form.data.processor_spec_id
                                                ? processorOptions.find((p) => p.id === form.data.processor_spec_id)?.label
                                                : 'Select a processor...'}
                                            <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                        </Button>
                                    </PopoverTrigger>
                                    <PopoverContent className="w-full p-0">
                                        <Command>
                                            <CommandInput placeholder="Search processors..." />
                                            <CommandList>
                                                <CommandEmpty>No processor found.</CommandEmpty>
                                                <CommandGroup>
                                                    {processorOptions.map((opt) => (
                                                        <CommandItem
                                                            key={opt.id}
                                                            value={opt.label}
                                                            onSelect={() => {
                                                                form.setData('processor_spec_id', opt.id);
                                                                setProcessorOpen(false);
                                                            }}
                                                        >
                                                            <Check
                                                                className={cn(
                                                                    'mr-2 h-4 w-4',
                                                                    form.data.processor_spec_id === opt.id ? 'opacity-100' : 'opacity-0'
                                                                )}
                                                            />
                                                            <div className="flex flex-col">
                                                                <span>{opt.label}</span>
                                                                <span className="text-xs text-muted-foreground">
                                                                    {opt.core_count}C/{opt.thread_count}T • {opt.base_clock_ghz}–{opt.boost_clock_ghz} GHz
                                                                </span>
                                                            </div>
                                                        </CommandItem>
                                                    ))}
                                                </CommandGroup>
                                            </CommandList>
                                        </Command>
                                    </PopoverContent>
                                </Popover>
                                {form.errors.processor_spec_id && <p className="text-sm text-destructive">{form.errors.processor_spec_id}</p>}
                            </div>
                        ) : (
                            <div className="rounded-lg border p-4 space-y-4">
                                <p className="text-muted-foreground text-sm">Fill in the processor details below. A new processor spec will be created automatically.</p>
                                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <div>
                                        <Label htmlFor="processor_manufacturer">Manufacturer</Label>
                                        <Select
                                            value={form.data.processor_manufacturer}
                                            onValueChange={(val) => form.setData('processor_manufacturer', val)}
                                        >
                                            <SelectTrigger id="processor_manufacturer">
                                                <SelectValue placeholder="Select manufacturer" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {['Intel', 'AMD'].map((b) => (
                                                    <SelectItem key={b} value={b}>{b}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {form.errors.processor_manufacturer && <p className="text-sm text-red-600">{form.errors.processor_manufacturer}</p>}
                                    </div>

                                    <div>
                                        <Label htmlFor="processor_model">Model</Label>
                                        <Input
                                            id="processor_model"
                                            placeholder="e.g. Core i5-12400"
                                            value={form.data.processor_model}
                                            onChange={(e) => form.setData('processor_model', e.target.value)}
                                        />
                                        {form.errors.processor_model && <p className="text-sm text-red-600">{form.errors.processor_model}</p>}
                                    </div>

                                    <div>
                                        <Label htmlFor="processor_core_count">Core Count</Label>
                                        <Input
                                            id="processor_core_count"
                                            type="number"
                                            min={1}
                                            placeholder="e.g. 6"
                                            value={form.data.processor_core_count}
                                            onChange={(e) => form.setData('processor_core_count', e.target.value ? Number(e.target.value) : '')}
                                        />
                                        {form.errors.processor_core_count && <p className="text-sm text-red-600">{form.errors.processor_core_count}</p>}
                                    </div>

                                    <div>
                                        <Label htmlFor="processor_thread_count">Thread Count</Label>
                                        <Input
                                            id="processor_thread_count"
                                            type="number"
                                            min={1}
                                            placeholder="e.g. 12"
                                            value={form.data.processor_thread_count}
                                            onChange={(e) => form.setData('processor_thread_count', e.target.value ? Number(e.target.value) : '')}
                                        />
                                        {form.errors.processor_thread_count && <p className="text-sm text-red-600">{form.errors.processor_thread_count}</p>}
                                    </div>

                                    <div>
                                        <Label htmlFor="processor_base_clock_ghz">Base Clock (GHz)</Label>
                                        <Input
                                            id="processor_base_clock_ghz"
                                            type="number"
                                            step="0.01"
                                            min={0}
                                            placeholder="e.g. 2.50"
                                            value={form.data.processor_base_clock_ghz}
                                            onChange={(e) => form.setData('processor_base_clock_ghz', e.target.value ? Number(e.target.value) : '')}
                                        />
                                        {form.errors.processor_base_clock_ghz && <p className="text-sm text-red-600">{form.errors.processor_base_clock_ghz}</p>}
                                    </div>

                                    <div>
                                        <Label htmlFor="processor_boost_clock_ghz">Boost Clock (GHz)</Label>
                                        <Input
                                            id="processor_boost_clock_ghz"
                                            type="number"
                                            step="0.01"
                                            min={0}
                                            placeholder="e.g. 4.40"
                                            value={form.data.processor_boost_clock_ghz}
                                            onChange={(e) => form.setData('processor_boost_clock_ghz', e.target.value ? Number(e.target.value) : '')}
                                        />
                                        {form.errors.processor_boost_clock_ghz && <p className="text-sm text-red-600">{form.errors.processor_boost_clock_ghz}</p>}
                                    </div>
                                </div>
                            </div>
                        )}
                    </section>

                    {/* Submit */}
                    <div className="flex justify-end">
                        <Button type="submit" disabled={form.processing}>
                            {form.data.quantity > 1 ? `Create ${form.data.quantity} PCs` : 'Create PC Spec'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
