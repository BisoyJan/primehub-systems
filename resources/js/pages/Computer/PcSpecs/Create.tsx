import { useEffect, useMemo, useState } from 'react'
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

import { PageHeader } from '@/components/PageHeader'
import { LoadingOverlay } from '@/components/LoadingOverlay'
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks'

import {
    index as pcSpecIndex,
    store as pcSpecStore,
    create as pcSpecCreate,
} from '@/routes/pcspecs'

interface Option {
    id: number
    label: string
    stock_quantity?: number
    capacity_gb?: number
    interface?: string
}

interface ProcessorOption extends Option {
    socket_type: string
}

interface RamOption extends Option {
    type: string
    capacity_gb?: number
}

type PageProps = {
    ramOptions: RamOption[]
    diskOptions: Option[]
    processorOptions: ProcessorOption[]
}

const memoryTypes = ['DDR3', 'DDR4', 'DDR5']
const ramSlotOptions = [1, 2, 4, 6, 8]
const m2SlotOptions = [1, 2, 3, 4]
const sataPortOptions = [2, 4, 6, 8]
const maxRamCapacityOptions = [16, 32, 64, 128, 256]
const formFactorOptions = ['ATX', 'Micro-ATX', 'Mini-ITX']
//const ethernetSpeedOptions = ['1GbE', '2.5GbE', '5GbE', '10GbE']

export default function Create() {
    useFlashMessage()

    const { ramOptions, diskOptions, processorOptions } = usePage<PageProps>().props

    const { title, breadcrumbs } = usePageMeta({
        title: 'Create PC Specification',
        breadcrumbs: [
            { title: 'PC Specifications', href: pcSpecIndex().url },
            { title: 'Create', href: pcSpecCreate().url },
        ],
    })

    const isPageLoading = usePageLoading()

    const form = useForm({
        pc_number: '',
        manufacturer: '',
        model: '',
        form_factor: '',
        memory_type: '',
        ram_slots: 0,
        max_ram_capacity_gb: 0,
        max_ram_speed: '',
        m2_slots: 0,
        sata_ports: 0,
        ram_mode: 'different' as 'same' | 'different',
        ram_specs: {} as Record<number, number>, // { ramId: qty }
        disk_mode: 'different' as 'same' | 'different',
        disk_specs: {} as Record<number, number>, // { diskId: qty }
        processor_spec_id: 0, // Changed from processor_spec_ids array to single value
        quantity: 1, // Add this field for creating multiple identical PCs
    })

    const [localError, setLocalError] = useState<string | null>(null)

    const compatibleRams = (ramOptions ?? []).filter(
        (r) => r.type === form.data.memory_type
    )

    // derived helpers
    const totalRamSelected = useMemo(
        () => Object.values(form.data.ram_specs || {}).reduce((s, v) => s + Number(v || 0), 0),
        [form.data.ram_specs]
    )

    const remainingRamSlots = form.data.ram_slots - totalRamSelected

    // auto-clear incompatible RAM when memory_type changes
    useEffect(() => {
        const compatible = (ramOptions ?? []).filter((r) => r.type === form.data.memory_type)
        const stillValidIds = Object.keys(form.data.ram_specs ?? {})
            .map(Number)
            .filter((id) => compatible.some((r) => r.id === id))

        // rebuild ram_specs keeping only compatible ids
        if (stillValidIds.length !== Object.keys(form.data.ram_specs ?? {}).length) {
            const next: Record<number, number> = {}
            stillValidIds.forEach((id) => {
                next[id] = form.data.ram_specs[id] ?? 1
            })
            form.setData('ram_specs', next)
        }
    }, [form.data.memory_type, ramOptions, form])

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault()
        setLocalError(null)

        // Check for RAM slots limit
        if (form.data.ram_slots > 0 && totalRamSelected > form.data.ram_slots) {
            toast.error(`You selected ${totalRamSelected} RAM sticks, but the motherboard only supports ${form.data.ram_slots} slots.`);
            return;
        }

        // Verify if there's enough stock for multiple PCs
        const quantity = form.data.quantity || 1;
        if (quantity > 1) {
            // Check RAM stock
            for (const [idStr, qty] of Object.entries(form.data.ram_specs)) {
                const id = Number(idStr);
                const ram = ramOptions.find(r => r.id === id);
                if (ram) {
                    const totalNeeded = Number(qty) * quantity;
                    if ((ram.stock_quantity || 0) < totalNeeded) {
                        toast.error(`Not enough stock for RAM ${ram.label}: need ${totalNeeded} for ${quantity} PCs, but only have ${ram.stock_quantity || 0} available.`);
                        return;
                    }
                }
            }

            // Check Disk stock
            for (const [idStr, qty] of Object.entries(form.data.disk_specs)) {
                const id = Number(idStr);
                const disk = diskOptions.find(d => d.id === id);
                if (disk) {
                    const totalNeeded = Number(qty) * quantity;
                    if ((disk.stock_quantity || 0) < totalNeeded) {
                        toast.error(`Not enough stock for Disk ${disk.label}: need ${totalNeeded} for ${quantity} PCs, but only have ${disk.stock_quantity || 0} available.`);
                        return;
                    }
                }
            }

            // Check Processor stock (now for single processor)
            if (form.data.processor_spec_id) {
                const proc = processorOptions.find(p => p.id === form.data.processor_spec_id);
                if (proc) {
                    if ((proc.stock_quantity || 0) < quantity) {
                        toast.error(`Not enough stock for Processor ${proc.label}: need ${quantity} units, but only have ${proc.stock_quantity || 0} available.`);
                        return;
                    }
                }
            }
        }

        form.post(pcSpecStore().url)
    }

    useEffect(() => {
        if (form.data.ram_mode !== 'same') return;
        const selectedId = Number(Object.keys(form.data.ram_specs || [])[0]);
        if (!selectedId) return;
        const opt = ramOptions.find(r => r.id === selectedId);
        const capPer = Number(opt?.capacity_gb ?? NaN);
        const qty = Number(form.data.ram_slots || 1);
        const maxCap = Number(form.data.max_ram_capacity_gb || 0);
        if (!Number.isNaN(capPer) && maxCap > 0 && (capPer * qty) > maxCap) {
            toast.error(`Current selection (${opt?.label}) now exceeds motherboard max capacity (${capPer * qty} GB > ${maxCap} GB)`);
            // optionally clear selection: form.setData('ram_specs', {})
        }
    }, [form.data.ram_mode, form.data.ram_slots, form.data.max_ram_capacity_gb, form.data.ram_specs, ramOptions]);

    // RAM helpers (same vs different)
    function setRamSameSelection(ramId?: number) {
        if (!ramId) return;
        const qty = Number(form.data.ram_slots || 1);
        const opt = ramOptions.find((r) => r.id === ramId);
        const capacityPerModule = Number(opt?.capacity_gb ?? NaN);
        const maxCap = Number(form.data.max_ram_capacity_gb || 0);
        const totalCap = Number.isNaN(capacityPerModule) ? NaN : capacityPerModule * qty;

        // if capacity unknown treat as unsafe and surface an error to user
        if (Number.isNaN(capacityPerModule)) {
            toast.error('Selected RAM is missing capacity information; server will validate on save.');
            return;
        }

        if (maxCap > 0 && totalCap > maxCap) {
            toast.error(
                `Cannot select ${opt?.label ?? `RAM #${ramId}`} for ${qty} slots: ${totalCap} GB exceeds motherboard max of ${maxCap} GB`
            );
            return;
        }

        const available = Number(opt?.stock_quantity ?? 0);
        if (available < qty) {
            toast.error(`Not enough stock for ${opt?.label ?? `RAM #${ramId}`} (requested ${qty}, available ${available})`);
            return;
        }

        // pass selection (qty = ram_slots)
        form.setData('ram_specs', { [ramId]: qty });
    }

    function toggleRamSelection(id: number) {
        const next = { ...(form.data.ram_specs || {}) }
        if (next[id]) delete next[id]
        else next[id] = 1
        form.setData('ram_specs', next)
    }

    // Disk helpers (same vs different)
    function setDiskSameSelection(diskId?: number) {
        if (!diskId) return
        // number of usable slots is the sum of m.2 slots and sata ports (treat both as "drive bays" for same-mode)
        const qty = Number(form.data.m2_slots || 0) + Number(form.data.sata_ports || 0)
        const finalQty = qty > 0 ? qty : 1 // fallback to 1 if no slots set yet

        const opt = diskOptions.find((d) => d.id === diskId)
        const available = Number(opt?.stock_quantity ?? 0)

        if (available < finalQty) {
            toast.error(`Not enough stock for ${opt?.label ?? `Disk #${diskId}`} (requested ${finalQty}, available ${available})`)
            return
        }

        // capacity/interface check is optional; show interface if present
        setLocalError(null)
        form.setData('disk_specs', { [diskId]: finalQty })
    }

    function toggleDiskSelection(id: number) {
        const next = { ...(form.data.disk_specs || {}) }
        if (next[id]) delete next[id]
        else next[id] = 1
        form.setData('disk_specs', next)
    }

    // Add reactive validation when quantity changes
    useEffect(() => {
        // Skip if quantity is 1 (single PC)
        if (form.data.quantity <= 1) return;

        // Validate stock levels when quantity changes (show warnings)
        const validateStockForQuantity = () => {
            const quantity = form.data.quantity || 1;
            let hasRamWarning = false;
            let hasDiskWarning = false;
            let hasProcWarning = false;
            const warningComponents: string[] = [];

            // Check each component type
            for (const [idStr, qty] of Object.entries(form.data.ram_specs)) {
                const id = Number(idStr);
                const ram = ramOptions.find(r => r.id === id);
                if (ram) {
                    const totalNeeded = Number(qty) * quantity;
                    if ((ram.stock_quantity || 0) < totalNeeded) {
                        hasRamWarning = true;
                    }
                }
            }

            for (const [idStr, qty] of Object.entries(form.data.disk_specs)) {
                const id = Number(idStr);
                const disk = diskOptions.find(d => d.id === id);
                if (disk) {
                    const totalNeeded = Number(qty) * quantity;
                    if ((disk.stock_quantity || 0) < totalNeeded) {
                        hasDiskWarning = true;
                    }
                }
            }

            // Check processor (now single)
            if (form.data.processor_spec_id) {
                const proc = processorOptions.find(p => p.id === form.data.processor_spec_id);
                if (proc && (proc.stock_quantity || 0) < quantity) {
                    hasProcWarning = true;
                }
            }

            // Build the list of component types that have warnings
            if (hasRamWarning) warningComponents.push("RAM");
            if (hasDiskWarning) warningComponents.push("Disk");
            if (hasProcWarning) warningComponents.push("Processor");

            if (warningComponents.length > 0) {
                setLocalError(`Warning: Not enough stock for ${warningComponents.join(", ")} to build ${quantity} identical PCs.`);
            } else {
                setLocalError(null);
            }
        };

        validateStockForQuantity();
    }, [form.data.quantity, form.data.ram_specs, form.data.disk_specs, form.data.processor_spec_id, ramOptions, diskOptions, processorOptions]);

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
                    description="Bundle motherboard capabilities with RAM, storage, and CPU selections."
                    actions={(
                        <Link href={pcSpecIndex().url}>
                            <Button variant="outline">
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back to list
                            </Button>
                        </Link>
                    )}
                />

                <form onSubmit={handleSubmit} className="space-y-8">
                    {/* Core Info */}
                    <section>
                        <h2 className="text-lg font-semibold mb-2">Core Info</h2>
                        <div className="grid grid-cols-2 gap-6">
                            <div>
                                <Label htmlFor="pc_number">PC Number (Optional)</Label>
                                <Input
                                    id="pc_number"
                                    name="pc_number"
                                    value={form.data.pc_number}
                                    onChange={(e) => form.setData('pc_number', e.target.value)}
                                    placeholder="e.g., PC-2025-001"
                                />
                                {form.errors.pc_number && <p className="text-red-600">{form.errors.pc_number}</p>}
                            </div>

                            <div>
                                <Label htmlFor="manufacturer">Manufacturer</Label>
                                <Input id="manufacturer" name="manufacturer" value={form.data.manufacturer} onChange={(e) => form.setData('manufacturer', e.target.value)} />
                                {form.errors.manufacturer && <p className="text-red-600">{form.errors.manufacturer}</p>}
                            </div>

                            <div>
                                <Label htmlFor="model">Model</Label>
                                <Input id="model" name="model" value={form.data.model} onChange={(e) => form.setData('model', e.target.value)} />
                                {form.errors.model && <p className="text-red-600">{form.errors.model}</p>}
                            </div>

                            <div>
                                <Label htmlFor="form_factor">Form Factor</Label>
                                <Select value={form.data.form_factor} onValueChange={(val) => form.setData('form_factor', val)}>
                                    <SelectTrigger id="form_factor" className="w-full"><SelectValue placeholder="Select form factor" /></SelectTrigger>
                                    <SelectContent>
                                        {formFactorOptions.map(ff => (
                                            <SelectItem key={ff} value={ff}>{ff}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {form.errors.form_factor && <p className="text-red-600">{form.errors.form_factor}</p>}
                            </div>

                            {/* Add quantity field */}
                            <div>
                                <Label htmlFor="quantity">Quantity to create</Label>
                                <div className="flex items-center gap-2">
                                    <Input
                                        id="quantity"
                                        name="quantity"
                                        type="number"
                                        min="1"
                                        max="100"
                                        value={form.data.quantity}
                                        onChange={(e) => form.setData('quantity', parseInt(e.target.value) || 1)}
                                        className={`w-full ${localError ? 'border-orange-500 focus:ring-orange-500' : ''}`}
                                    />
                                    <span className="text-sm text-gray-500 whitespace-nowrap">PCs with identical specs</span>
                                </div>
                                {form.errors.quantity && <p className="text-red-600">{form.errors.quantity}</p>}
                                {localError && <p className="text-orange-500 text-sm mt-1">{localError}</p>}
                            </div>
                        </div>
                    </section>

                    {/* Memory */}
                    <section>
                        <h2 className="text-lg font-semibold mb-2">Memory</h2>
                        <div className="grid grid-cols-2 gap-6">
                            <div>
                                <Label htmlFor="memory_type">Memory Type</Label>
                                <Select value={form.data.memory_type} onValueChange={(val) => form.setData('memory_type', val)}>
                                    <SelectTrigger id="memory_type" className="w-full"><SelectValue placeholder="Select memory type" /></SelectTrigger>
                                    <SelectContent>{memoryTypes.map(type => <SelectItem key={type} value={type}>{type}</SelectItem>)}</SelectContent>
                                </Select>
                                {form.errors.memory_type && <p className="text-red-600">{form.errors.memory_type}</p>}
                            </div>

                            <div>
                                <Label htmlFor="ram_slots">RAM Slots</Label>
                                <Select value={String(form.data.ram_slots)} onValueChange={(val) => form.setData('ram_slots', Number(val))}>
                                    <SelectTrigger id="ram_slots" className="w-full"><SelectValue placeholder="Select # of slots" /></SelectTrigger>
                                    <SelectContent>{ramSlotOptions.map(n => <SelectItem key={n} value={String(n)}>{n}</SelectItem>)}</SelectContent>
                                </Select>
                                {form.errors.ram_slots && <p className="text-red-600">{form.errors.ram_slots}</p>}
                            </div>

                            <div>
                                <Label htmlFor="max_ram_capacity_gb">Max RAM Capacity (GB)</Label>
                                <Select value={String(form.data.max_ram_capacity_gb)} onValueChange={(val) => form.setData('max_ram_capacity_gb', Number(val))}>
                                    <SelectTrigger id="max_ram_capacity_gb" className="w-full"><SelectValue placeholder="Select capacity" /></SelectTrigger>
                                    <SelectContent>{maxRamCapacityOptions.map(cap => <SelectItem key={cap} value={String(cap)}>{cap} GB</SelectItem>)}</SelectContent>
                                </Select>
                                {form.errors.max_ram_capacity_gb && <p className="text-red-600">{form.errors.max_ram_capacity_gb}</p>}
                            </div>

                            <div>
                                <Label htmlFor="max_ram_speed">Max RAM Speed</Label>
                                <Input id="max_ram_speed" name="max_ram_speed" value={form.data.max_ram_speed} onChange={(e) => form.setData('max_ram_speed', e.target.value)} />
                                {form.errors.max_ram_speed && <p className="text-red-600">{form.errors.max_ram_speed}</p>}
                            </div>
                        </div>

                        {/* same vs different toggle */}
                        <div className="mt-4 flex items-center gap-6">
                            <div className="flex items-center gap-2">
                                <input id="ram_mode_same" name="ram_mode" type="radio" className="h-4 w-4 cursor-pointer accent-primary" checked={form.data.ram_mode === 'same'} onChange={() => form.setData('ram_mode', 'same')} aria-label="Use same module for all slots" />
                                <Label htmlFor="ram_mode_same">Use same module for all slots</Label>
                            </div>
                            <div className="flex items-center gap-2">
                                <input id="ram_mode_diff" name="ram_mode" type="radio" className="h-4 w-4 cursor-pointer accent-primary" checked={form.data.ram_mode === 'different'} onChange={() => form.setData('ram_mode', 'different')} aria-label="Use different modules" />
                                <Label htmlFor="ram_mode_diff">Use different modules</Label>
                            </div>
                            <div className="ml-auto text-sm text-gray-600">
                                Slots remaining: <span className={remainingRamSlots === 0 ? 'font-semibold text-green-600' : 'font-semibold text-red-600'}>{remainingRamSlots}</span>
                            </div>
                        </div>

                        {/* RAM selection UIs */}
                        <div className="mt-4 grid grid-cols-1 gap-3">
                            {form.data.ram_mode === 'same' ? (
                                <>
                                    <Label>Choose module to fill all slots</Label>
                                    <Popover>
                                        <PopoverTrigger asChild>
                                            <Button variant="outline" className="w-full justify-between">
                                                {Object.keys(form.data.ram_specs).length
                                                    ? ramOptions.find(r => r.id === Number(Object.keys(form.data.ram_specs)[0]))?.label
                                                    : 'Select RAM module to fill slots…'}
                                                <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0" />
                                            </Button>
                                        </PopoverTrigger>
                                        <PopoverContent className="w-full p-0">
                                            <Command>
                                                <CommandInput placeholder="Search RAM…" />
                                                <CommandEmpty>No RAM found.</CommandEmpty>
                                                <CommandGroup>
                                                    {compatibleRams.map(opt => {
                                                        const outOfStock = (opt.stock_quantity ?? 0) < form.data.ram_slots
                                                        // also mark as incompatible if this module would exceed max capacity
                                                        const capPer = Number(opt.capacity_gb ?? 0)
                                                        const maxCap = Number(form.data.max_ram_capacity_gb || 0)
                                                        const totalCap = capPer * (form.data.ram_slots || 1)
                                                        const exceedsCapacity = maxCap > 0 && totalCap > maxCap

                                                        const disabled = outOfStock || exceedsCapacity

                                                        return (
                                                            <CommandItem
                                                                key={opt.id}
                                                                onSelect={() => !disabled && setRamSameSelection(opt.id)}
                                                                disabled={disabled}
                                                                className={disabled ? "opacity-50 cursor-not-allowed" : ""}
                                                            >
                                                                <Check className={`mr-2 h-4 w-4 ${Object.keys(form.data.ram_specs).map(Number).includes(opt.id) ? 'opacity-100' : 'opacity-0'}`} />
                                                                <span className="flex-1">{opt.label}</span>
                                                                <span className="text-xs text-gray-500 ml-2">({opt.stock_quantity ?? 0} in stock)</span>
                                                                {opt.capacity_gb !== undefined && (
                                                                    <span className="text-xs text-gray-500 ml-4">• {opt.capacity_gb} GB/module</span>
                                                                )}
                                                                {exceedsCapacity && (
                                                                    <span className="text-xs text-red-600 ml-3">• exceeds max capacity</span>
                                                                )}
                                                            </CommandItem>
                                                        )
                                                    })}
                                                </CommandGroup>
                                            </Command>
                                        </PopoverContent>
                                    </Popover>

                                    {/* Quantity input removed while in 'same' mode per your request */}
                                </>
                            ) : (
                                <>
                                    <Label>Choose modules and set per-module quantities</Label>
                                    <MultiPopover
                                        id="ram_specs"
                                        label="RAM Modules"
                                        options={compatibleRams}
                                        selected={Object.keys(form.data.ram_specs).map(Number)}
                                        onToggle={(id) => toggleRamSelection(id)}
                                        error={form.errors.ram_specs as string}
                                    />

                                    {/* selected items with qty inputs */}
                                    <div className="space-y-2 mt-2">
                                        {Object.entries(form.data.ram_specs || {}).map(([key, qty]) => {
                                            const id = Number(key)
                                            const opt = ramOptions.find(r => r.id === id)
                                            return (
                                                <div key={id} className="flex items-center gap-3">
                                                    <div className="flex-1 text-sm">{opt?.label ?? `RAM #${id}`}</div>
                                                    <Input type="number" value={qty} min={1} max={form.data.ram_slots || 1} onChange={(e) => {
                                                        const next = { ...(form.data.ram_specs || {}) }
                                                        next[id] = Number(e.target.value || 0)
                                                        form.setData('ram_specs', next)
                                                    }} className="w-24" />
                                                    <div className="text-xs text-gray-500">({opt?.stock_quantity ?? 0} in stock)</div>
                                                </div>
                                            )
                                        })}
                                    </div>
                                </>
                            )}
                        </div>
                        {localError && <p className="text-red-600 mt-2">{localError}</p>}
                    </section>

                    {/* Expansion & Storage */}
                    <section>
                        <h2 className="text-lg font-semibold mb-2">Expansion & Storage</h2>
                        <div className="grid grid-cols-2 gap-6">
                            <div>
                                <Label htmlFor="m2_slots">M.2 Slots</Label>
                                <Select value={String(form.data.m2_slots)} onValueChange={(val) => form.setData('m2_slots', Number(val))}>
                                    <SelectTrigger id="m2_slots" className="w-full"><SelectValue placeholder="Select M.2 slots" /></SelectTrigger>
                                    <SelectContent>{m2SlotOptions.map(n => <SelectItem key={n} value={String(n)}>{n}</SelectItem>)}</SelectContent>
                                </Select>
                                {form.errors.m2_slots && <p className="text-red-600">{form.errors.m2_slots}</p>}
                            </div>

                            <div>
                                <Label htmlFor="sata_ports">SATA Ports</Label>
                                <Select value={String(form.data.sata_ports)} onValueChange={(val) => form.setData('sata_ports', Number(val))}>
                                    <SelectTrigger id="sata_ports" className="w-full"><SelectValue placeholder="Select SATA ports" /></SelectTrigger>
                                    <SelectContent>{sataPortOptions.map(n => <SelectItem key={n} value={String(n)}>{n}</SelectItem>)}</SelectContent>
                                </Select>
                                {form.errors.sata_ports && <p className="text-red-600">{form.errors.sata_ports}</p>}
                            </div>
                        </div>

                        {/* disk same/different toggle */}
                        <div className="mt-4 flex items-center gap-6">
                            <div className="flex items-center gap-2">
                                <input id="disk_mode_same" name="disk_mode" type="radio" className="h-4 w-4 cursor-pointer accent-primary" checked={form.data.disk_mode === 'same'} onChange={() => form.setData('disk_mode', 'same')} aria-label="Use same drive for all slots" />
                                <Label htmlFor="disk_mode_same">Use same drive for all slots</Label>
                            </div>
                            <div className="flex items-center gap-2">
                                <input id="disk_mode_diff" name="disk_mode" type="radio" className="h-4 w-4 cursor-pointer accent-primary" checked={form.data.disk_mode === 'different'} onChange={() => form.setData('disk_mode', 'different')} aria-label="Use different drives" />
                                <Label htmlFor="disk_mode_diff">Use different drives</Label>
                            </div>
                        </div>

                        <div className="mt-3">
                            {form.data.disk_mode === 'same' ? (
                                <>
                                    <Label>Choose one disk to populate slots</Label>
                                    <Popover>
                                        <PopoverTrigger asChild>
                                            <Button variant="outline" className="w-full justify-between">
                                                {Object.keys(form.data.disk_specs).length
                                                    ? diskOptions.find(d => d.id === Number(Object.keys(form.data.disk_specs)[0]))?.label
                                                    : 'Select disk to fill available bays…'}
                                                <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0" />
                                            </Button>
                                        </PopoverTrigger>
                                        <PopoverContent className="w-full p-0">
                                            <Command>
                                                <CommandInput placeholder="Search disks…" />
                                                <CommandEmpty>No disks found.</CommandEmpty>
                                                <CommandGroup>
                                                    {diskOptions.map(opt => {
                                                        const outOfStock = (opt.stock_quantity ?? 0) < 1
                                                        // interface display if backend provided it
                                                        return (
                                                            <CommandItem key={opt.id} onSelect={() => !outOfStock && setDiskSameSelection(opt.id)} disabled={outOfStock} className={outOfStock ? "opacity-50 cursor-not-allowed" : ""}>
                                                                <Check className={`mr-2 h-4 w-4 ${Object.keys(form.data.disk_specs).map(Number).includes(opt.id) ? 'opacity-100' : 'opacity-0'}`} />
                                                                <span className="flex-1">{opt.label}</span>
                                                                {opt.interface && <span className="text-xs text-gray-500 ml-2">{opt.interface}</span>}
                                                                <span className="text-xs text-gray-500 ml-2">({opt.stock_quantity ?? 0} in stock)</span>
                                                            </CommandItem>
                                                        )
                                                    })}
                                                </CommandGroup>
                                            </Command>
                                        </PopoverContent>
                                    </Popover>

                                    {/* Show chosen disk interface and assigned qty (derived from SATA + M.2 slots) */}
                                    {Object.keys(form.data.disk_specs).length > 0 && (() => {
                                        const id = Number(Object.keys(form.data.disk_specs)[0])
                                        const opt = diskOptions.find(d => d.id === id)
                                        const qty = form.data.disk_specs[id] ?? (form.data.m2_slots + form.data.sata_ports || 1)
                                        return (
                                            <div className="mt-2 text-sm">
                                                <div><span className="font-medium">Selected drive:</span> {opt?.label}</div>
                                                {opt?.interface && <div><span className="font-medium">Interface:</span> {opt.interface}</div>}
                                                <div><span className="font-medium">Assigned to bays:</span> {qty}</div>
                                            </div>
                                        )
                                    })()}
                                </>
                            ) : (
                                <>
                                    <Label>Choose disks</Label>
                                    <MultiPopover
                                        id="disk_specs"
                                        label="Disk Drives"
                                        options={diskOptions}
                                        selected={Object.keys(form.data.disk_specs).map(Number)}
                                        onToggle={(id) => toggleDiskSelection(id)}
                                        error={form.errors.disk_specs as string}
                                    />

                                    <div className="space-y-2 mt-2">
                                        {Object.entries(form.data.disk_specs || {}).map(([key, qty]) => {
                                            const id = Number(key)
                                            const opt = diskOptions.find(d => d.id === id)
                                            return (
                                                <div key={id} className="flex items-center gap-3">
                                                    <div className="flex-1 text-sm">{opt?.label ?? `Disk #${id}`}</div>
                                                    <Input type="number" value={qty} min={1} onChange={(e) => {
                                                        const next = { ...(form.data.disk_specs || {}) }
                                                        next[id] = Number(e.target.value || 0)
                                                        form.setData('disk_specs', next)
                                                    }} className="w-24" />
                                                    <div className="text-xs text-gray-500">({opt?.stock_quantity ?? 0} in stock)</div>
                                                    {opt?.interface && <div className="text-xs text-gray-500 ml-3">{opt.interface}</div>}
                                                </div>
                                            )
                                        })}
                                    </div>
                                </>
                            )}
                        </div>
                    </section>

                    {/* Compatibility (Processor) - changed to searchable popover */}
                    <section>
                        <h2 className="text-lg font-semibold mb-2">Compatibility</h2>
                        <div>
                            <Label htmlFor="processor_spec_id">Processor</Label>
                            <Popover>
                                <PopoverTrigger asChild>
                                    <Button variant="outline" className="w-full justify-between">
                                        {form.data.processor_spec_id
                                            ? processorOptions.find(p => p.id === form.data.processor_spec_id)?.label
                                            : 'Select a processor...'}
                                        <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0" />
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent className="w-full p-0">
                                    <Command>
                                        <CommandInput placeholder="Search processors..." />
                                        <CommandEmpty>No processor found.</CommandEmpty>
                                        <CommandGroup>
                                            {processorOptions.map(opt => {
                                                const outOfStock = (opt.stock_quantity || 0) < 1;

                                                return (
                                                    <CommandItem
                                                        key={opt.id}
                                                        onSelect={() => !outOfStock && form.setData('processor_spec_id', opt.id)}
                                                        disabled={outOfStock}
                                                        className={outOfStock ? "opacity-50 cursor-not-allowed" : ""}
                                                    >
                                                        <Check className={`mr-2 h-4 w-4 ${form.data.processor_spec_id === opt.id ? 'opacity-100' : 'opacity-0'}`} />
                                                        <span className="flex-1">{opt.label}</span>
                                                        <span className="text-xs text-gray-500 ml-4">• {opt.socket_type}</span>
                                                        <span className="text-xs text-gray-500 ml-2">({opt.stock_quantity ?? 0} in stock)</span>
                                                    </CommandItem>
                                                );
                                            })}
                                        </CommandGroup>
                                    </Command>
                                </PopoverContent>
                            </Popover>
                            {form.errors.processor_spec_id && <p className="text-red-600">{form.errors.processor_spec_id}</p>}
                        </div>
                    </section>

                    {/* Submit */}
                    <div className="flex justify-end">
                        <Button type="submit">
                            {form.data.quantity > 1
                                ? `Create ${form.data.quantity} PCs`
                                : "Create PC Spec"}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    )
}

/**
 * Inline MultiPopover used by the page (keeps file self-contained).
 * If you prefer the separate component file, extract it to src/components/MultiPopover.tsx
 */
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
                        {selected.length ? options.filter(o => selected.includes(o.id)).map(o => o.label).join(', ') : `Select ${label.toLowerCase()}…`}
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
                                const outOfStock = (opt.stock_quantity ?? 0) < 1;

                                return (
                                    <CommandItem
                                        key={opt.id}
                                        onSelect={() => !outOfStock && onToggle(opt.id)}
                                        disabled={outOfStock}
                                        className={outOfStock ? "opacity-50 cursor-not-allowed" : ""}
                                    >
                                        <Check className={`mr-2 h-4 w-4 ${isSelected ? 'opacity-100' : 'opacity-0'}`} />
                                        <span className="flex-1 truncate">{opt.label}</span>
                                        {opt.interface && <span className="text-xs text-gray-500 ml-2">{opt.interface}</span>}
                                        <span className="text-xs text-gray-500 ml-2">({opt.stock_quantity ?? 0} in stock)</span>
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
