import { useEffect } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { toast } from 'sonner';

import AppLayout from '@/layouts/app-layout';
import {
    Select,
    SelectTrigger,
    SelectValue,
    SelectContent,
    SelectGroup,
    SelectLabel,
    SelectItem,
} from '@/components/ui/select';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem } from '@/components/ui/command'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Check, ChevronsUpDown } from 'lucide-react'
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { ArrowLeft } from 'lucide-react';

import type { BreadcrumbItem } from '@/types';
import {
    index as motherboardIndex,
    update as motherboardUpdate,
} from '@/routes/motherboards';

const memoryTypes = ['DDR3', 'DDR4', 'DDR5'];
const ramSlotOptions = [1, 2, 4, 6, 8];
const m2SlotOptions = [0, 1, 2, 3, 4];
const sataPortOptions = [0, 2, 4, 6, 8];
const maxRamCapacityOptions = [16, 32, 64, 128, 256];
const ethernetSpeedOptions = ['1GbE', '2.5GbE', '5GbE', '10GbE'];


const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Edit a Motherboard Specification', href: motherboardIndex().url },
];

interface Option {
    id: number;
    label: string;
}

interface Motherboard {
    id: number;
    brand: string;
    model: string;
    chipset: string;
    form_factor: string;
    memory_type: string;
    ram_slots: number;
    max_ram_capacity_gb: number;
    max_ram_speed: string;
    pcie_slots: string;
    m2_slots: number;
    sata_ports: number;
    usb_ports: string;
    ethernet_speed: string;
    wifi: boolean;
    ramSpecs: Array<{ id: number }>;
    diskSpecs: Array<{ id: number }>;
    processorSpecs: Array<{ id: number }>;
}

interface Props {
    motherboard: Motherboard;
    ramOptions: Option[];
    diskOptions: Option[];
    processorOptions: Option[];
    flash?: { message?: string; type?: string };
}

export default function Edit({ motherboard, ramOptions, diskOptions, processorOptions, flash }: Props) {
    const { data, setData, put, errors } = useForm({
        brand: motherboard.brand,
        model: motherboard.model,
        chipset: motherboard.chipset,
        form_factor: motherboard.form_factor,
        memory_type: motherboard.memory_type,
        ram_slots: motherboard.ram_slots,
        max_ram_capacity_gb: motherboard.max_ram_capacity_gb,
        max_ram_speed: motherboard.max_ram_speed,
        pcie_slots: motherboard.pcie_slots,
        m2_slots: motherboard.m2_slots,
        sata_ports: motherboard.sata_ports,
        usb_ports: motherboard.usb_ports,
        ethernet_speed: motherboard.ethernet_speed,
        wifi: motherboard.wifi,
        ram_spec_ids:
            (motherboard.ramSpecs ?? []).map((r) => r.id),

        disk_spec_ids:
            (motherboard.diskSpecs ?? []).map((d) => d.id),

        processor_spec_ids:
            (motherboard.processorSpecs ?? []).map((p) => p.id),
    });

    useEffect(() => {
        if (!flash?.message) return;
        if (flash.type === "error") {
            toast.error(flash.message);
        } else {
            toast.success(flash.message);
        }
    }, [flash?.message, flash?.type]);


    function handleUpdate(e: React.FormEvent) {
        e.preventDefault();
        put(motherboardUpdate.url(motherboard.id));
    }

    function toggleSelect(field: 'ram_spec_ids' | 'disk_spec_ids' | 'processor_spec_ids', id: number) {
        const list = data[field]
        const next = list.includes(id) ? list.filter(i => i !== id) : [...list, id]
        setData(field, next)
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Edit Motherboard" />

            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3 w-8/12">
                <div className="flex justify-start">
                    <Link href={motherboardIndex.url()}>
                        <Button><ArrowLeft /> Return</Button>
                    </Link>
                </div>


                <form onSubmit={handleUpdate} className="grid grid-cols-2 gap-6">
                    {/* Brand */}
                    <div>
                        <Label htmlFor="brand">Brand</Label>
                        <Input
                            id="brand"
                            name="brand"
                            value={data.brand}
                            onChange={e => setData('brand', e.target.value)}
                        />
                        {errors.brand && <p className="text-red-600">{errors.brand}</p>}
                    </div>

                    {/* Model */}
                    <div>
                        <Label htmlFor="model">Model</Label>
                        <Input
                            id="model"
                            name="model"
                            value={data.model}
                            onChange={e => setData('model', e.target.value)}
                        />
                        {errors.model && <p className="text-red-600">{errors.model}</p>}
                    </div>

                    {/* Chipset */}
                    <div>
                        <Label htmlFor="chipset">Chipset</Label>
                        <Input
                            id="chipset"
                            name="chipset"
                            value={data.chipset}
                            onChange={e => setData('chipset', e.target.value)}
                        />
                        {errors.chipset && <p className="text-red-600">{errors.chipset}</p>}
                    </div>

                    {/* Form Factor */}
                    <div>
                        <Label htmlFor="form_factor">Form Factor</Label>
                        <Input
                            id="form_factor"
                            name="form_factor"
                            value={data.form_factor}
                            onChange={e => setData('form_factor', e.target.value)}
                        />
                        {errors.form_factor && <p className="text-red-600">{errors.form_factor}</p>}
                    </div>

                    {/* Memory Type */}
                    <div>
                        <Label htmlFor="memory_type">Memory Type</Label>
                        <Select
                            onValueChange={(val) => setData('memory_type', val)}
                            value={data.memory_type}
                        >
                            <SelectTrigger id="memory_type" className="w-full">
                                <SelectValue placeholder="Select memory type" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    <SelectLabel>Type</SelectLabel>
                                    {memoryTypes.map((type) => (
                                        <SelectItem key={type} value={type}>
                                            {type}
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                        {errors.memory_type && (
                            <p className="text-red-600">{errors.memory_type}</p>
                        )}
                    </div>


                    {/* RAM Slots */}
                    <div>
                        <Label htmlFor="ram_slots">RAM Slots</Label>
                        <Select
                            onValueChange={(val) => setData('ram_slots', Number(val))}
                            value={String(data.ram_slots)}
                        >
                            <SelectTrigger id="ram_slots" className="w-full">
                                <SelectValue placeholder="Select # of slots" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    <SelectLabel>Slots</SelectLabel>
                                    {ramSlotOptions.map((n) => (
                                        <SelectItem key={n} value={String(n)}>
                                            {n}
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                        {errors.ram_slots && (
                            <p className="text-red-600">{errors.ram_slots}</p>
                        )}
                    </div>

                    {/* Max RAM Capacity */}
                    <div>
                        <Label htmlFor="max_ram_capacity_gb">Max RAM Capacity (GB)</Label>
                        <Select
                            onValueChange={(val) => setData('max_ram_capacity_gb', Number(val))}
                            value={String(data.max_ram_capacity_gb)}
                        >
                            <SelectTrigger id="max_ram_capacity_gb" className="w-full">
                                <SelectValue placeholder="Select capacity" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    <SelectLabel>Capacity</SelectLabel>
                                    {maxRamCapacityOptions.map((cap) => (
                                        <SelectItem key={cap} value={String(cap)}>
                                            {cap} GB
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                        {errors.max_ram_capacity_gb && (
                            <p className="text-red-600">{errors.max_ram_capacity_gb}</p>
                        )}
                    </div>


                    {/* Max RAM Speed */}
                    <div>
                        <Label htmlFor="max_ram_speed">Max RAM Speed</Label>
                        <Input
                            id="max_ram_speed"
                            name="max_ram_speed"
                            value={data.max_ram_speed}
                            onChange={e => setData('max_ram_speed', e.target.value)}
                        />
                        {errors.max_ram_speed && <p className="text-red-600">{errors.max_ram_speed}</p>}
                    </div>

                    {/* PCIe Slots */}
                    <div>
                        <Label htmlFor="pcie_slots">PCIe Slots</Label>
                        <Input
                            id="pcie_slots"
                            name="pcie_slots"
                            value={data.pcie_slots}
                            onChange={e => setData('pcie_slots', e.target.value)}
                        />
                        {errors.pcie_slots && <p className="text-red-600">{errors.pcie_slots}</p>}
                    </div>

                    {/* M.2 Slots */}
                    <div>
                        <Label htmlFor="m2_slots">M.2 Slots</Label>
                        <Select
                            onValueChange={(val) => setData('m2_slots', Number(val))}
                            value={String(data.m2_slots)}
                        >
                            <SelectTrigger id="m2_slots" className="w-full">
                                <SelectValue placeholder="Select M.2 slots" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    <SelectLabel>M.2</SelectLabel>
                                    {m2SlotOptions.map((n) => (
                                        <SelectItem key={n} value={String(n)}>
                                            {n}
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                        {errors.m2_slots && (
                            <p className="text-red-600">{errors.m2_slots}</p>
                        )}
                    </div>

                    {/* SATA Ports */}
                    <div>
                        <Label htmlFor="sata_ports">SATA Ports</Label>
                        <Select
                            onValueChange={(val) => setData('sata_ports', Number(val))}
                            value={String(data.sata_ports)}
                        >
                            <SelectTrigger id="sata_ports" className="w-full">
                                <SelectValue placeholder="Select SATA ports" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    <SelectLabel>SATA</SelectLabel>
                                    {sataPortOptions.map((n) => (
                                        <SelectItem key={n} value={String(n)}>
                                            {n}
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                        {errors.sata_ports && (
                            <p className="text-red-600">{errors.sata_ports}</p>
                        )}
                    </div>


                    {/* USB Ports */}
                    <div>
                        <Label htmlFor="usb_ports">USB Ports</Label>
                        <Input
                            id="usb_ports"
                            name="usb_ports"
                            value={data.usb_ports}
                            onChange={e => setData('usb_ports', e.target.value)}
                        />
                        {errors.usb_ports && <p className="text-red-600">{errors.usb_ports}</p>}
                    </div>

                    {/* Ethernet Speed */}
                    <div>
                        <Label htmlFor="ethernet_speed">Ethernet Speed</Label>
                        <Select
                            onValueChange={(val) => setData('ethernet_speed', val)}
                            value={data.ethernet_speed}
                        >
                            <SelectTrigger id="ethernet_speed" className="w-full">
                                <SelectValue placeholder="Select speed" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    <SelectLabel>Speed</SelectLabel>
                                    {ethernetSpeedOptions.map((speed) => (
                                        <SelectItem key={speed} value={speed}>
                                            {speed}
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                        {errors.ethernet_speed && (
                            <p className="text-red-600">{errors.ethernet_speed}</p>
                        )}
                    </div>

                    {/* Wi-Fi */}
                    <div className="flex items-center space-x-2">
                        <Input
                            id="wifi"
                            name="wifi"
                            type="checkbox"
                            checked={data.wifi}
                            onChange={e => setData('wifi', e.target.checked)}
                        />
                        <Label htmlFor="wifi">Wi-Fi Enabled</Label>
                        {errors.wifi && <p className="text-red-600">{errors.wifi}</p>}
                    </div>

                    {/* RAM Modules multi-select */}
                    <MultiPopover
                        id="ram_spec_ids"
                        label="RAM Modules"
                        options={ramOptions}
                        selected={data.ram_spec_ids}
                        onToggle={id => toggleSelect('ram_spec_ids', id)}
                        error={errors.ram_spec_ids}
                    />

                    {/* Disk Drives multi-select */}
                    <MultiPopover
                        id="disk_spec_ids"
                        label="Disk Drives"
                        options={diskOptions}
                        selected={data.disk_spec_ids}
                        onToggle={id => toggleSelect('disk_spec_ids', id)}
                        error={errors.disk_spec_ids}
                    />

                    {/* Processors multi-select */}
                    <MultiPopover
                        id="processor_spec_ids"
                        label="Processors"
                        options={processorOptions}
                        selected={data.processor_spec_ids}
                        onToggle={id => toggleSelect('processor_spec_ids', id)}
                        error={errors.processor_spec_ids}
                    />

                    <div className="col-span-2 flex justify-end">
                        <Button type="submit">Update Motherboard</Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}

function MultiPopover({
    id,
    label,
    options,
    selected,
    onToggle,
    error,
}: {
    id: string;
    label: string;
    options: Option[];
    selected: number[];
    onToggle: (id: number) => void;
    error?: string;
}) {
    return (
        <div className="col-span-2">
            <Label htmlFor={id}>{label}</Label>
            <Popover>
                <PopoverTrigger asChild>
                    <Button variant="outline" className="w-full justify-between" id={id}>
                        {selected.length
                            ? options
                                .filter(o => selected.includes(o.id))
                                .map(o => o.label)
                                .join(', ')
                            : `Select ${label.toLowerCase()}…`}
                        <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent className="w-full p-0">
                    <Command>
                        <CommandInput placeholder={`Search ${label.toLowerCase()}…`} />
                        <CommandEmpty>No {label.toLowerCase()} found.</CommandEmpty>
                        <CommandGroup>
                            {options.map(opt => {
                                const isSelected = selected.includes(opt.id);
                                return (
                                    <CommandItem key={opt.id} onSelect={() => onToggle(opt.id)}>
                                        <Check
                                            className={`mr-2 h-4 w-4 ${isSelected ? 'opacity-100' : 'opacity-0'
                                                }`}
                                        />
                                        {opt.label}
                                    </CommandItem>
                                );
                            })}
                        </CommandGroup>
                    </Command>
                </PopoverContent>
            </Popover>
            {error && <p className="text-red-600">{error}</p>}
        </div>
    );
}
