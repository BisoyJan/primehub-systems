import { useEffect, useMemo } from 'react'
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
  stock_quantity?: number
}

interface ProcessorOption extends Option {
  socket_type: string
}

interface RamOption extends Option {
  type: string
}

type PageProps = {
  ramOptions: RamOption[]
  diskOptions: Option[]
  processorOptions: ProcessorOption[]
  flash?: { message?: string; type?: string }
}

const memoryTypes = ['DDR3', 'DDR4', 'DDR5']
const ramSlotOptions = [1, 2, 4, 6, 8]
const m2SlotOptions = [0, 1, 2, 3, 4]
const sataPortOptions = [0, 2, 4, 6, 8]
const maxRamCapacityOptions = [16, 32, 64, 128, 256]
const ethernetSpeedOptions = ['1GbE', '2.5GbE', '5GbE', '10GbE']

export default function Create() {
  const { ramOptions, diskOptions, processorOptions, flash } = usePage<PageProps>().props

  const form = useForm({
    brand: '',
    model: '',
    chipset: '',
    form_factor: '',
    socket_type: '',
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

    // new shape for same/different feature
    ram_mode: 'different' as 'same' | 'different',
    ram_specs: {} as Record<number, number>, // { ramId: qty }
    disk_mode: 'different' as 'same' | 'different',
    disk_specs: {} as Record<number, number>, // { diskId: qty }

    processor_spec_ids: [] as number[],
  })

  // compatibility filters
  const compatibleProcessors = (processorOptions ?? []).filter(
    (p) => p.socket_type === form.data.socket_type
  )

  const compatibleRams = (ramOptions ?? []).filter(
    (r) => r.type === form.data.memory_type
  )

  // derived helpers
  const totalRamSelected = useMemo(
    () => Object.values(form.data.ram_specs || {}).reduce((s, v) => s + Number(v || 0), 0),
    [form.data.ram_specs]
  )

  const remainingRamSlots = form.data.ram_slots - totalRamSelected

  // auto-clear incompatible processors when socket changes
  useEffect(() => {
    const compatible = (processorOptions ?? []).filter(
      (p) => p.socket_type === form.data.socket_type
    )
    const stillValid = (form.data.processor_spec_ids ?? []).filter((id) =>
      compatible.some((p) => p.id === id)
    )
    if (stillValid.length !== form.data.processor_spec_ids.length) {
      form.setData('processor_spec_ids', stillValid)
    }
  }, [form.data.socket_type, processorOptions])

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
  }, [form.data.memory_type, ramOptions])

  // flash messages
  useEffect(() => {
    if (!flash?.message) return
    if (flash.type === 'error') toast.error(flash.message)
    else toast.success(flash.message)
  }, [flash?.message, flash?.type])

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    // simple client-side guard: require exact slot fill for RAM
    if (form.data.ram_slots > 0 && totalRamSelected !== form.data.ram_slots) {
      toast.error(`Please allocate exactly ${form.data.ram_slots} RAM sticks (selected ${totalRamSelected})`)
      return
    }
    form.post(motherboardStore.url())
  }

  // RAM helpers (same vs different)
  function setRamSameSelection(ramId?: number) {
    if (!ramId) return
    const qty = form.data.ram_slots || 1
    form.setData('ram_specs', { [ramId]: qty })
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
    const qty = form.data.m2_slots + form.data.sata_ports || 1
    // keep it conservative: user can edit qty later
    form.setData('disk_specs', { [diskId]: qty })
  }

  function toggleDiskSelection(id: number) {
    const next = { ...(form.data.disk_specs || {}) }
    if (next[id]) delete next[id]
    else next[id] = 1
    form.setData('disk_specs', next)
  }

  return (
    <AppLayout>
      <Head title="Create Motherboard" />

      <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3 w-full md:w-10/12 lg:w-8/12 mx-auto">
        <div className="flex justify-start">
          <Link href={motherboardIndex.url()}>
            <Button><ArrowLeft /> Return</Button>
          </Link>
        </div>

        <form onSubmit={handleSubmit} className="space-y-8">
          {/* Core Info */}
          <section>
            <h2 className="text-lg font-semibold mb-2">Core Info</h2>
            <div className="grid grid-cols-2 gap-6">
              <div>
                <Label htmlFor="brand">Brand</Label>
                <Input id="brand" name="brand" value={form.data.brand} onChange={(e) => form.setData('brand', e.target.value)} />
                {form.errors.brand && <p className="text-red-600">{form.errors.brand}</p>}
              </div>

              <div>
                <Label htmlFor="model">Model</Label>
                <Input id="model" name="model" value={form.data.model} onChange={(e) => form.setData('model', e.target.value)} />
                {form.errors.model && <p className="text-red-600">{form.errors.model}</p>}
              </div>

              <div>
                <Label htmlFor="chipset">Chipset</Label>
                <Input id="chipset" name="chipset" value={form.data.chipset} onChange={(e) => form.setData('chipset', e.target.value)} />
                {form.errors.chipset && <p className="text-red-600">{form.errors.chipset}</p>}
              </div>

              <div>
                <Label htmlFor="form_factor">Form Factor</Label>
                <Input id="form_factor" name="form_factor" value={form.data.form_factor} onChange={(e) => form.setData('form_factor', e.target.value)} />
                {form.errors.form_factor && <p className="text-red-600">{form.errors.form_factor}</p>}
              </div>

              <div>
                <Label htmlFor="socket_type">Socket Type</Label>
                <Select onValueChange={(val) => form.setData('socket_type', val)} value={form.data.socket_type}>
                  <SelectTrigger id="socket_type" className="w-full"><SelectValue placeholder="Select socket type" /></SelectTrigger>
                  <SelectContent>
                    {["LGA1151", "LGA1200", "LGA1700", "AM3+", "AM4", "AM5", "TR4", "sTRX4"].map(socket => (
                      <SelectItem key={socket} value={socket}>{socket}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {form.errors.socket_type && <p className="text-red-600">{form.errors.socket_type}</p>}
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
                <input id="ram_mode_same" name="ram_mode" type="radio" className="cursor-pointer" checked={form.data.ram_mode === 'same'} onChange={() => form.setData('ram_mode', 'same')} />
                <Label htmlFor="ram_mode_same">Use same module for all slots</Label>
              </div>
              <div className="flex items-center gap-2">
                <input id="ram_mode_diff" name="ram_mode" type="radio" className="cursor-pointer" checked={form.data.ram_mode === 'different'} onChange={() => form.setData('ram_mode', 'different')} />
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
                            return (
                              <CommandItem key={opt.id} onSelect={() => !outOfStock && setRamSameSelection(opt.id)} disabled={outOfStock} className={outOfStock ? "opacity-50 cursor-not-allowed" : ""}>
                                <Check className={`mr-2 h-4 w-4 ${Object.keys(form.data.ram_specs).map(Number).includes(opt.id) ? 'opacity-100' : 'opacity-0'}`} />
                                <span className="flex-1">{opt.label}</span>
                                <span className="text-xs text-gray-500 ml-2">({opt.stock_quantity ?? 0} in stock)</span>
                              </CommandItem>
                            )
                          })}
                        </CommandGroup>
                      </Command>
                    </PopoverContent>
                  </Popover>

                  {/* allow editing quantity (in case user wants fewer than all slots) */}
                  {Object.keys(form.data.ram_specs).length > 0 && (
                    <div className="flex items-center gap-2 mt-2">
                      <span className="text-sm">Quantity</span>
                      <Input
                        type="number"
                        min={1}
                        max={form.data.ram_slots || 1}
                        value={Object.values(form.data.ram_specs)[0] ?? ''}
                        onChange={(e) => {
                          const id = Number(Object.keys(form.data.ram_specs)[0])
                          const q = Number(e.target.value || 0)
                          form.setData('ram_specs', q > 0 ? { [id]: q } : {})
                        }}
                        className="w-28"
                      />
                    </div>
                  )}
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
          </section>

          {/* Expansion & Storage */}
          <section>
            <h2 className="text-lg font-semibold mb-2">Expansion & Storage</h2>
            <div className="grid grid-cols-2 gap-6">
              <div>
                <Label htmlFor="pcie_slots">PCIe Slots</Label>
                <Input id="pcie_slots" name="pcie_slots" value={form.data.pcie_slots} onChange={(e) => form.setData('pcie_slots', e.target.value)} />
                {form.errors.pcie_slots && <p className="text-red-600">{form.errors.pcie_slots}</p>}
              </div>

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

              <div>
                <Label htmlFor="usb_ports">USB Ports</Label>
                <Input id="usb_ports" name="usb_ports" value={form.data.usb_ports} onChange={(e) => form.setData('usb_ports', e.target.value)} />
                {form.errors.usb_ports && <p className="text-red-600">{form.errors.usb_ports}</p>}
              </div>
            </div>

            {/* disk same/different toggle */}
            <div className="mt-4 flex items-center gap-6">
              <div className="flex items-center gap-2">
                <input id="disk_mode_same" name="disk_mode" type="radio" checked={form.data.disk_mode === 'same'} onChange={() => form.setData('disk_mode', 'same')} />
                <Label htmlFor="disk_mode_same">Use same drive for all slots</Label>
              </div>
              <div className="flex items-center gap-2">
                <input id="disk_mode_diff" name="disk_mode" type="radio" checked={form.data.disk_mode === 'different'} onChange={() => form.setData('disk_mode', 'different')} />
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
                            return (
                              <CommandItem key={opt.id} onSelect={() => !outOfStock && setDiskSameSelection(opt.id)} disabled={outOfStock} className={outOfStock ? "opacity-50 cursor-not-allowed" : ""}>
                                <Check className={`mr-2 h-4 w-4 ${Object.keys(form.data.disk_specs).map(Number).includes(opt.id) ? 'opacity-100' : 'opacity-0'}`} />
                                <span className="flex-1">{opt.label}</span>
                                <span className="text-xs text-gray-500 ml-2">({opt.stock_quantity ?? 0} in stock)</span>
                              </CommandItem>
                            )
                          })}
                        </CommandGroup>
                      </Command>
                    </PopoverContent>
                  </Popover>
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
                        </div>
                      )
                    })}
                  </div>
                </>
              )}
            </div>
          </section>

          {/* Connectivity */}
          <section>
            <h2 className="text-lg font-semibold mb-2">Connectivity</h2>
            <div className="grid grid-cols-2 gap-6">
              <div>
                <Label htmlFor="ethernet_speed">Ethernet Speed</Label>
                <Select value={form.data.ethernet_speed} onValueChange={(val) => form.setData('ethernet_speed', val)}>
                  <SelectTrigger id="ethernet_speed" className="w-full"><SelectValue placeholder="Select speed" /></SelectTrigger>
                  <SelectContent>
                    <SelectGroup>
                      <SelectLabel>Speed</SelectLabel>
                      {ethernetSpeedOptions.map((speed) => <SelectItem key={speed} value={speed}>{speed}</SelectItem>)}
                    </SelectGroup>
                  </SelectContent>
                </Select>
                {form.errors.ethernet_speed && <p className="text-red-600">{form.errors.ethernet_speed}</p>}
              </div>

              <div className="flex items-center space-x-2">
                <Input id="wifi" name="wifi" type="checkbox" checked={form.data.wifi} onChange={(e) => form.setData('wifi', e.target.checked)} />
                <Label htmlFor="wifi">Wi-Fi Enabled</Label>
                {form.errors.wifi && <p className="text-red-600">{form.errors.wifi}</p>}
              </div>
            </div>
          </section>

          {/* Compatibility (Processors) */}
          <section>
            <h2 className="text-lg font-semibold mb-2">Compatibility</h2>
            {!form.data.socket_type ? (
              <p className="text-gray-500">Select a socket type first to see compatible processors.</p>
            ) : (
              <MultiPopover
                id="processor_spec_ids"
                label="Processors"
                options={compatibleProcessors}
                selected={form.data.processor_spec_ids}
                onToggle={(id) => {
                  const list = form.data.processor_spec_ids
                  const next = list.includes(id) ? list.filter(i => i !== id) : [...list, id]
                  form.setData('processor_spec_ids', next)
                }}
                error={form.errors.processor_spec_ids as string}
              />
            )}
          </section>

          {/* Submit */}
          <div className="flex justify-end">
            <Button type="submit">Create Motherboard</Button>
          </div>
        </form>
      </div>
    </AppLayout>
  )
}

function MultiPopover({
  id,
  label,
  options,
  selected,
  onToggle,
  error,
}: {
  id: string
  label: string
  options: Option[]
  selected: number[]
  onToggle: (id: number) => void
  error?: string
}) {
  return (
    <div className="col-span-2">
      <Label htmlFor={id}>{label}</Label>
      <Popover>
        <PopoverTrigger asChild>
          <Button variant="outline" className="w-full justify-between" id={id}>
            {selected.length
              ? options.filter(o => selected.includes(o.id)).map(o => o.label).join(', ')
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
                const isSelected = selected.includes(opt.id)
                const outOfStock = (opt.stock_quantity ?? 0) < 1

                return (
                  <CommandItem
                    key={opt.id}
                    onSelect={() => !outOfStock && onToggle(opt.id)}
                    disabled={outOfStock}
                    className={outOfStock ? "opacity-50 cursor-not-allowed" : ""}
                  >
                    <Check className={`mr-2 h-4 w-4 ${isSelected ? 'opacity-100' : 'opacity-0'}`} />
                    <span className="flex-1">{opt.label}</span>
                    <span className="text-xs text-gray-500 ml-2">({opt.stock_quantity ?? 0} in stock)</span>
                  </CommandItem>
                )
              })}
            </CommandGroup>
          </Command>
        </PopoverContent>
      </Popover>
      {error && <p className="text-red-600">{error}</p>}
    </div>
  )
}
