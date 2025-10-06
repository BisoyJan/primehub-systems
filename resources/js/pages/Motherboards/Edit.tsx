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
    stock_quantity?: number;
}

interface Motherboard {
    id: number;
    brand: string;
    model: string;
    chipset: string;
    form_factor: string;
    socket_type: string;
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

interface ProcessorOption extends Option {
    socket_type: string;
}

interface RamOption extends Option {
    type: string; // DDR3, DDR4, DDR5
}

interface Props {
    motherboard: Motherboard;
    ramOptions: RamOption[];
    diskOptions: Option[];
    processorOptions: ProcessorOption[];
    flash?: { message?: string; type?: string };
}

export default function Edit({ motherboard, ramOptions, diskOptions, processorOptions, flash }: Props) {
    const { data, setData, put, errors } = useForm({
        brand: motherboard.brand ?? '',
        model: motherboard.model ?? '',
        chipset: motherboard.chipset ?? '',
        form_factor: motherboard.form_factor ?? '',
        socket_type: motherboard.socket_type ?? '',
        memory_type: motherboard.memory_type ?? '',
        ram_slots: motherboard.ram_slots ?? 0,
        max_ram_capacity_gb: motherboard.max_ram_capacity_gb ?? 0,
        max_ram_speed: motherboard.max_ram_speed ?? '',
        pcie_slots: motherboard.pcie_slots ?? '',
        m2_slots: motherboard.m2_slots ?? 0,
        sata_ports: motherboard.sata_ports ?? 0,
        usb_ports: motherboard.usb_ports ?? '',
        ethernet_speed: motherboard.ethernet_speed ?? '',
        wifi: motherboard.wifi ?? false,

        ram_spec_ids: (motherboard.ramSpecs ?? []).map(r => Number(r)),
        disk_spec_ids: (motherboard.diskSpecs ?? []).map(d => Number(d)),
        processor_spec_ids: (motherboard.processorSpecs ?? []).map(p => Number(p)),
    });

    //Filter processors by selected motherboard socket_type
    const compatibleProcessors = (processorOptions ?? []).filter(
        (p) => p.socket_type === data.socket_type
    );

    const compatibleRams = (ramOptions ?? []).filter(
        (r) => r.type === data.memory_type // or data.memory_type in Edit
    );

    useEffect(() => {
        if (!flash?.message) return;
        if (flash.type === "error") {
            toast.error(flash.message);
        } else {
            toast.success(flash.message);
        }
    }, [flash?.message, flash?.type]);

    useEffect(() => {
        const compatible = (processorOptions ?? []).filter(
            (p) => p.socket_type === data.socket_type
        )

        const stillValid = (data.processor_spec_ids ?? []).filter((id) =>
            compatible.some((p) => p.id === id)
        )

        if (stillValid.length !== data.processor_spec_ids.length) {
            setData('processor_spec_ids', stillValid)
        }
    }, [data.socket_type, processorOptions, data.processor_spec_ids, setData])

    useEffect(() => {
        const compatible = (ramOptions ?? []).filter(
            (r) => r.type === data.memory_type
        )

        const stillValid = (data.ram_spec_ids ?? []).filter((id) =>
            compatible.some((p) => p.id === id)
        )

        if (stillValid.length !== data.ram_spec_ids.length) {
            setData('ram_spec_ids', stillValid)
        }
    }, [data.memory_type, ramOptions, data.ram_spec_ids, setData])


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

            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3 w-full md:w-10/12 lg:w-8/12 mx-auto">
                <div className="flex justify-start">
                    <Link href={motherboardIndex.url()}>
                        <Button><ArrowLeft /> Return</Button>
                    </Link>
                </div>

                <form onSubmit={handleUpdate} className="space-y-8">
                    {/* Core Info */}
                    <section>
                        <h2 className="text-lg font-semibold mb-2">Core Info</h2>
                        <div className="grid grid-cols-2 gap-6">
                            {/* Brand */}
                            <div>
                                <Label htmlFor="brand">Brand</Label>
                                <Input
                                    id="brand"
                                    name="brand"
                                    value={data.brand}
                                    onChange={e => setData("brand", e.target.value)}
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
                                    onChange={e => setData("model", e.target.value)}
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
                                    onChange={e => setData("chipset", e.target.value)}
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
                                    onChange={e => setData("form_factor", e.target.value)}
                                />
                                {errors.form_factor && <p className="text-red-600">{errors.form_factor}</p>}
                            </div>

                            {/* Socket Type */}
                            <div>
                                <Label htmlFor="socket_type">Socket Type</Label>
                                <Select
                                    onValueChange={(val) => setData("socket_type", val)}
                                    value={data.socket_type}
                                >
                                    <SelectTrigger id="socket_type" className="w-full">
                                        <SelectValue placeholder="Select socket type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {["LGA1151", "LGA1200", "LGA1700", "AM3+", "AM4", "AM5", "TR4", "sTRX4"].map(socket => (
                                            <SelectItem key={socket} value={socket}>{socket}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.socket_type && <p className="text-red-600">{errors.socket_type}</p>}
                            </div>
                        </div>
                    </section>

                    {/* Memory */}
                    <section>
                        <h2 className="text-lg font-semibold mb-2">Memory</h2>
                        <div className="grid grid-cols-2 gap-6">
                            {/* Memory Type */}
                            <div>
                                <Label htmlFor="memory_type">Memory Type</Label>
                                <Select
                                    onValueChange={(val) => setData("memory_type", val)}
                                    value={data.memory_type}
                                >
                                    <SelectTrigger id="memory_type" className="w-full">
                                        <SelectValue placeholder="Select memory type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {memoryTypes.map(type => (
                                            <SelectItem key={type} value={type}>{type}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.memory_type && <p className="text-red-600">{errors.memory_type}</p>}
                            </div>

                            {/* RAM Slots */}
                            <div>
                                <Label htmlFor="ram_slots">RAM Slots</Label>
                                <Select
                                    onValueChange={(val) => setData("ram_slots", Number(val))}
                                    value={String(data.ram_slots)}
                                >
                                    <SelectTrigger id="ram_slots" className="w-full">
                                        <SelectValue placeholder="Select # of slots" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {ramSlotOptions.map(n => (
                                            <SelectItem key={n} value={String(n)}>{n}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.ram_slots && <p className="text-red-600">{errors.ram_slots}</p>}
                            </div>

                            {/* Max RAM Capacity */}
                            <div>
                                <Label htmlFor="max_ram_capacity_gb">Max RAM Capacity (GB)</Label>
                                <Select
                                    onValueChange={(val) => setData("max_ram_capacity_gb", Number(val))}
                                    value={String(data.max_ram_capacity_gb)}
                                >
                                    <SelectTrigger id="max_ram_capacity_gb" className="w-full">
                                        <SelectValue placeholder="Select capacity" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {maxRamCapacityOptions.map(cap => (
                                            <SelectItem key={cap} value={String(cap)}>{cap} GB</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.max_ram_capacity_gb && <p className="text-red-600">{errors.max_ram_capacity_gb}</p>}
                            </div>

                            {/* Max RAM Speed */}
                            <div>
                                <Label htmlFor="max_ram_speed">Max RAM Speed</Label>
                                <Input
                                    id="max_ram_speed"
                                    name="max_ram_speed"
                                    value={data.max_ram_speed}
                                    onChange={e => setData("max_ram_speed", e.target.value)}
                                />
                                {errors.max_ram_speed && <p className="text-red-600">{errors.max_ram_speed}</p>}
                            </div>
                        </div>
                    </section>

                    {/* Expansion & Storage */}
                    <section>
                        <h2 className="text-lg font-semibold mb-2">Expansion & Storage</h2>
                        <div className="grid grid-cols-2 gap-6">
                            {/* PCIe Slots */}
                            <div>
                                <Label htmlFor="pcie_slots">PCIe Slots</Label>
                                <Input
                                    id="pcie_slots"
                                    name="pcie_slots"
                                    value={data.pcie_slots}
                                    onChange={e => setData("pcie_slots", e.target.value)}
                                />
                                {errors.pcie_slots && <p className="text-red-600">{errors.pcie_slots}</p>}
                            </div>

                            {/* M.2 Slots */}
                            <div>
                                <Label htmlFor="m2_slots">M.2 Slots</Label>
                                <Select
                                    onValueChange={(val) => setData("m2_slots", Number(val))}
                                    value={String(data.m2_slots)}
                                >
                                    <SelectTrigger id="m2_slots" className="w-full">
                                        <SelectValue placeholder="Select M.2 slots" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {m2SlotOptions.map(n => (
                                            <SelectItem key={n} value={String(n)}>{n}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.m2_slots && <p className="text-red-600">{errors.m2_slots}</p>}
                            </div>

                            {/* SATA Ports */}
                            <div>
                                <Label htmlFor="sata_ports">SATA Ports</Label>
                                <Select
                                    onValueChange={(val) => setData("sata_ports", Number(val))}
                                    value={String(data.sata_ports)}
                                >
                                    <SelectTrigger id="sata_ports" className="w-full">
                                        <SelectValue placeholder="Select SATA ports" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {sataPortOptions.map(n => (
                                            <SelectItem key={n} value={String(n)}>{n}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.sata_ports && <p className="text-red-600">{errors.sata_ports}</p>}
                            </div>

                            {/* USB Ports */}
                            <div>
                                <Label htmlFor="usb_ports">USB Ports</Label>
                                <Input
                                    id="usb_ports"
                                    name="usb_ports"
                                    value={data.usb_ports}
                                    onChange={e => setData("usb_ports", e.target.value)}
                                />
                                {errors.usb_ports && <p className="text-red-600">{errors.usb_ports}</p>}
                            </div>
                        </div>
                    </section>

                    {/* Connectivity */}
                    <section>
                        <h2 className="text-lg font-semibold mb-2">Connectivity</h2>
                        <div className="grid grid-cols-2 gap-6">
                            {/* Ethernet Speed */}
                            <div>
                                <Label htmlFor="ethernet_speed">Ethernet Speed</Label>
                                <Select
                                    value={data.ethernet_speed}
                                    onValueChange={val => setData("ethernet_speed", val)}
                                >
                                    <SelectTrigger id="ethernet_speed" className="w-full">
                                        <SelectValue placeholder="Select speed" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectGroup>
                                            <SelectLabel>Speed</SelectLabel>
                                            {ethernetSpeedOptions.map((s) => (
                                                <SelectItem key={s} value={s}>
                                                    {s}
                                                </SelectItem>
                                            ))}
                                            {/* If DB value is custom and not in list, still show it */}
                                            {data.ethernet_speed &&
                                                !ethernetSpeedOptions.includes(data.ethernet_speed) && (
                                                    <SelectItem
                                                        key={"custom-" + data.ethernet_speed}
                                                        value={data.ethernet_speed}
                                                    >
                                                        {data.ethernet_speed}
                                                    </SelectItem>
                                                )}
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
                                    onChange={(e) => setData("wifi", e.target.checked)}
                                />
                                <Label htmlFor="wifi">Wi-Fi Enabled</Label>
                                {errors.wifi && <p className="text-red-600">{errors.wifi}</p>}
                            </div>
                        </div>
                    </section>

                    {/* Compatibility */}
                    <section>
                        <h2 className="text-lg font-semibold mb-2">Compatibility</h2>

                        {/* Processors */}
                        {!data.socket_type ? (
                            <p className="text-gray-500">
                                Select a socket type first to see compatible processors.
                            </p>
                        ) : (
                            <MultiPopover
                                id="processor_spec_ids"
                                label="Processors"
                                options={compatibleProcessors}
                                selected={(data.processor_spec_ids as number[]) ?? []}
                                onToggle={(id) => toggleSelect("processor_spec_ids", Number(id))}
                                error={errors.processor_spec_ids}
                            />
                        )}

                        {/* RAM */}
                        {!data.memory_type ? (
                            <p className="text-gray-500">
                                Select a memory type first to see compatible RAM modules.
                            </p>
                        ) : (
                            <MultiPopover
                                id="ram_spec_ids"
                                label="RAM Modules"
                                options={compatibleRams}
                                selected={(data.ram_spec_ids as number[]) ?? []}
                                onToggle={(id) => toggleSelect("ram_spec_ids", Number(id))}
                                error={errors.ram_spec_ids}
                            />
                        )}

                        {/* Disks */}
                        <MultiPopover
                            id="disk_spec_ids"
                            label="Disk Drives"
                            options={diskOptions ?? []}
                            selected={(data.disk_spec_ids as number[]) ?? []}
                            onToggle={(id) => toggleSelect("disk_spec_ids", Number(id))}
                            error={errors.disk_spec_ids}
                        />
                    </section>

                    {/* Submit */}
                    <div className="flex justify-end">
                        <Button type="submit">Update Motherboard</Button>
                    </div>
                </form>
            </div>
        </AppLayout>

    );
}

/* #TODO need to create MultiPopover component for ramSpecs, diskSpecs, processorSpecs to show the already selected options when editing a motherboard. */
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

    // safe computation of selected labels (handles string/number mismatches and undefined)
    const selectedLabels = (options ?? [])
        .filter(o => (selected ?? []).map(Number).includes(Number(o.id)))
        .map(o => o.label);

    return (

        <div className="col-span-2">
            <Label htmlFor={id}>{label}</Label>
            <Popover>
                <PopoverTrigger asChild>
                    <Button variant="outline" className="w-full justify-between" id={id}>
                        {selectedLabels.length
                            ? selectedLabels.join(', ')
                            : `Select ${label.toLowerCase()}…`}
                        <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent className="w-full p-0">
                    <Command>
                        <CommandInput placeholder={`Search ${label.toLowerCase()}…`} />
                        <CommandEmpty>No {label.toLowerCase()} found.</CommandEmpty>
                        <CommandGroup>
                            {(options ?? []).map(opt => {
                                const isSelected = (selected ?? []).map(Number).includes(Number(opt.id));
                                const outOfStock = (opt.stock_quantity ?? 0) < 1; // 👈 check stock

                                return (
                                    <CommandItem
                                        key={opt.id}
                                        onSelect={() => !outOfStock && onToggle(Number(opt.id))} // 👈 prevent toggle if 0
                                        disabled={outOfStock} // 👈 disable item
                                        className={outOfStock ? "opacity-50 cursor-not-allowed" : ""}
                                    >
                                        <Check className={`mr-2 h-4 w-4 ${isSelected ? 'opacity-100' : 'opacity-0'}`} />
                                        <span className="flex-1">{opt.label}</span>
                                        <span className="text-xs text-gray-500 ml-2">
                                            ({opt.stock_quantity ?? 0} in stock)
                                        </span>
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
