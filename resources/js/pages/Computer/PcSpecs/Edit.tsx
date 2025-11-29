import { useEffect, useMemo } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { toast } from 'sonner';
import { ArrowLeft, Check, ChevronsUpDown } from 'lucide-react';

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
import { Command, CommandInput, CommandEmpty, CommandGroup, CommandItem } from '@/components/ui/command';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { useFlashMessage, usePageMeta, usePageLoading } from '@/hooks';

import { index as pcSpecIndex, update as pcSpecUpdate, edit as pcSpecEdit } from '@/routes/pcspecs';

const memoryTypes = ['DDR3', 'DDR4', 'DDR5'];
const ramSlotOptions = [1, 2, 4, 6, 8];
const m2SlotOptions = [1, 2, 3, 4];
const sataPortOptions = [2, 4, 6, 8];
const maxRamCapacityOptions = [16, 32, 64, 128, 256];
const formFactorOptions = ['ATX', 'Micro-ATX', 'Mini-ITX'];
//const ethernetSpeedOptions = ['1GbE', '2.5GbE', '5GbE', '10GbE'];

interface Option {
    id: number;
    label: string;
    stock_quantity?: number;
    capacity_gb?: number;
    interface?: string;
    type?: string;
}

interface ProcessorOption extends Option {
    socket_type: string;
}

interface RamOption extends Option {
    type: string;
    capacity_gb?: number;
}

interface PcSpec {
    id: number;
    pc_number?: string | null;
    manufacturer: string;
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
    // ramSpecs may be an array of ids or an array of objects depending on server shape
    ramSpecs: Array<number | { id: number; pivot?: { quantity?: number } }>;
    diskSpecs: Array<number | { id: number }>;
    processorSpecs: Array<number>;
}

interface Props {
    pcspec: PcSpec;
    ramOptions: RamOption[];
    diskOptions: Option[];
    processorOptions: ProcessorOption[];
}

export default function Edit({ pcspec, ramOptions, diskOptions, processorOptions }: Props) {
    useFlashMessage();

    const specLabel = pcspec.pc_number?.trim() || pcspec.model || `PC Spec #${pcspec.id}`;

    const { title, breadcrumbs } = usePageMeta({
        title: `Edit ${specLabel}`,
        breadcrumbs: [
            { title: 'PC Specifications', href: pcSpecIndex().url },
            { title: specLabel, href: pcSpecEdit({ pcspec: pcspec.id }).url },
        ],
    });

    const isPageLoading = usePageLoading();
    // helper: build initial ram_specs (id => qty) from incoming motherboard data.
    // server may provide ramSpecs as [id, id...] or [{id, pivot:{quantity}}...]
    const initialRamSpecs = (() => {
        if (!pcspec?.ramSpecs) return {};
        const out: Record<number, number> = {};
        for (const item of pcspec.ramSpecs) {
            if (typeof item === 'number') {
                out[item] = 1; // fallback quantity
            } else if (typeof item === 'object' && item?.id) {
                const q = item?.pivot?.quantity ?? 1;
                out[item.id] = q;
            }
        }
        return out;
    })();

    // initial disk_specs: server likely gives list of disk ids; default qty 1
    type DiskEntry = number | { id: number };
    const initialDiskSpecs = (() => {
        const out: Record<number, number> = {};
        for (const d of pcspec.diskSpecs ?? []) {
            const entry = d as DiskEntry;
            const id = typeof entry === 'number' ? entry : entry.id;
            out[Number(id)] = 1;
        }
        return out;
    })();

    const { data, setData, put, errors, processing } = useForm({
        pc_number: pcspec.pc_number ?? '',
        manufacturer: pcspec.manufacturer ?? '',
        model: pcspec.model ?? '',
        form_factor: pcspec.form_factor ?? '',
        memory_type: pcspec.memory_type ?? '',
        ram_slots: pcspec.ram_slots ?? 0,
        max_ram_capacity_gb: pcspec.max_ram_capacity_gb ?? 0,
        max_ram_speed: pcspec.max_ram_speed ?? '',
        m2_slots: pcspec.m2_slots ?? 0,
        sata_ports: pcspec.sata_ports ?? 0,
        ram_mode: 'different' as 'same' | 'different',
        ram_specs: initialRamSpecs as Record<number, number>,
        disk_mode: 'different' as 'same' | 'different',
        disk_specs: initialDiskSpecs as Record<number, number>,
        // Change from array to single processor ID, use the first one if available
        processor_spec_id: pcspec.processorSpecs && pcspec.processorSpecs.length > 0
            ? Number(pcspec.processorSpecs[0])
            : 0,
    });

    // compatibility filters
    const compatibleRams = (ramOptions ?? []).filter((r) => r.type === data.memory_type);

    // derived helpers
    const totalRamSelected = useMemo(
        () => Object.values(data.ram_specs || {}).reduce((s, v) => s + Number(v || 0), 0),
        [data.ram_specs]
    );
    const remainingRamSlots = data.ram_slots - totalRamSelected;

    // auto-prune incompatible ram selections when memory_type changes
    useEffect(() => {
        const compatible = (ramOptions ?? []).filter((r) => r.type === data.memory_type).map((r) => r.id);
        const next: Record<number, number> = {};

        for (const [idStr, qty] of Object.entries(data.ram_specs || {})) {
            const id = Number(idStr);
            if (compatible.includes(id)) next[id] = qty;
        }

        if (Object.keys(next).length !== Object.keys(data.ram_specs || {}).length) {
            setData('ram_specs', next);
        }
    }, [data.memory_type, ramOptions, data.ram_specs, setData]);

    useEffect(() => {
        if (data.ram_mode !== 'same') return;
        const selectedId = Number(Object.keys(data.ram_specs || [])[0]);
        if (!selectedId) return;
        const opt = ramOptions.find(r => r.id === selectedId);
        const capPer = Number(opt?.capacity_gb ?? NaN);
        const qty = Number(data.ram_slots || 1);
        const maxCap = Number(data.max_ram_capacity_gb || 0);
        if (!Number.isNaN(capPer) && maxCap > 0 && (capPer * qty) > maxCap) {
            toast.error(`Current selection (${opt?.label}) now exceeds motherboard max capacity (${capPer * qty} GB > ${maxCap} GB)`);
            // optionally clear selection: setData('ram_specs', {})
        }
    }, [data.ram_mode, data.ram_slots, data.max_ram_capacity_gb, data.ram_specs, ramOptions]);

    function handleUpdate(e: React.FormEvent) {
        e.preventDefault();
        // client guard: require exact fill if you keep that rule
        if (data.ram_slots > 0 && totalRamSelected > data.ram_slots) {
            toast.error(`You selected ${totalRamSelected} RAM sticks, but the motherboard only supports ${data.ram_slots} slots.`);
            return;
        }

        put(pcSpecUpdate({ pcspec: pcspec.id }).url);
    }

    // RAM helpers (same vs different) - patched: capacity and stock checks, no quantity input in 'same' mode
    function setRamSameSelection(ramId?: number) {
        if (!ramId) return;
        const qty = Number(data.ram_slots || 1);
        const opt = ramOptions.find((r) => r.id === ramId);
        const capacityPerModule = Number(opt?.capacity_gb ?? NaN);
        const maxCap = Number(data.max_ram_capacity_gb || 0);
        const totalCap = Number.isNaN(capacityPerModule) ? NaN : capacityPerModule * qty;

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

        setData('ram_specs', { [ramId]: qty });
    }

    function toggleRamSelection(id: number) {
        const next = { ...(data.ram_specs || {}) };
        if (next[id]) delete next[id];
        else next[id] = 1;
        setData('ram_specs', next);
    }

    // Disk helpers - same-mode uses m2 + sata slots and shows interface
    function setDiskSameSelection(diskId?: number) {
        if (!diskId) return;
        const qty = (Number(data.m2_slots || 0) + Number(data.sata_ports || 0)) || 1;
        const opt = diskOptions.find((d) => d.id === diskId);
        const available = Number(opt?.stock_quantity ?? 0);

        if (available < qty) {
            toast.error(`Not enough stock for ${opt?.label ?? `Disk #${diskId}`} (requested ${qty}, available ${available})`);
            return;
        }

        setData('disk_specs', { [diskId]: qty });
    }

    function toggleDiskSelection(id: number) {
        const next = { ...(data.disk_specs || {}) };
        if (next[id]) delete next[id];
        else next[id] = 1;
        setData('disk_specs', next);
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="relative mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3 md:p-6">
                <LoadingOverlay
                    isLoading={isPageLoading || processing}
                    message={processing ? 'Updating PC specification...' : undefined}
                />

                <PageHeader
                    title={title}
                    description="Revise motherboard attributes and component pairings."
                    actions={(
                        <Link href={pcSpecIndex().url}>
                            <Button variant="outline">
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back to list
                            </Button>
                        </Link>
                    )}
                />

                <form onSubmit={handleUpdate} className="space-y-8">
                    {/* Core Info */}
                    <section>
                        <h2 className="text-lg font-semibold mb-2">Core Info</h2>
                        <div className="grid grid-cols-2 gap-6">
                            <div>
                                <Label htmlFor="pc_number">PC Number (Optional)</Label>
                                <Input
                                    id="pc_number"
                                    name="pc_number"
                                    value={data.pc_number}
                                    onChange={(e) => setData('pc_number', e.target.value)}
                                    placeholder="e.g., PC-2025-001"
                                />
                                {errors.pc_number && <p className="text-red-600">{errors.pc_number}</p>}
                            </div>

                            <div>
                                <Label htmlFor="manufacturer">Manufacturer</Label>
                                <Input id="manufacturer" name="manufacturer" value={data.manufacturer} onChange={(e) => setData('manufacturer', e.target.value)} />
                                {errors.manufacturer && <p className="text-red-600">{errors.manufacturer}</p>}
                            </div>

                            <div>
                                <Label htmlFor="model">Model</Label>
                                <Input id="model" name="model" value={data.model} onChange={(e) => setData('model', e.target.value)} />
                                {errors.model && <p className="text-red-600">{errors.model}</p>}
                            </div>

                            <div>
                                <Label htmlFor="form_factor">Form Factor</Label>
                                <Select value={data.form_factor} onValueChange={(val) => setData('form_factor', val)}>
                                    <SelectTrigger id="form_factor" className="w-full"><SelectValue placeholder="Select form factor" /></SelectTrigger>
                                    <SelectContent>
                                        {formFactorOptions.map(ff => (
                                            <SelectItem key={ff} value={ff}>{ff}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.form_factor && <p className="text-red-600">{errors.form_factor}</p>}
                            </div>
                        </div>
                    </section>

                    {/* Memory */}
                    <section>
                        <h2 className="text-lg font-semibold mb-2">Memory</h2>
                        <div className="grid grid-cols-2 gap-6">
                            <div>
                                <Label htmlFor="memory_type">Memory Type</Label>
                                <Select value={data.memory_type} onValueChange={(val) => setData('memory_type', val)}>
                                    <SelectTrigger id="memory_type" className="w-full"><SelectValue placeholder="Select memory type" /></SelectTrigger>
                                    <SelectContent>{memoryTypes.map(type => <SelectItem key={type} value={type}>{type}</SelectItem>)}</SelectContent>
                                </Select>
                                {errors.memory_type && <p className="text-red-600">{errors.memory_type}</p>}
                            </div>

                            <div>
                                <Label htmlFor="ram_slots">RAM Slots</Label>
                                <Select value={String(data.ram_slots)} onValueChange={(val) => setData('ram_slots', Number(val))}>
                                    <SelectTrigger id="ram_slots" className="w-full"><SelectValue placeholder="Select # of slots" /></SelectTrigger>
                                    <SelectContent>{ramSlotOptions.map(n => <SelectItem key={n} value={String(n)}>{n}</SelectItem>)}</SelectContent>
                                </Select>
                                {errors.ram_slots && <p className="text-red-600">{errors.ram_slots}</p>}
                            </div>

                            <div>
                                <Label htmlFor="max_ram_capacity_gb">Max RAM Capacity (GB)</Label>
                                <Select value={String(data.max_ram_capacity_gb)} onValueChange={(val) => setData('max_ram_capacity_gb', Number(val))}>
                                    <SelectTrigger id="max_ram_capacity_gb" className="w-full"><SelectValue placeholder="Select capacity" /></SelectTrigger>
                                    <SelectContent>{maxRamCapacityOptions.map(cap => <SelectItem key={cap} value={String(cap)}>{cap} GB</SelectItem>)}</SelectContent>
                                </Select>
                                {errors.max_ram_capacity_gb && <p className="text-red-600">{errors.max_ram_capacity_gb}</p>}
                            </div>

                            <div>
                                <Label htmlFor="max_ram_speed">Max RAM Speed</Label>
                                <Input id="max_ram_speed" name="max_ram_speed" value={data.max_ram_speed} onChange={(e) => setData('max_ram_speed', e.target.value)} />
                                {errors.max_ram_speed && <p className="text-red-600">{errors.max_ram_speed}</p>}
                            </div>
                        </div>

                        {/* same vs different toggle */}
                        <div className="mt-4 flex items-center gap-6">
                            <div className="flex items-center gap-2">
                                <input id="ram_mode_same" name="ram_mode" type="radio" className="h-4 w-4 cursor-pointer accent-primary" checked={data.ram_mode === 'same'} onChange={() => setData('ram_mode', 'same')} aria-label="Use same module for all slots" />
                                <Label htmlFor="ram_mode_same">Use same module for all slots</Label>
                            </div>
                            <div className="flex items-center gap-2">
                                <input id="ram_mode_diff" name="ram_mode" type="radio" className="h-4 w-4 cursor-pointer accent-primary" checked={data.ram_mode === 'different'} onChange={() => setData('ram_mode', 'different')} aria-label="Use different modules" />
                                <Label htmlFor="ram_mode_diff">Use different modules</Label>
                            </div>
                            <div className="ml-auto text-sm text-gray-600">
                                Slots remaining: <span className={remainingRamSlots === 0 ? 'font-semibold text-green-600' : 'font-semibold text-red-600'}>{remainingRamSlots}</span>
                            </div>
                        </div>

                        {/* RAM selection UIs */}
                        <div className="mt-4 grid grid-cols-1 gap-3">
                            {data.ram_mode === 'same' ? (
                                <>
                                    <Label>Choose module to fill all slots</Label>
                                    <Popover>
                                        <PopoverTrigger asChild>
                                            <Button variant="outline" className="w-full justify-between">
                                                {Object.keys(data.ram_specs).length
                                                    ? ramOptions.find(r => r.id === Number(Object.keys(data.ram_specs)[0]))?.label
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
                                                        const outOfStock = (opt.stock_quantity ?? 0) < data.ram_slots;
                                                        const capPer = Number(opt.capacity_gb ?? 0);
                                                        const maxCap = Number(data.max_ram_capacity_gb || 0);
                                                        const totalCap = capPer * (data.ram_slots || 1);
                                                        const exceedsCapacity = maxCap > 0 && totalCap > maxCap;
                                                        const disabled = outOfStock || exceedsCapacity;

                                                        return (
                                                            <CommandItem key={opt.id} onSelect={() => !disabled && setRamSameSelection(opt.id)} disabled={disabled} className={disabled ? "opacity-50 cursor-not-allowed" : ""}>
                                                                <Check className={`mr-2 h-4 w-4 ${Object.keys(data.ram_specs).map(Number).includes(opt.id) ? 'opacity-100' : 'opacity-0'}`} />
                                                                <span className="flex-1">{opt.label}</span>
                                                                <span className="text-xs text-gray-500 ml-2">({opt.stock_quantity ?? 0} in stock)</span>
                                                                {opt.capacity_gb !== undefined && <span className="text-xs text-gray-500 ml-4">• {opt.capacity_gb} GB/module</span>}
                                                                {exceedsCapacity && <span className="text-xs text-red-600 ml-3">• exceeds max capacity</span>}
                                                            </CommandItem>
                                                        );
                                                    })}
                                                </CommandGroup>
                                            </Command>
                                        </PopoverContent>
                                    </Popover>

                                    {/* Quantity input removed while in 'same' mode */}
                                </>
                            ) : (
                                <>
                                    <Label>Choose modules and set per-module quantities</Label>
                                    <MultiPopover
                                        id="ram_specs"
                                        label="RAM Modules"
                                        options={compatibleRams}
                                        selected={Object.keys(data.ram_specs).map(Number)}
                                        onToggle={(id) => toggleRamSelection(id)}
                                        error={errors.ram_specs as string}
                                    />

                                    <div className="space-y-2 mt-2">
                                        {Object.entries(data.ram_specs || {}).map(([key, qty]) => {
                                            const id = Number(key);
                                            const opt = ramOptions.find(r => r.id === id);
                                            return (
                                                <div key={id} className="flex items-center gap-3">
                                                    <div className="flex-1 text-sm">{opt?.label ?? `RAM #${id}`}</div>
                                                    <Input type="number" value={qty} min={1} max={data.ram_slots || 1} onChange={(e) => {
                                                        const next = { ...(data.ram_specs || {}) };
                                                        next[id] = Number(e.target.value || 0);
                                                        setData('ram_specs', next);
                                                    }} className="w-24" />
                                                    <div className="text-xs text-gray-500">({opt?.stock_quantity ?? 0} in stock)</div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </>
                            )}
                        </div>
                    </section>

                    {/* Expansion & Storage */}
                    <section>
                        <h2 className="text-lg font-semibold mb-2">Expansion & Storage</h2>
                        <div className="grid grid-cols-2 gap-6">
                            <div>
                                <Label htmlFor="m2_slots">M.2 Slots</Label>
                                <Select value={String(data.m2_slots)} onValueChange={(val) => setData('m2_slots', Number(val))}>
                                    <SelectTrigger id="m2_slots" className="w-full"><SelectValue placeholder="Select M.2 slots" /></SelectTrigger>
                                    <SelectContent>{m2SlotOptions.map(n => <SelectItem key={n} value={String(n)}>{n}</SelectItem>)}</SelectContent>
                                </Select>
                                {errors.m2_slots && <p className="text-red-600">{errors.m2_slots}</p>}
                            </div>

                            <div>
                                <Label htmlFor="sata_ports">SATA Ports</Label>
                                <Select value={String(data.sata_ports)} onValueChange={(val) => setData('sata_ports', Number(val))}>
                                    <SelectTrigger id="sata_ports" className="w-full"><SelectValue placeholder="Select SATA ports" /></SelectTrigger>
                                    <SelectContent>{sataPortOptions.map(n => <SelectItem key={n} value={String(n)}>{n}</SelectItem>)}</SelectContent>
                                </Select>
                                {errors.sata_ports && <p className="text-red-600">{errors.sata_ports}</p>}
                            </div>
                        </div>

                        {/* disk same/different toggle */}
                        <div className="mt-4 flex items-center gap-6">
                            <div className="flex items-center gap-2">
                                <input id="disk_mode_same" name="disk_mode" type="radio" className="h-4 w-4 cursor-pointer accent-primary" checked={data.disk_mode === 'same'} onChange={() => setData('disk_mode', 'same')} aria-label="Use same drive for all slots" />
                                <Label htmlFor="disk_mode_same">Use same drive for all slots</Label>
                            </div>
                            <div className="flex items-center gap-2">
                                <input id="disk_mode_diff" name="disk_mode" type="radio" className="h-4 w-4 cursor-pointer accent-primary" checked={data.disk_mode === 'different'} onChange={() => setData('disk_mode', 'different')} aria-label="Use different drives" />
                                <Label htmlFor="disk_mode_diff">Use different drives</Label>
                            </div>
                        </div>

                        <div className="mt-3">
                            {data.disk_mode === 'same' ? (
                                <>
                                    <Label>Choose one disk to populate slots</Label>
                                    <Popover>
                                        <PopoverTrigger asChild>
                                            <Button variant="outline" className="w-full justify-between">
                                                {Object.keys(data.disk_specs).length
                                                    ? diskOptions.find(d => d.id === Number(Object.keys(data.disk_specs)[0]))?.label
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
                                                        const outOfStock = (opt.stock_quantity ?? 0) < 1;
                                                        const capInterface = opt.interface;
                                                        return (
                                                            <CommandItem key={opt.id} onSelect={() => !outOfStock && setDiskSameSelection(opt.id)} disabled={outOfStock} className={outOfStock ? "opacity-50 cursor-not-allowed" : ""}>
                                                                <Check className={`mr-2 h-4 w-4 ${Object.keys(data.disk_specs).map(Number).includes(opt.id) ? 'opacity-100' : 'opacity-0'}`} />
                                                                <span className="flex-1">{opt.label}</span>
                                                                {capInterface && <span className="text-xs text-gray-500 ml-2">{capInterface}</span>}
                                                                <span className="text-xs text-gray-500 ml-2">({opt.stock_quantity ?? 0} in stock)</span>
                                                            </CommandItem>
                                                        );
                                                    })}
                                                </CommandGroup>
                                            </Command>
                                        </PopoverContent>
                                    </Popover>

                                    {/* Show chosen disk interface and assigned qty (derived from SATA + M.2 slots) */}
                                    {Object.keys(data.disk_specs).length > 0 && (() => {
                                        const id = Number(Object.keys(data.disk_specs)[0]);
                                        const opt = diskOptions.find(d => d.id === id);
                                        const qty = data.disk_specs[id] ?? (Number(data.m2_slots || 0) + Number(data.sata_ports || 0) || 1);
                                        return (
                                            <div className="mt-2 text-sm">
                                                <div><span className="font-medium">Selected drive:</span> {opt?.label}</div>
                                                {opt?.interface && <div><span className="font-medium">Interface:</span> {opt.interface}</div>}
                                                <div><span className="font-medium">Assigned to bays:</span> {qty}</div>
                                            </div>
                                        );
                                    })()}
                                </>
                            ) : (
                                <>
                                    <Label>Choose disks</Label>
                                    <MultiPopover
                                        id="disk_specs"
                                        label="Disk Drives"
                                        options={diskOptions}
                                        selected={Object.keys(data.disk_specs).map(Number)}
                                        onToggle={(id) => toggleDiskSelection(id)}
                                        error={errors.disk_specs as string}
                                    />

                                    <div className="space-y-2 mt-2">
                                        {Object.entries(data.disk_specs || {}).map(([key, qty]) => {
                                            const id = Number(key);
                                            const opt = diskOptions.find(d => d.id === id);
                                            return (
                                                <div key={id} className="flex items-center gap-3">
                                                    <div className="flex-1 text-sm">{opt?.label ?? `Disk #${id}`}</div>
                                                    <Input type="number" value={qty} min={1} onChange={(e) => {
                                                        const next = { ...(data.disk_specs || {}) };
                                                        next[id] = Number(e.target.value || 0);
                                                        setData('disk_specs', next);
                                                    }} className="w-24" />
                                                    <div className="text-xs text-gray-500">({opt?.stock_quantity ?? 0} in stock)</div>
                                                    {opt?.interface && <div className="text-xs text-gray-500 ml-3">{opt.interface}</div>}
                                                </div>
                                            );
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
                                        {data.processor_spec_id
                                            ? processorOptions.find(p => p.id === data.processor_spec_id)?.label
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
                                                        onSelect={() => !outOfStock && setData('processor_spec_id', opt.id)}
                                                        disabled={outOfStock}
                                                        className={outOfStock ? "opacity-50 cursor-not-allowed" : ""}
                                                    >
                                                        <Check className={`mr-2 h-4 w-4 ${data.processor_spec_id === opt.id ? 'opacity-100' : 'opacity-0'}`} />
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
                            {errors.processor_spec_id && <p className="text-red-600">{errors.processor_spec_id}</p>}
                        </div>
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

/* MultiPopover used in Create/Edit */
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
