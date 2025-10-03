import { useEffect } from 'react'
import { Head, Link, useForm, usePage } from '@inertiajs/react'
import { toast } from 'sonner'
import { ArrowLeft, Check, ChevronsUpDown } from 'lucide-react'

import AppLayout from '@/layouts/app-layout'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
import {
    Select,
    SelectTrigger,
    SelectValue,
    SelectContent,
    SelectGroup,
    SelectLabel,
    SelectItem,
} from '@/components/ui/select'
import { Popover, PopoverTrigger, PopoverContent } from '@/components/ui/popover'
import {
    Command,
    CommandInput,
    CommandEmpty,
    CommandGroup,
    CommandItem,
} from '@/components/ui/command'

import {
    index as motherboardIndex,
    store as motherboardStore,
} from '@/routes/motherboards'

interface Option {
    id: number
    label: string
}

type PageProps = {
    ramOptions: Option[]
    diskOptions: Option[]
    processorOptions: Option[]
    flash?: { message?: string; type?: string }
}

// pre-defined choices for your six shadcn selects
const memoryTypes = ['DDR3', 'DDR4', 'DDR5']
const ramSlotOptions = [1, 2, 4, 6, 8]
const m2SlotOptions = [0, 1, 2, 3, 4]
const sataPortOptions = [0, 2, 4, 6, 8]
const maxRamCapacityOptions = [16, 32, 64, 128, 256]
const ethernetSpeedOptions = ['1GbE', '2.5GbE', '5GbE', '10GbE']

export default function Create() {
    const { ramOptions, diskOptions, processorOptions, flash } =
        usePage<PageProps>().props

    const form = useForm({
        brand: '',
        model: '',
        chipset: '',
        form_factor: '',
        memory_type: '',
        ram_slots: 0,
        max_ram_capacity_gb: 0,
        max_ram_speed: '',
        pcie_slots: '',
        m2_slots: 0,
        sata_ports: 0,
        usb_ports: '',
        ethernet_speed: '',
        wifi: false,
        ram_spec_ids: [] as number[],
        disk_spec_ids: [] as number[],
        processor_spec_ids: [] as number[],
    })

    useEffect(() => {
        if (!flash?.message) return;
        if (flash.type === "error") {
            toast.error(flash.message);
        } else {
            toast.success(flash.message);
        }
    }, [flash?.message, flash?.type]);

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault()
        form.post(motherboardStore.url())
    }

    function toggleSelect(field: keyof typeof form.data, id: number) {
        const list = form.data[field] as number[]
        const next = list.includes(id) ? list.filter(i => i !== id) : [...list, id]
        form.setData(field, next)
    }

    return (
        <AppLayout>
            <Head title="Create Motherboard" />

            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3 ">
                <div className="flex justify-start">
                    <Link href={motherboardIndex.url()}>
                        <Button><ArrowLeft /> Return</Button>
                    </Link>
                </div>


                <form onSubmit={handleSubmit} className="grid grid-cols-2 gap-6">
                    {/* Brand */}
                    <div>
                        <Label htmlFor="brand">Brand</Label>
                        <Input
                            id="brand"
                            name="brand"
                            value={form.data.brand}
                            onChange={(e) => form.setData('brand', e.target.value)}
                        />
                        {form.errors.brand && (
                            <p className="text-red-600">{form.errors.brand}</p>
                        )}
                    </div>

                    {/* Model */}
                    <div>
                        <Label htmlFor="model">Model</Label>
                        <Input
                            id="model"
                            name="model"
                            value={form.data.model}
                            onChange={(e) => form.setData('model', e.target.value)}
                        />
                        {form.errors.model && (
                            <p className="text-red-600">{form.errors.model}</p>
                        )}
                    </div>

                    {/* Chipset */}
                    <div>
                        <Label htmlFor="chipset">Chipset</Label>
                        <Input
                            id="chipset"
                            name="chipset"
                            value={form.data.chipset}
                            onChange={(e) => form.setData('chipset', e.target.value)}
                        />
                        {form.errors.chipset && (
                            <p className="text-red-600">{form.errors.chipset}</p>
                        )}
                    </div>

                    {/* Form Factor */}
                    <div>
                        <Label htmlFor="form_factor">Form Factor</Label>
                        <Input
                            id="form_factor"
                            name="form_factor"
                            value={form.data.form_factor}
                            onChange={(e) => form.setData('form_factor', e.target.value)}
                        />
                        {form.errors.form_factor && (
                            <p className="text-red-600">{form.errors.form_factor}</p>
                        )}
                    </div>

                    {/* Memory Type */}
                    <div>
                        <Label htmlFor="memory_type">Memory Type</Label>
                        <Select
                            value={form.data.memory_type}
                            onValueChange={val => form.setData('memory_type', val)}
                        >
                            <SelectTrigger id="memory_type" className="w-full">
                                <SelectValue placeholder="Select memory type" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    <SelectLabel>Type</SelectLabel>
                                    {memoryTypes.map(type => (
                                        <SelectItem key={type} value={type}>
                                            {type}
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                        {form.errors.memory_type && (
                            <p className="text-red-600">{form.errors.memory_type}</p>
                        )}
                    </div>


                    {/* RAM Slots */}
                    <div>
                        <Label htmlFor="ram_slots">RAM Slots</Label>
                        <Select
                            value={String(form.data.ram_slots)}
                            onValueChange={val => form.setData('ram_slots', Number(val))}
                        >
                            <SelectTrigger id="ram_slots" className="w-full">
                                <SelectValue placeholder="Select # of slots" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    <SelectLabel>Slots</SelectLabel>
                                    {ramSlotOptions.map(n => (
                                        <SelectItem key={n} value={String(n)}>
                                            {n}
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                        {form.errors.ram_slots && <p className="text-red-600">{form.errors.ram_slots}</p>}
                    </div>


                    {/* Max RAM Capacity */}
                    <div>
                        <Label htmlFor="max_ram_capacity_gb">Max RAM Capacity</Label>
                        <Select
                            value={String(form.data.max_ram_capacity_gb)}
                            onValueChange={val => form.setData('max_ram_capacity_gb', Number(val))}
                        >
                            <SelectTrigger id="max_ram_capacity_gb" className="w-full">
                                <SelectValue placeholder="Select capacity" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    <SelectLabel>Capacity</SelectLabel>
                                    {maxRamCapacityOptions.map(cap => (
                                        <SelectItem key={cap} value={String(cap)}>
                                            {cap} GB
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                        {form.errors.max_ram_capacity_gb && (
                            <p className="text-red-600">{form.errors.max_ram_capacity_gb}</p>
                        )}
                    </div>

                    {/* Max RAM Speed */}
                    <div>
                        <Label htmlFor="max_ram_speed">Max RAM Speed</Label>
                        <Input
                            id="max_ram_speed"
                            name="max_ram_speed"
                            value={form.data.max_ram_speed}
                            onChange={(e) => form.setData('max_ram_speed', e.target.value)}
                        />
                        {form.errors.max_ram_speed && (
                            <p className="text-red-600">{form.errors.max_ram_speed}</p>
                        )}
                    </div>

                    {/* PCIe Slots */}
                    <div>
                        <Label htmlFor="pcie_slots">PCIe Slots</Label>
                        <Input
                            id="pcie_slots"
                            name="pcie_slots"
                            value={form.data.pcie_slots}
                            onChange={(e) => form.setData('pcie_slots', e.target.value)}
                        />
                        {form.errors.pcie_slots && (
                            <p className="text-red-600">{form.errors.pcie_slots}</p>
                        )}
                    </div>

                    {/* M.2 Slots */}
                    <div>
                        <Label htmlFor="m2_slots">M.2 Slots</Label>
                        <Select
                            value={String(form.data.m2_slots)}
                            onValueChange={val => form.setData('m2_slots', Number(val))}
                        >
                            <SelectTrigger id="m2_slots" className="w-full">
                                <SelectValue placeholder="Select M.2 slots" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    <SelectLabel>M.2</SelectLabel>
                                    {m2SlotOptions.map(n => (
                                        <SelectItem key={n} value={String(n)}>
                                            {n}
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                        {form.errors.m2_slots && <p className="text-red-600">{form.errors.m2_slots}</p>}
                    </div>


                    {/* SATA Ports */}
                    <div>
                        <Label htmlFor="sata_ports">SATA Ports</Label>
                        <Select
                            value={String(form.data.sata_ports)}
                            onValueChange={val => form.setData('sata_ports', Number(val))}
                        >
                            <SelectTrigger id="sata_ports" className="w-full">
                                <SelectValue placeholder="Select SATA ports" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    <SelectLabel>SATA</SelectLabel>
                                    {sataPortOptions.map(n => (
                                        <SelectItem key={n} value={String(n)}>
                                            {n}
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                        {form.errors.sata_ports && <p className="text-red-600">{form.errors.sata_ports}</p>}
                    </div>


                    {/* USB Ports */}
                    <div>
                        <Label htmlFor="usb_ports">USB Ports</Label>
                        <Input
                            id="usb_ports"
                            name="usb_ports"
                            value={form.data.usb_ports}
                            onChange={(e) => form.setData('usb_ports', e.target.value)}
                        />
                        {form.errors.usb_ports && (
                            <p className="text-red-600">{form.errors.usb_ports}</p>
                        )}
                    </div>

                    {/* Ethernet Speed */}
                    <div>
                        <Label htmlFor="ethernet_speed">Ethernet Speed</Label>
                        <Select
                            value={form.data.ethernet_speed}
                            onValueChange={val => form.setData('ethernet_speed', val)}
                        >
                            <SelectTrigger id="ethernet_speed" className="w-full">
                                <SelectValue placeholder="Select speed" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    <SelectLabel>Speed</SelectLabel>
                                    {ethernetSpeedOptions.map(speed => (
                                        <SelectItem key={speed} value={speed}>
                                            {speed}
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                        {form.errors.ethernet_speed && (
                            <p className="text-red-600">{form.errors.ethernet_speed}</p>
                        )}
                    </div>

                    {/* Wi-Fi */}
                    <div className="flex items-center space-x-2">
                        <Input
                            id="wifi"
                            name="wifi"
                            type="checkbox"
                            checked={form.data.wifi}
                            onChange={(e) => form.setData('wifi', e.target.checked)}
                        />
                        <Label htmlFor="wifi">Wi-Fi Enabled</Label>
                        {form.errors.wifi && (
                            <p className="text-red-600">{form.errors.wifi}</p>
                        )}
                    </div>

                    {/* RAM Modules multi-select */}
                    <MultiPopover
                        id="ram_spec_ids"
                        label="RAM Modules"
                        options={ramOptions}
                        selected={form.data.ram_spec_ids}
                        onToggle={id => toggleSelect('ram_spec_ids', id)}
                        error={form.errors.ram_spec_ids}
                    />

                    {/* Disk Drives multi-select */}
                    <MultiPopover
                        id="disk_spec_ids"
                        label="Disk Drives"
                        options={diskOptions}
                        selected={form.data.disk_spec_ids}
                        onToggle={id => toggleSelect('disk_spec_ids', id)}
                        error={form.errors.disk_spec_ids}
                    />

                    {/* Processors multi-select */}
                    <MultiPopover
                        id="processor_spec_ids"
                        label="Processors"
                        options={processorOptions}
                        selected={form.data.processor_spec_ids}
                        onToggle={id => toggleSelect('processor_spec_ids', id)}
                        error={form.errors.processor_spec_ids}
                    />

                    <div className="col-span-2 flex justify-end">
                        <Button type="submit">Create Motherboard</Button>
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
