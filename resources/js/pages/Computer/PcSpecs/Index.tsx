import { useState, useEffect, useRef, useMemo } from 'react';
import { Head, Link, useForm, usePage, router } from '@inertiajs/react';
import type { PageProps as InertiaPageProps } from "@inertiajs/core";
import { toast } from 'sonner';

import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Dialog,
    DialogTrigger,
    DialogContent,
    DialogTitle,
    DialogClose,
    DialogHeader,
    DialogDescription,
} from '@/components/ui/dialog';
import {
    AlertDialog,
    AlertDialogTrigger,
    AlertDialogContent,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogCancel,
    AlertDialogAction,
} from '@/components/ui/alert-dialog';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Badge } from '@/components/ui/badge';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import PaginationNav, { PaginationLink } from '@/components/pagination-nav';
import { RefreshCw, Filter, Plus, Play, Pause, ChevronsUpDown, Check, X } from 'lucide-react';

// New reusable components and hooks
import { usePageMeta, useFlashMessage, usePageLoading } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { TableSkeleton } from '@/components/TableSkeleton';

import {
    index as pcSpecIndex,
    create as pcSpecCreate,
    edit as pcSpecEdit,
    destroy as pcSpecDestroy,
} from '@/routes/pcspecs';


interface ProcessorSpec {
    id: number;
    manufacturer: string;
    model: string;
    core_count: number;
    thread_count: number;
    base_clock_ghz: number;
    boost_clock_ghz: number;
}
interface PcSpec {
    id: number;
    pc_number?: string | null;
    manufacturer: string;
    memory_type: string;
    ram_gb: number;
    disk_gb: number;
    available_ports?: string | null;
    notes?: string | null;
    bios_release_date?: string | null;
    issue?: string | null;
    processorSpecs: ProcessorSpec[];
}

interface PaginatedPcSpecs {
    data: PcSpec[];
    links: PaginationLink[];
    current_page?: number;
    meta?: { current_page?: number };
}

interface PcOption {
    id: number;
    label: string;
}

interface ProcessorOption {
    id: number;
    label: string;
    core_count?: number | null;
    thread_count?: number | null;
}

interface Props extends InertiaPageProps {
    flash?: { message?: string; type?: string };
    pcspecs: PaginatedPcSpecs;
    allPcSpecs: PcOption[];
    allProcessors: ProcessorOption[];
    filters: {
        pc_ids: number[];
        processor_ids: number[];
        sort_dir?: 'asc' | 'desc';
        pc_number_from?: number | null;
        pc_number_to?: number | null;
    };
}



export default function Index() {
    const {
        pcspecs = { data: [], links: [] },
        allPcSpecs = [],
        allProcessors = [],
        filters = { pc_ids: [], processor_ids: [], sort_dir: 'asc' },
    } = usePage<Props>().props;
    const form = useForm({}); // Keep useForm for delete but empty for search

    // Current pagination page (preserved across CRUD operations)
    const currentPage = pcspecs.current_page
        ?? pcspecs.meta?.current_page
        ?? (() => {
            const fromUrl = Number(new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '').get('page'));
            return Number.isFinite(fromUrl) && fromUrl > 0 ? fromUrl : 1;
        })();
    const editLinkSuffix = currentPage && currentPage > 1 ? `?page=${currentPage}` : '';
    const [issueDialogOpen, setIssueDialogOpen] = useState(false);
    const [selectedPcSpec, setSelectedPcSpec] = useState<PcSpec | null>(null);
    const [issueText, setIssueText] = useState('');
    const [notesDialogOpen, setNotesDialogOpen] = useState(false);
    const [notesText, setNotesText] = useState('');

    // Multi-select PC search state
    const [pcSearchQuery, setPcSearchQuery] = useState('');
    const [isPcPopoverOpen, setIsPcPopoverOpen] = useState(false);
    const [selectedFilterPcIds, setSelectedFilterPcIds] = useState<number[]>(
        Array.isArray(filters.pc_ids) ? filters.pc_ids.map(Number) : []
    );

    // Sort state
    const [sortDir, setSortDir] = useState<'asc' | 'desc'>(filters.sort_dir ?? 'asc');

    // PC number range filter state
    const [pcNumberFrom, setPcNumberFrom] = useState<string>(filters.pc_number_from != null ? String(filters.pc_number_from) : '');
    const [pcNumberTo, setPcNumberTo] = useState<string>(filters.pc_number_to != null ? String(filters.pc_number_to) : '');

    // Processor filter state (multi-select)
    const [processorSearchQuery, setProcessorSearchQuery] = useState('');
    const [isProcessorPopoverOpen, setIsProcessorPopoverOpen] = useState(false);
    const [selectedProcessorIds, setSelectedProcessorIds] = useState<number[]>(
        Array.isArray(filters.processor_ids) ? filters.processor_ids.map(Number) : []
    );

    const handleToggleProcessorSelect = (procId: number) => {
        setSelectedProcessorIds(prev =>
            prev.includes(procId) ? prev.filter(id => id !== procId) : [...prev, procId]
        );
    };

    // Filter PC options by search query
    const filteredPcOptions = useMemo(() => {
        if (!pcSearchQuery) return allPcSpecs;
        const lower = pcSearchQuery.toLowerCase();
        return allPcSpecs.filter(pc => pc.label.toLowerCase().includes(lower));
    }, [allPcSpecs, pcSearchQuery]);

    // Filter processor options by search query
    const filteredProcessorOptions = useMemo(() => {
        if (!processorSearchQuery) return allProcessors;
        const lower = processorSearchQuery.toLowerCase();
        return allProcessors.filter(p => p.label.toLowerCase().includes(lower));
    }, [allProcessors, processorSearchQuery]);

    const handleTogglePcSelect = (pcId: number) => {
        setSelectedFilterPcIds(prev =>
            prev.includes(pcId)
                ? prev.filter(id => id !== pcId)
                : [...prev, pcId]
        );
    };

    const handleRemovePcFilter = (pcId: number) => {
        setSelectedFilterPcIds(prev => prev.filter(id => id !== pcId));
    };

    const [lastRefresh, setLastRefresh] = useState(new Date());
    const [autoRefreshEnabled, setAutoRefreshEnabled] = useState(false);

    const handleManualRefresh = () => {
        setLastRefresh(new Date());
        router.reload({ only: ['pcspecs'] });
    };

    // Auto-refresh every 30 seconds
    const isPollingRef = useRef(false);
    useEffect(() => {
        if (!autoRefreshEnabled) return;
        const interval = setInterval(() => {
            if (isPollingRef.current) return;
            isPollingRef.current = true;
            router.reload({
                only: ['pcspecs'],
                onSuccess: () => setLastRefresh(new Date()),
                onFinish: () => { isPollingRef.current = false; },
            });
        }, 30000);

        return () => clearInterval(interval);
    }, [autoRefreshEnabled]);

    const handleFilter = () => {
        const params: Record<string, string | number | number[]> = {};
        if (selectedFilterPcIds.length > 0) {
            params.pc_ids = selectedFilterPcIds;
        }
        if (selectedProcessorIds.length > 0) {
            params.processor_ids = selectedProcessorIds;
        }
        if (sortDir !== 'asc') {
            params.sort_dir = sortDir;
        }
        const from = parseInt(pcNumberFrom, 10);
        const to = parseInt(pcNumberTo, 10);
        if (!isNaN(from) && from > 0) params.pc_number_from = from;
        if (!isNaN(to) && to > 0) params.pc_number_to = to;
        router.get(
            pcSpecIndex().url,
            params,
            {
                preserveState: true,
                preserveScroll: true,
            }
        );
    };

    const handleReset = () => {
        setSelectedFilterPcIds([]);
        setSelectedProcessorIds([]);
        setPcSearchQuery('');
        setProcessorSearchQuery('');
        setSortDir('asc');
        setPcNumberFrom('');
        setPcNumberTo('');
        router.get(pcSpecIndex().url);
    };

    const [selectedZipProgress, setSelectedZipProgress] = useState<{
        running: boolean;
        percent: number;
        status: string;
    }>({ running: false, percent: 0, status: '' });

    const [bulkProgress, setBulkProgress] = useState<{
        running: boolean;
        percent: number;
        status: string;
    }>({ running: false, percent: 0, status: '' });

    // QR Code functionality - persist selection in localStorage
    const LOCAL_STORAGE_KEY = 'pcspec_selected_ids';
    const LOCAL_STORAGE_TIMESTAMP_KEY = 'pcspec_selected_ids_timestamp';
    const EXPIRY_TIME_MS = 15 * 60 * 1000; // 15 minutes

    const [selectedPcIds, setSelectedPcIds] = useState<number[]>(() => {
        try {
            const stored = localStorage.getItem(LOCAL_STORAGE_KEY);
            const timestamp = localStorage.getItem(LOCAL_STORAGE_TIMESTAMP_KEY);

            if (stored && timestamp) {
                const age = Date.now() - parseInt(timestamp, 10);
                if (age < EXPIRY_TIME_MS) {
                    return JSON.parse(stored);
                } else {
                    // Clear expired data
                    localStorage.removeItem(LOCAL_STORAGE_KEY);
                    localStorage.removeItem(LOCAL_STORAGE_TIMESTAMP_KEY);
                }
            }
            return [];
        } catch {
            // Ignore localStorage errors
            return [];
        }
    });

    useEffect(() => {
        try {
            if (selectedPcIds.length > 0) {
                localStorage.setItem(LOCAL_STORAGE_KEY, JSON.stringify(selectedPcIds));
                localStorage.setItem(LOCAL_STORAGE_TIMESTAMP_KEY, Date.now().toString());
            } else {
                localStorage.removeItem(LOCAL_STORAGE_KEY);
                localStorage.removeItem(LOCAL_STORAGE_TIMESTAMP_KEY);
            }
        } catch {
            // Ignore localStorage errors
        }
    }, [selectedPcIds]);

    // Use new hooks for cleaner code
    const { title, breadcrumbs } = usePageMeta({
        title: "PC Specifications",
        breadcrumbs: [{ title: "PC Specifications", href: pcSpecIndex().url }]
    });

    useFlashMessage(); // Automatically handles flash messages
    const isLoading = usePageLoading(); // Track page loading state

    const handleDelete = (id: number) => {
        form.delete(pcSpecDestroy({ pcspec: id }).url, {
            preserveScroll: true,
        });
    };

    function handleOpenIssueDialog(pcSpec: PcSpec) {
        setSelectedPcSpec(pcSpec);
        setIssueText(pcSpec.issue || '');
        setIssueDialogOpen(true);
    }

    function handleSaveIssue() {
        if (!selectedPcSpec) return;

        router.patch(`/pcspecs/${selectedPcSpec.id}/issue`, {
            issue: issueText || null,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setIssueDialogOpen(false);
            },
            onError: () => {
                toast.error('Failed to update issue');
            },
        });
    }

    function handleOpenNotesDialog(pcSpec: PcSpec) {
        setSelectedPcSpec(pcSpec);
        setNotesText(pcSpec.notes || '');
        setNotesDialogOpen(true);
    }

    function handleSaveNotes() {
        if (!selectedPcSpec) return;

        router.patch(`/pcspecs/${selectedPcSpec.id}/notes`, {
            notes: notesText || null,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setNotesDialogOpen(false);
            },
            onError: () => {
                toast.error('Failed to update notes');
            },
        });
    }

    // QR Code handlers
    const handleSelectAll = (checked: boolean) => {
        if (checked) {
            setSelectedPcIds(pcspecs.data.map(pc => pc.id));
        } else {
            setSelectedPcIds([]);
        }
    };

    const handleSelectPc = (id: number, checked: boolean) => {
        if (checked) {
            setSelectedPcIds(prev => [...prev, id]);
        } else {
            setSelectedPcIds(prev => prev.filter(pcId => pcId !== id));
        }
    };

    const handleBulkDownloadAllQRCodes = () => {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (!csrfToken) {
            toast.error('CSRF token not found. Please refresh the page.');
            return;
        }

        setBulkProgress({ running: true, percent: 0, status: 'Generating...' });
        toast.info('Generating QR codes...');

        fetch('/pcspecs/qrcode/bulk-all-stream', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify({
                format: 'png',
                size: 245,
                metadata: 0,
            }),
        })
            .then(res => {
                if (!res.ok) throw new Error('Failed to generate QR codes');
                const filename = res.headers.get('Content-Disposition')?.match(/filename="?(.+?)"?$/)?.[1] || 'pc-qrcodes-all.zip';
                return res.blob().then(blob => ({ blob, filename }));
            })
            .then(({ blob, filename }) => {
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
                toast.success('Download started');
                setBulkProgress({ running: false, percent: 100, status: 'Done' });
            })
            .catch(() => {
                toast.error('Failed to download QR codes');
                setBulkProgress({ running: false, percent: 0, status: '' });
            });
    };

    const handleDownloadSelectedQRCodes = () => {
        if (selectedPcIds.length === 0) {
            toast.error('No PCs selected');
            return;
        }

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (!csrfToken) {
            toast.error('CSRF token not found. Please refresh the page.');
            return;
        }

        setSelectedZipProgress({ running: true, percent: 0, status: 'Generating...' });
        toast.info('Generating selected QR codes...');

        fetch('/pcspecs/qrcode/zip-selected-stream', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify({
                pc_ids: selectedPcIds,
                format: 'png',
                size: 245,
                metadata: 0,
            }),
        })
            .then(res => {
                if (!res.ok) throw new Error('Failed to generate QR codes');
                const filename = res.headers.get('Content-Disposition')?.match(/filename="?(.+?)"?$/)?.[1] || 'pc-qrcodes-selected.zip';
                return res.blob().then(blob => ({ blob, filename }));
            })
            .then(({ blob, filename }) => {
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
                toast.success('Download started');
                setSelectedZipProgress({ running: false, percent: 100, status: 'Done' });
                setSelectedPcIds([]);
            })
            .catch(() => {
                toast.error('Failed to download QR codes');
                setSelectedZipProgress({ running: false, percent: 0, status: '' });
            });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                {/* Loading overlay for page transitions */}
                <LoadingOverlay isLoading={isLoading} />

                {/* Floating progress indicator for ALL */}
                {bulkProgress.running && (
                    <div className="fixed bottom-6 right-6 z-50 bg-white dark:bg-gray-900 border border-blue-400 shadow-lg rounded-lg px-6 py-4 flex flex-col gap-2 items-center">
                        <div className="font-semibold text-blue-700 dark:text-blue-200">
                            Generating QR Codes...
                        </div>
                        <div className="text-xs text-gray-700 dark:text-gray-300">
                            {bulkProgress.status}
                        </div>
                    </div>
                )}

                {/* Floating progress indicator for SELECTED */}
                {selectedZipProgress.running && (
                    <div className="fixed bottom-24 right-6 z-50 bg-white dark:bg-gray-900 border border-green-400 shadow-lg rounded-lg px-6 py-4 flex flex-col gap-2 items-center">
                        <div className="font-semibold text-green-700 dark:text-green-200">
                            Generating Selected QR Codes...
                        </div>
                        <div className="text-xs text-gray-700 dark:text-gray-300">
                            {selectedZipProgress.status}
                        </div>
                    </div>
                )}

                {/* Reusable page header with create button */}
                <PageHeader
                    title="PC Specs Management"
                    description="Manage complete PC specifications and configurations"
                />

                <div className="flex flex-col gap-4">
                    <div className="flex flex-col sm:flex-row gap-4 justify-between items-start sm:items-center">
                        <div className="w-full sm:w-auto flex-1 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            {/* Multi-select PC Search */}
                            <Popover open={isPcPopoverOpen} onOpenChange={setIsPcPopoverOpen}>
                                <PopoverTrigger asChild>
                                    <Button
                                        variant="outline"
                                        role="combobox"
                                        aria-expanded={isPcPopoverOpen}
                                        className="w-full justify-between font-normal"
                                    >
                                        <span className="truncate">
                                            {selectedFilterPcIds.length > 0
                                                ? `${selectedFilterPcIds.length} PC${selectedFilterPcIds.length !== 1 ? 's' : ''} selected`
                                                : 'Select PCs to filter...'}
                                        </span>
                                        <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent className="w-full min-w-75 p-0" align="start">
                                    <Command shouldFilter={false}>
                                        <CommandInput
                                            placeholder="Search PC specs..."
                                            value={pcSearchQuery}
                                            onValueChange={setPcSearchQuery}
                                        />
                                        <CommandList>
                                            <CommandEmpty>No PC specs found.</CommandEmpty>
                                            <CommandGroup>
                                                {filteredPcOptions.map((pc) => (
                                                    <CommandItem
                                                        key={pc.id}
                                                        value={pc.label}
                                                        onSelect={() => handleTogglePcSelect(pc.id)}
                                                        className="cursor-pointer"
                                                    >
                                                        <Check
                                                            className={`mr-2 h-4 w-4 ${selectedFilterPcIds.includes(pc.id) ? "opacity-100" : "opacity-0"}`}
                                                        />
                                                        {pc.label}
                                                    </CommandItem>
                                                ))}
                                            </CommandGroup>
                                        </CommandList>
                                    </Command>
                                </PopoverContent>
                            </Popover>

                            {/* Processor Filter (multi-select) */}
                            <Popover open={isProcessorPopoverOpen} onOpenChange={setIsProcessorPopoverOpen}>
                                <PopoverTrigger asChild>
                                    <Button
                                        variant="outline"
                                        role="combobox"
                                        aria-expanded={isProcessorPopoverOpen}
                                        className="w-full justify-between font-normal"
                                    >
                                        <span className="truncate">
                                            {selectedProcessorIds.length > 0
                                                ? `${selectedProcessorIds.length} processor${selectedProcessorIds.length !== 1 ? 's' : ''} selected`
                                                : 'All Processors'}
                                        </span>
                                        <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent className="w-full min-w-75 p-0" align="start">
                                    <Command shouldFilter={false}>
                                        <CommandInput
                                            placeholder="Search processor..."
                                            value={processorSearchQuery}
                                            onValueChange={setProcessorSearchQuery}
                                        />
                                        <CommandList>
                                            <CommandEmpty>No processor found.</CommandEmpty>
                                            <CommandGroup>
                                                {filteredProcessorOptions.map((proc) => (
                                                    <CommandItem
                                                        key={proc.id}
                                                        value={proc.label}
                                                        onSelect={() => handleToggleProcessorSelect(proc.id)}
                                                        className="cursor-pointer"
                                                    >
                                                        <Check
                                                            className={`mr-2 h-4 w-4 ${selectedProcessorIds.includes(proc.id) ? "opacity-100" : "opacity-0"}`}
                                                        />
                                                        {proc.label}
                                                        {proc.core_count != null && (
                                                            <span className="ml-auto text-xs text-muted-foreground">{proc.core_count != null ? `${proc.core_count}C` : ''}/{proc.thread_count != null ? `${proc.thread_count}T` : ''}</span>
                                                        )}
                                                    </CommandItem>
                                                ))}
                                            </CommandGroup>
                                        </CommandList>
                                    </Command>
                                </PopoverContent>
                            </Popover>

                            {/* PC Number Range Filter */}
                            <div className="flex items-center gap-1.5">
                                <div className="relative flex-1">
                                    <span className="absolute left-2.5 top-1/2 -translate-y-1/2 text-xs text-muted-foreground pointer-events-none">PC</span>
                                    <input
                                        type="number"
                                        min={1}
                                        placeholder="From"
                                        value={pcNumberFrom}
                                        onChange={e => setPcNumberFrom(e.target.value)}
                                        className="w-full pl-8 pr-2 h-9 rounded-md border border-input bg-background text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    />
                                </div>
                                <span className="text-muted-foreground text-sm shrink-0">–</span>
                                <div className="relative flex-1">
                                    <span className="absolute left-2.5 top-1/2 -translate-y-1/2 text-xs text-muted-foreground pointer-events-none">PC</span>
                                    <input
                                        type="number"
                                        min={1}
                                        placeholder="To"
                                        value={pcNumberTo}
                                        onChange={e => setPcNumberTo(e.target.value)}
                                        className="w-full pl-8 pr-2 h-9 rounded-md border border-input bg-background text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    />
                                </div>
                            </div>

                            <Select value={sortDir} onValueChange={(value: 'asc' | 'desc') => setSortDir(value)}>
                                <SelectTrigger className="w-full font-normal">
                                    <SelectValue placeholder="PC Number Order" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="asc">PC Number: Ascending (A-Z)</SelectItem>
                                    <SelectItem value="desc">PC Number: Descending (Z-A)</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="flex flex-wrap gap-2 w-full sm:w-auto">
                            <Button onClick={handleFilter} className="flex-1 sm:flex-none">
                                <Filter className="mr-2 h-4 w-4" />
                                Filter
                            </Button>
                            <Button variant="outline" onClick={handleReset} className="flex-1 sm:flex-none">
                                Reset
                            </Button>
                            <div className="flex gap-2">
                                <Button variant="ghost" size="icon" onClick={handleManualRefresh} title="Refresh">
                                    <RefreshCw className="h-4 w-4" />
                                </Button>
                                <Button
                                    variant={autoRefreshEnabled ? "default" : "ghost"}
                                    size="icon"
                                    onClick={() => setAutoRefreshEnabled(!autoRefreshEnabled)}
                                    title={autoRefreshEnabled ? "Disable auto-refresh" : "Enable auto-refresh (30s)"}
                                >
                                    {autoRefreshEnabled ? <Pause className="h-4 w-4" /> : <Play className="h-4 w-4" />}
                                </Button>
                            </div>
                            <Link href={pcSpecCreate.url() + editLinkSuffix}>
                                <Button className="flex-1 sm:flex-none">
                                    <Plus className="mr-2 h-4 w-4" />
                                    Add PC Spec
                                </Button>
                            </Link>
                        </div>
                    </div>

                    {/* Selected PC filter badges */}
                    {selectedFilterPcIds.length > 0 && (
                        <div className="flex flex-wrap gap-2">
                            {selectedFilterPcIds.map((pcId) => {
                                const pc = allPcSpecs.find(p => p.id === pcId);
                                return (
                                    <Badge key={pcId} variant="secondary" className="flex items-center gap-1 py-1 px-2">
                                        <span className="truncate max-w-50">{pc?.label || `PC #${pcId}`}</span>
                                        <button
                                            type="button"
                                            onClick={() => handleRemovePcFilter(pcId)}
                                            className="ml-1 rounded-full hover:bg-muted p-0.5"
                                            title="Remove filter"
                                        >
                                            <X className="h-3 w-3" />
                                        </button>
                                    </Badge>
                                );
                            })}
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => setSelectedFilterPcIds([])}
                                className="h-7 text-xs"
                            >
                                Clear all
                            </Button>
                        </div>
                    )}

                    <div className="flex justify-between items-center text-sm">
                        <div className="text-muted-foreground">
                            Showing {pcspecs.data.length} records
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Last updated: {lastRefresh.toLocaleTimeString()}
                        </div>
                    </div>
                </div>

                {/* QR Code Selection Actions */}
                {selectedPcIds.length > 0 && (
                    <div className="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 flex items-center justify-between">
                        <div className="flex items-center gap-4">
                            <span className="font-medium text-blue-900 dark:text-blue-100">
                                {selectedPcIds.length} PC{selectedPcIds.length !== 1 ? 's' : ''} selected
                            </span>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setSelectedPcIds([])}
                            >
                                Clear Selection
                            </Button>
                        </div>
                        <div className="flex gap-2">
                            <Button
                                onClick={handleDownloadSelectedQRCodes}
                                variant="outline"
                                className="border-green-600 text-green-600 hover:bg-green-600 hover:text-white dark:hover:text-white"
                                disabled={selectedZipProgress.running}
                            >
                                Download Selected QR Codes as ZIP
                            </Button>
                            <Button
                                onClick={handleBulkDownloadAllQRCodes}
                                className="bg-blue-700 hover:bg-blue-800 text-white"
                                disabled={bulkProgress.running}
                            >
                                Download All QR Codes as ZIP
                            </Button>
                        </div>
                    </div>
                )}

                {/* Desktop Table */}
                <div className="hidden md:block shadow rounded-md overflow-hidden">
                    {isLoading ? (
                        <TableSkeleton columns={10} rows={8} />
                    ) : (
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow className="bg-muted/50">
                                        <TableHead className="w-12">
                                            <Checkbox
                                                checked={selectedPcIds.length === pcspecs.data.length && pcspecs.data.length > 0}
                                                onCheckedChange={(checked) => handleSelectAll(checked === true)}
                                                aria-label="Select all PC specs"
                                            />
                                        </TableHead>
                                        <TableHead>PC Number</TableHead>
                                        <TableHead>Manufacturer</TableHead>
                                        <TableHead className="hidden xl:table-cell">Processor</TableHead>
                                        <TableHead className="hidden xl:table-cell">Cores</TableHead>
                                        <TableHead>RAM (GB)</TableHead>
                                        <TableHead>Disk (GB)</TableHead>
                                        <TableHead className="hidden xl:table-cell">Ports</TableHead>
                                        <TableHead className="hidden xl:table-cell">Notes</TableHead>
                                        <TableHead>Issue</TableHead>
                                        <TableHead className="text-center">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {pcspecs.data.map((pc) => {
                                        // Get first processor
                                        const proc = pc.processorSpecs?.[0];
                                        const procLabel = proc ? `${proc.manufacturer} ${proc.model}` : '—';

                                        return (
                                            <TableRow key={pc.id}>
                                                <TableCell>
                                                    <Checkbox
                                                        checked={selectedPcIds.includes(pc.id)}
                                                        onCheckedChange={(checked) => handleSelectPc(pc.id, checked === true)}
                                                        aria-label={`Select PC ${pc.pc_number || pc.id}`}
                                                    />
                                                </TableCell>
                                                <TableCell className="font-medium">
                                                    {pc.pc_number || <span className="text-gray-400">—</span>}
                                                </TableCell>
                                                <TableCell>{pc.manufacturer}</TableCell>
                                                <TableCell className="hidden xl:table-cell">{procLabel}</TableCell>
                                                <TableCell className="hidden xl:table-cell">
                                                    {proc?.core_count != null || proc?.thread_count != null
                                                        ? `${proc?.core_count ?? '?'}C/${proc?.thread_count ?? '?'}T`
                                                        : '—'}
                                                </TableCell>
                                                <TableCell>{pc.ram_gb}</TableCell>
                                                <TableCell>{pc.disk_gb}</TableCell>
                                                <TableCell className="hidden xl:table-cell">{pc.available_ports || '—'}</TableCell>
                                                <TableCell className="hidden xl:table-cell">
                                                    <div className="flex items-center gap-2">
                                                        {pc.notes ? (
                                                            <span className="text-xs truncate max-w-40 block" title={pc.notes}>{pc.notes}</span>
                                                        ) : (
                                                            <span className="text-xs text-gray-400">No notes</span>
                                                        )}
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() => handleOpenNotesDialog(pc)}
                                                            className="h-7 px-2 text-xs"
                                                        >
                                                            {pc.notes ? 'Edit' : 'Add'}
                                                        </Button>
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex items-center gap-2">
                                                        {pc.issue ? (
                                                            <span className="text-xs text-red-600 font-medium truncate max-w-50" title={pc.issue}>
                                                                ⚠️ {pc.issue}
                                                            </span>
                                                        ) : (
                                                            <span className="text-xs text-gray-400">No issue</span>
                                                        )}
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() => handleOpenIssueDialog(pc)}
                                                            className="h-7 px-2 text-xs"
                                                        >
                                                            {pc.issue ? 'Edit' : 'Add'}
                                                        </Button>
                                                    </div>
                                                </TableCell>
                                                <TableCell className="flex justify-center gap-2">
                                                    {/* Edit */}
                                                    <Link href={pcSpecEdit.url(pc.id) + editLinkSuffix}>
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            className="bg-green-600 hover:bg-green-700 text-white"
                                                        >
                                                            Edit
                                                        </Button>
                                                    </Link>

                                                    {/* Details Dialog */}
                                                    <Dialog>
                                                        <DialogTrigger asChild>
                                                            <Button variant="outline" size="sm">
                                                                Details
                                                            </Button>
                                                        </DialogTrigger>
                                                        <DialogContent className="max-w-[95vw] sm:max-w-lg w-full">
                                                            <DialogHeader>
                                                                <DialogTitle className="text-lg font-semibold">
                                                                    {pc.pc_number || `PC #${pc.id}`}
                                                                </DialogTitle>
                                                                <DialogDescription>
                                                                    {pc.manufacturer}
                                                                </DialogDescription>
                                                            </DialogHeader>

                                                            <div className="space-y-4 text-sm">
                                                                <div className="grid grid-cols-2 gap-x-6 gap-y-3 rounded-lg border p-4">
                                                                    <div>
                                                                        <span className="text-xs text-muted-foreground">Memory Type</span>
                                                                        <p className="font-medium">{pc.memory_type}</p>
                                                                    </div>
                                                                    <div>
                                                                        <span className="text-xs text-muted-foreground">RAM</span>
                                                                        <p className="font-medium">{pc.ram_gb} GB</p>
                                                                    </div>
                                                                    <div>
                                                                        <span className="text-xs text-muted-foreground">Disk</span>
                                                                        <p className="font-medium">{pc.disk_gb} GB</p>
                                                                    </div>
                                                                    <div>
                                                                        <span className="text-xs text-muted-foreground">Ports</span>
                                                                        <p className="font-medium">{pc.available_ports || 'N/A'}</p>
                                                                    </div>
                                                                    <div>
                                                                        <span className="text-xs text-muted-foreground">Bios Release Date</span>
                                                                        <p className="font-medium">{pc.bios_release_date || 'N/A'}</p>
                                                                    </div>
                                                                </div>

                                                                {pc.processorSpecs?.length ? (
                                                                    <div className="space-y-2">
                                                                        <h4 className="text-sm font-semibold">Processor</h4>
                                                                        {pc.processorSpecs.map((p) => (
                                                                            <div key={p.id} className="grid grid-cols-2 gap-x-6 gap-y-3 rounded-lg border p-4">
                                                                                <div className="col-span-2">
                                                                                    <span className="text-xs text-muted-foreground">Processor</span>
                                                                                    <p className="font-medium">{p.manufacturer} {p.model}</p>
                                                                                </div>
                                                                                <div>
                                                                                    <span className="text-xs text-muted-foreground">Cores / Threads</span>
                                                                                    <p className="font-medium">{p.core_count} / {p.thread_count}</p>
                                                                                </div>
                                                                                <div>
                                                                                    <span className="text-xs text-muted-foreground">Clock (Base / Boost)</span>
                                                                                    <p className="font-medium">{p.base_clock_ghz} / {p.boost_clock_ghz} GHz</p>
                                                                                </div>
                                                                            </div>
                                                                        ))}
                                                                    </div>
                                                                ) : (
                                                                    <p className="text-muted-foreground text-sm">No processor specs available.</p>
                                                                )}

                                                                {pc.issue && (
                                                                    <div className="rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-4">
                                                                        <span className="text-xs text-muted-foreground">Issue</span>
                                                                        <p className="font-medium text-red-600 dark:text-red-400">{pc.issue}</p>
                                                                    </div>
                                                                )}
                                                            </div>

                                                            <DialogClose asChild>
                                                                <Button variant="outline" className="mt-2 w-full">Close</Button>
                                                            </DialogClose>
                                                        </DialogContent>
                                                    </Dialog>

                                                    {/* Delete */}
                                                    <AlertDialog>
                                                        <AlertDialogTrigger asChild>
                                                            <Button
                                                                variant="destructive"
                                                                className="bg-red-600 hover:bg-red-700 text-white"
                                                            >
                                                                Delete
                                                            </Button>
                                                        </AlertDialogTrigger>
                                                        <AlertDialogContent>
                                                            <AlertDialogHeader>
                                                                <AlertDialogTitle>Confirm Delete</AlertDialogTitle>
                                                                <AlertDialogDescription>
                                                                    Are you sure you want to delete {pc.pc_number || pc.manufacturer}? This action cannot be undone.
                                                                </AlertDialogDescription>
                                                            </AlertDialogHeader>
                                                            <AlertDialogFooter className="flex-col sm:flex-row gap-2">
                                                                <AlertDialogCancel className="w-full sm:w-auto">Cancel</AlertDialogCancel>
                                                                <AlertDialogAction
                                                                    className="bg-red-600 hover:bg-red-700 w-full sm:w-auto"
                                                                    onClick={() => handleDelete(pc.id)}
                                                                >
                                                                    Yes, Delete
                                                                </AlertDialogAction>
                                                            </AlertDialogFooter>
                                                        </AlertDialogContent>
                                                    </AlertDialog>
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>

                                <TableFooter>
                                    <TableRow>
                                        <TableCell colSpan={11} className="text-center font-medium">
                                            PC Specs List
                                        </TableCell>
                                    </TableRow>
                                </TableFooter>
                            </Table>
                        </div>
                    )}
                </div>

                {/* Mobile Card View */}
                <div className="md:hidden space-y-4">
                    {pcspecs.data.map((pc) => {
                        const proc = pc.processorSpecs?.[0];
                        const procLabel = proc ? `${proc.manufacturer} ${proc.model}` : '—';

                        return (
                            <div key={pc.id} className="bg-card border rounded-lg p-4 shadow-sm space-y-3">
                                <div className="flex justify-between items-start">
                                    <div className="flex items-start gap-3 flex-1">
                                        <Checkbox
                                            checked={selectedPcIds.includes(pc.id)}
                                            onCheckedChange={(checked) => handleSelectPc(pc.id, checked === true)}
                                            className="mt-1"
                                            aria-label={`Select PC ${pc.pc_number || pc.id}`}
                                        />
                                        <div>
                                            <div className="text-xs text-muted-foreground">PC Number</div>
                                            <div className="font-bold text-blue-600">
                                                {pc.pc_number || <span className="text-gray-400">Not assigned</span>}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div className="flex justify-between items-start">
                                    <div>
                                        <div className="text-xs text-muted-foreground">Manufacturer</div>
                                        <div className="font-semibold text-lg">{pc.manufacturer}</div>
                                    </div>
                                </div>

                                <div className="space-y-2 text-sm">
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Processor:</span>
                                        <span className="font-medium text-right wrap-break-word max-w-[60%]">{procLabel}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Cores / Threads:</span>
                                        <span className="font-medium">
                                            {proc?.core_count != null || proc?.thread_count != null
                                                ? `${proc?.core_count ?? '?'}C/${proc?.thread_count ?? '?'}T`
                                                : '—'}
                                        </span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">RAM:</span>
                                        <span className="font-medium">{pc.ram_gb} GB</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Disk:</span>
                                        <span className="font-medium">{pc.disk_gb} GB</span>
                                    </div>
                                    {pc.available_ports && (
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">Ports:</span>
                                            <span className="font-medium text-right max-w-[60%]">{pc.available_ports}</span>
                                        </div>
                                    )}
                                    <div className="pt-2 border-t">
                                        <div className="flex justify-between items-start gap-2">
                                            <span className="text-muted-foreground">Issue:</span>
                                            <div className="flex items-center gap-2 flex-1 justify-end">
                                                {pc.issue ? (
                                                    <span className="text-xs text-red-600 font-medium truncate max-w-30" title={pc.issue}>
                                                        ⚠️ {pc.issue}
                                                    </span>
                                                ) : (
                                                    <span className="text-xs text-gray-400">No issue</span>
                                                )}
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => handleOpenIssueDialog(pc)}
                                                    className="h-7 px-2 text-xs"
                                                >
                                                    {pc.issue ? 'Edit' : 'Add'}
                                                </Button>
                                            </div>
                                        </div>
                                    </div>
                                    <div className="pt-1">
                                        <div className="flex justify-between items-start gap-2">
                                            <span className="text-muted-foreground">Notes:</span>
                                            <div className="flex items-center gap-2 flex-1 justify-end">
                                                {pc.notes ? (
                                                    <span className="text-xs text-muted-foreground truncate max-w-30" title={pc.notes}>
                                                        {pc.notes}
                                                    </span>
                                                ) : (
                                                    <span className="text-xs text-gray-400">No notes</span>
                                                )}
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => handleOpenNotesDialog(pc)}
                                                    className="h-7 px-2 text-xs"
                                                >
                                                    {pc.notes ? 'Edit' : 'Add'}
                                                </Button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div className="flex flex-col gap-2 pt-2 border-t">
                                    <div className="flex gap-2">
                                        <Link href={pcSpecEdit.url(pc.id) + editLinkSuffix} className="flex-1">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="bg-green-600 hover:bg-green-700 text-white w-full"
                                            >
                                                Edit
                                            </Button>
                                        </Link>
                                        <Dialog>
                                            <DialogTrigger asChild>
                                                <Button variant="outline" size="sm" className="flex-1">
                                                    Details
                                                </Button>
                                            </DialogTrigger>
                                            <DialogContent className="max-w-[95vw] sm:max-w-lg w-full">
                                                <DialogHeader>
                                                    <DialogTitle className="text-lg font-semibold">
                                                        {pc.pc_number || `PC #${pc.id}`}
                                                    </DialogTitle>
                                                    <DialogDescription>
                                                        {pc.manufacturer}
                                                    </DialogDescription>
                                                </DialogHeader>

                                                <div className="space-y-4 text-sm">
                                                    <div className="grid grid-cols-2 gap-x-6 gap-y-3 rounded-lg border p-4">
                                                        <div>
                                                            <span className="text-xs text-muted-foreground">Memory Type</span>
                                                            <p className="font-medium">{pc.memory_type}</p>
                                                        </div>
                                                        <div>
                                                            <span className="text-xs text-muted-foreground">RAM</span>
                                                            <p className="font-medium">{pc.ram_gb} GB</p>
                                                        </div>
                                                        <div>
                                                            <span className="text-xs text-muted-foreground">Disk</span>
                                                            <p className="font-medium">{pc.disk_gb} GB</p>
                                                        </div>
                                                        <div>
                                                            <span className="text-xs text-muted-foreground">Ports</span>
                                                            <p className="font-medium">{pc.available_ports || 'N/A'}</p>
                                                        </div>
                                                        <div>
                                                            <span className="text-xs text-muted-foreground">Bios Release Date</span>
                                                            <p className="font-medium">{pc.bios_release_date || 'N/A'}</p>
                                                        </div>
                                                    </div>

                                                    {pc.processorSpecs?.length ? (
                                                        <div className="space-y-2">
                                                            <h4 className="text-sm font-semibold">Processor</h4>
                                                            {pc.processorSpecs.map((p) => (
                                                                <div key={p.id} className="grid grid-cols-2 gap-x-6 gap-y-3 rounded-lg border p-4">
                                                                    <div className="col-span-2">
                                                                        <span className="text-xs text-muted-foreground">Processor</span>
                                                                        <p className="font-medium">{p.manufacturer} {p.model}</p>
                                                                    </div>
                                                                    <div>
                                                                        <span className="text-xs text-muted-foreground">Cores / Threads</span>
                                                                        <p className="font-medium">{p.core_count} / {p.thread_count}</p>
                                                                    </div>
                                                                    <div>
                                                                        <span className="text-xs text-muted-foreground">Clock (Base / Boost)</span>
                                                                        <p className="font-medium">{p.base_clock_ghz} / {p.boost_clock_ghz} GHz</p>
                                                                    </div>
                                                                </div>
                                                            ))}
                                                        </div>
                                                    ) : (
                                                        <p className="text-muted-foreground text-sm">No processor specs available.</p>
                                                    )}

                                                    {pc.issue && (
                                                        <div className="rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-4">
                                                            <span className="text-xs text-muted-foreground">Issue</span>
                                                            <p className="font-medium text-red-600 dark:text-red-400">{pc.issue}</p>
                                                        </div>
                                                    )}
                                                </div>

                                                <DialogClose asChild>
                                                    <Button variant="outline" className="mt-2 w-full">Close</Button>
                                                </DialogClose>
                                            </DialogContent>
                                        </Dialog>
                                    </div>
                                    <AlertDialog>
                                        <AlertDialogTrigger asChild>
                                            <Button
                                                variant="destructive"
                                                className="bg-red-600 hover:bg-red-700 text-white w-full"
                                            >
                                                Delete
                                            </Button>
                                        </AlertDialogTrigger>
                                        <AlertDialogContent className="max-w-[90vw] sm:max-w-lg">
                                            <AlertDialogHeader>
                                                <AlertDialogTitle>Confirm Delete</AlertDialogTitle>
                                                <AlertDialogDescription>
                                                    Are you sure you want to delete {pc.pc_number || pc.manufacturer}? This action cannot be undone.
                                                </AlertDialogDescription>
                                            </AlertDialogHeader>
                                            <AlertDialogFooter className="flex-col sm:flex-row gap-2">
                                                <AlertDialogCancel className="w-full sm:w-auto">Cancel</AlertDialogCancel>
                                                <AlertDialogAction
                                                    className="bg-red-600 hover:bg-red-700 w-full sm:w-auto"
                                                    onClick={() => handleDelete(pc.id)}
                                                >
                                                    Yes, Delete
                                                </AlertDialogAction>
                                            </AlertDialogFooter>
                                        </AlertDialogContent>
                                    </AlertDialog>
                                </div>
                            </div>
                        );
                    })}
                    {pcspecs.data.length === 0 && (
                        <div className="py-12 text-center text-gray-500 border rounded-lg bg-card">
                            No PC specs found
                        </div>
                    )}
                </div>

                {/* Pagination */}
                <div className="flex justify-center">
                    <PaginationNav links={pcspecs.links} />
                </div>

                {/* Issue Dialog */}
                <Dialog open={issueDialogOpen} onOpenChange={setIssueDialogOpen}>
                    <DialogContent className="max-w-[90vw] sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>Manage Issue Note</DialogTitle>
                            <DialogDescription>
                                {selectedPcSpec && (
                                    <span className="text-sm wrap-break-word">
                                        {selectedPcSpec.pc_number || selectedPcSpec.manufacturer}
                                    </span>
                                )}
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-4 py-4">
                            <div className="space-y-2">
                                <Label htmlFor="issue">Issue Details</Label>
                                <Textarea
                                    id="issue"
                                    placeholder="Describe any issues with this PC spec..."
                                    value={issueText}
                                    onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setIssueText(e.target.value)}
                                    rows={5}
                                    className="resize-none"
                                />
                                <p className="text-xs text-gray-500">
                                    Leave empty to remove the issue note.
                                </p>
                            </div>
                        </div>
                        <div className="flex flex-col sm:flex-row justify-end gap-2">
                            <Button variant="outline" onClick={() => setIssueDialogOpen(false)} className="w-full sm:w-auto">
                                Cancel
                            </Button>
                            <Button onClick={handleSaveIssue} className="w-full sm:w-auto">
                                Save Issue
                            </Button>
                        </div>
                    </DialogContent>
                </Dialog>

                {/* Notes Dialog */}
                <Dialog open={notesDialogOpen} onOpenChange={setNotesDialogOpen}>
                    <DialogContent className="max-w-[90vw] sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>Manage Notes</DialogTitle>
                            <DialogDescription>
                                {selectedPcSpec && (
                                    <span className="text-sm wrap-break-word">
                                        {selectedPcSpec.pc_number || `PC #${selectedPcSpec.id}`} — {selectedPcSpec.manufacturer}
                                    </span>
                                )}
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-4 py-4">
                            <div className="space-y-2">
                                <Label htmlFor="notes">Notes</Label>
                                <Textarea
                                    id="notes"
                                    placeholder="Add notes for this PC spec..."
                                    value={notesText}
                                    onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setNotesText(e.target.value)}
                                    rows={5}
                                    className="resize-none"
                                />
                                <p className="text-xs text-gray-500">
                                    Leave empty to remove the notes.
                                </p>
                            </div>
                        </div>
                        <div className="flex flex-col sm:flex-row justify-end gap-2">
                            <Button variant="outline" onClick={() => setNotesDialogOpen(false)} className="w-full sm:w-auto">
                                Cancel
                            </Button>
                            <Button onClick={handleSaveNotes} className="w-full sm:w-auto">
                                Save Notes
                            </Button>
                        </div>
                    </DialogContent>
                </Dialog>

            </div>
        </AppLayout>
    );
}
