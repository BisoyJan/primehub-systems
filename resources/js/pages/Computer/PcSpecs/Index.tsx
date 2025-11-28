import { useState, useEffect, useRef } from 'react';
import { Head, Link, useForm, usePage, router } from '@inertiajs/react';
import type { PageProps as InertiaPageProps } from "@inertiajs/core";
import { toast } from 'sonner';

import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import PaginationNav, { PaginationLink } from '@/components/pagination-nav';
import { RefreshCw, Search, Filter, Plus } from 'lucide-react';

// New reusable components and hooks
import { usePageMeta, useFlashMessage, usePageLoading } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { LoadingOverlay } from "@/components/LoadingOverlay";

import {
    index as pcSpecIndex,
    create as pcSpecCreate,
    edit as pcSpecEdit,
    destroy as pcSpecDestroy,
} from '@/routes/pcspecs';


interface RamSpec {
    id: number;
    manufacturer: string;
    model: string;
    capacity_gb: number;
    type: string;
    speed: string;
    form_factor: string;
    voltage: number;
}
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
interface ProcessorSpec {
    id: number;
    manufacturer: string;
    model: string;
    socket_type: string;
    core_count: number;
    thread_count: number;
    base_clock_ghz: number;
    boost_clock_ghz: number;
    integrated_graphics: string;
    tdp_watts: number;
}
interface PcSpec {
    id: number;
    pc_number?: string | null;
    manufacturer: string;
    model: string;
    chipset: string;
    memory_type: string;
    form_factor: string;
    socket_type: string;
    issue?: string | null;
    ramSpecs: RamSpec[];
    diskSpecs: DiskSpec[];
    processorSpecs: ProcessorSpec[];
}

interface PaginatedPcSpecs {
    data: PcSpec[];
    links: PaginationLink[];
}

interface Props extends InertiaPageProps {
    flash?: { message?: string; type?: string };
    pcspecs: PaginatedPcSpecs;
    search?: string;
}



export default function Index() {
    const { pcspecs, search: initialSearch } = usePage<Props>().props;
    const form = useForm({}); // Keep useForm for delete but empty for search
    const [issueDialogOpen, setIssueDialogOpen] = useState(false);
    const [selectedPcSpec, setSelectedPcSpec] = useState<PcSpec | null>(null);
    const [issueText, setIssueText] = useState('');

    const [searchQuery, setSearchQuery] = useState(initialSearch || "");
    const [lastRefresh, setLastRefresh] = useState(new Date());

    const handleManualRefresh = () => {
        setLastRefresh(new Date());
        router.reload({ only: ['pcspecs'] });
    };

    const handleFilter = () => {
        router.get(
            pcSpecIndex().url,
            { search: searchQuery },
            {
                preserveState: true,
                preserveScroll: true,
            }
        );
    };

    const handleReset = () => {
        setSearchQuery("");
        router.get(pcSpecIndex().url);
    };

    const [selectedZipProgress, setSelectedZipProgress] = useState<{
        running: boolean;
        percent: number;
        status: string;
        downloadUrl?: string;
        jobId?: string;
    }>({ running: false, percent: 0, status: '' });

    const [bulkProgress, setBulkProgress] = useState<{
        running: boolean;
        percent: number;
        status: string;
        downloadUrl?: string;
        jobId?: string;
    }>({ running: false, percent: 0, status: '' });

    const progressIntervalRef = useRef<NodeJS.Timeout | null>(null);
    const bulkProgressRef = useRef<HTMLDivElement>(null);
    const selectedProgressRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (bulkProgressRef.current) {
            bulkProgressRef.current.style.width = `${bulkProgress.percent}%`;
        }
    }, [bulkProgress.percent]);

    useEffect(() => {
        if (selectedProgressRef.current) {
            selectedProgressRef.current.style.width = `${selectedZipProgress.percent}%`;
        }
    }, [selectedZipProgress.percent]);

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

    // Auto-clear selection after 15 minutes of inactivity
    useEffect(() => {
        const expiryTime = 15 * 60 * 1000; // 15 minutes
        const clearTimer = setTimeout(() => {
            if (selectedPcIds.length > 0) {
                setSelectedPcIds([]);
                toast.info('QR code selection cleared after 15 minutes of inactivity');
            }
        }, expiryTime);

        return () => clearTimeout(clearTimer);
    }, [selectedPcIds]);

    const selectedZipIntervalRef = useRef<NodeJS.Timeout | null>(null);

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
        // Get CSRF token
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (!csrfToken) {
            toast.error('CSRF token not found. Please refresh the page.');
            return;
        }

        toast.info('Preparing bulk QR code ZIP...');

        // Start bulk job
        fetch('/pcspecs/qrcode/bulk-all', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify({
                format: 'png',
                size: 256,
                metadata: 0,
            }),
        })
            .then(res => res.json())
            .then(data => {
                if (data.jobId) {
                    setBulkProgress({
                        running: true,
                        percent: 0,
                        status: 'Starting...',
                        jobId: data.jobId,
                    });
                } else {
                    toast.error('Failed to start bulk download');
                }
            })
            .catch(() => toast.error('Failed to start bulk download'));
    };

    useEffect(() => {
        if (bulkProgress.running && bulkProgress.jobId) {
            if (progressIntervalRef.current) {
                clearInterval(progressIntervalRef.current);
                progressIntervalRef.current = null;
            }

            // Poll immediately first, then every 500ms
            const pollProgress = () => {
                fetch(`/pcspecs/qrcode/bulk-progress/${bulkProgress.jobId}`)
                    .then(res => {
                        if (!res.ok) throw new Error('Failed to fetch progress');
                        return res.json();
                    })
                    .then(data => {
                        setBulkProgress(prev => ({
                            ...prev,
                            percent: data.percent || 0,
                            status: data.status || 'Processing...',
                            downloadUrl: data.downloadUrl,
                            running: !data.finished,
                        }));
                        if (data.finished) {
                            if (progressIntervalRef.current) {
                                clearInterval(progressIntervalRef.current);
                                progressIntervalRef.current = null;
                            }
                            if (data.downloadUrl) {
                                window.location.href = data.downloadUrl;
                                toast.success('Download started');
                            }
                        }
                    })
                    .catch(err => {
                        console.error('Progress fetch error:', err);
                        toast.error('Failed to fetch progress');
                    });
            };

            // Poll immediately
            pollProgress();

            // Then poll every 500ms for faster updates
            progressIntervalRef.current = setInterval(pollProgress, 500);
        }
        return () => {
            if (progressIntervalRef.current) {
                clearInterval(progressIntervalRef.current);
                progressIntervalRef.current = null;
            }
        };
    }, [bulkProgress.running, bulkProgress.jobId]);

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

        toast.info('Preparing selected QR code ZIP...');

        fetch('/pcspecs/qrcode/zip-selected', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify({
                pc_ids: selectedPcIds,
                format: 'png',
                size: 256,
                metadata: 0,
            }),
        })
            .then(res => res.json())
            .then(data => {
                if (data.jobId) {
                    setSelectedZipProgress({
                        running: true,
                        percent: 0,
                        status: 'Starting...',
                        jobId: data.jobId,
                    });
                } else if (data.downloadUrl) {
                    window.location.href = data.downloadUrl;
                    toast.success('Download started');
                } else {
                    toast.error('Failed to start selected ZIP download');
                }
            })
            .catch(() => toast.error('Failed to start selected ZIP download'));
    };

    // Poll progress for selected ZIP every 500ms
    useEffect(() => {
        if (selectedZipProgress.running && selectedZipProgress.jobId) {
            if (selectedZipIntervalRef.current) {
                clearInterval(selectedZipIntervalRef.current);
                selectedZipIntervalRef.current = null;
            }

            // Poll immediately first, then every 500ms
            const pollProgress = () => {
                fetch(`/pcspecs/qrcode/selected-progress/${selectedZipProgress.jobId}`)
                    .then(res => {
                        if (!res.ok) throw new Error('Failed to fetch progress');
                        return res.json();
                    })
                    .then(data => {
                        setSelectedZipProgress(prev => ({
                            ...prev,
                            percent: data.percent || 0,
                            status: data.status || 'Processing...',
                            downloadUrl: data.downloadUrl,
                            running: !data.finished,
                        }));
                        if (data.finished) {
                            if (selectedZipIntervalRef.current) {
                                clearInterval(selectedZipIntervalRef.current);
                                selectedZipIntervalRef.current = null;
                            }
                            if (data.downloadUrl) {
                                window.location.href = data.downloadUrl;
                                toast.success('Download started');
                            }
                        }
                    })
                    .catch(err => {
                        console.error('Progress fetch error:', err);
                        toast.error('Failed to fetch progress');
                    });
            };

            // Poll immediately
            pollProgress();

            // Then poll every 500ms for faster updates
            selectedZipIntervalRef.current = setInterval(pollProgress, 500);
        }
        return () => {
            if (selectedZipIntervalRef.current) {
                clearInterval(selectedZipIntervalRef.current);
                selectedZipIntervalRef.current = null;
            }
        };
    }, [selectedZipProgress.running, selectedZipProgress.jobId]);

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
                            Bulk QR Code ZIP Progress
                        </div>
                        <div className="w-full bg-gray-200 rounded h-3 mb-2">
                            <div
                                ref={bulkProgressRef}
                                className="bg-blue-600 h-3 rounded"
                            />
                        </div>
                        <div className="text-xs text-gray-700 dark:text-gray-300">
                            {bulkProgress.status} ({bulkProgress.percent}%)
                        </div>
                    </div>
                )}

                {/* Floating progress indicator for SELECTED */}
                {selectedZipProgress.running && (
                    <div className="fixed bottom-24 right-6 z-50 bg-white dark:bg-gray-900 border border-green-400 shadow-lg rounded-lg px-6 py-4 flex flex-col gap-2 items-center">
                        <div className="font-semibold text-green-700 dark:text-green-200">
                            Selected QR Code ZIP Progress
                        </div>
                        <div className="w-full bg-gray-200 rounded h-3 mb-2">
                            <div
                                ref={selectedProgressRef}
                                className="bg-green-600 h-3 rounded"
                            />
                        </div>
                        <div className="text-xs text-gray-700 dark:text-gray-300">
                            {selectedZipProgress.status} ({selectedZipProgress.percent}%)
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
                            <div className="relative">
                                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search PC specs..."
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                    className="pl-8"
                                />
                            </div>
                        </div>

                        <div className="flex gap-2 w-full sm:w-auto">
                            <Button onClick={handleFilter} className="flex-1 sm:flex-none">
                                <Filter className="mr-2 h-4 w-4" />
                                Filter
                            </Button>
                            <Button variant="outline" onClick={handleReset} className="flex-1 sm:flex-none">
                                Reset
                            </Button>
                            <Button variant="ghost" size="icon" onClick={handleManualRefresh} title="Refresh">
                                <RefreshCw className="h-4 w-4" />
                            </Button>
                            <Link href={pcSpecCreate.url()}>
                                <Button className="flex-1 sm:flex-none">
                                    <Plus className="mr-2 h-4 w-4" />
                                    Add PC Spec
                                </Button>
                            </Link>
                        </div>
                    </div>

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
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-12">
                                        <Checkbox
                                            checked={selectedPcIds.length === pcspecs.data.length && pcspecs.data.length > 0}
                                            onCheckedChange={(checked) => handleSelectAll(checked === true)}
                                            aria-label="Select all PC specs"
                                        />
                                    </TableHead>
                                    <TableHead className="hidden lg:table-cell">ID</TableHead>
                                    <TableHead>PC Number</TableHead>
                                    <TableHead>Manufacturer</TableHead>
                                    <TableHead>Model</TableHead>
                                    <TableHead className="hidden xl:table-cell">Processor</TableHead>
                                    <TableHead>RAM (GB)</TableHead>
                                    <TableHead className="hidden xl:table-cell">Storage Type</TableHead>
                                    <TableHead>Issue</TableHead>
                                    <TableHead className="text-center">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {pcspecs.data.map((pc) => {
                                    // Get first processor
                                    const proc = pc.processorSpecs?.[0];
                                    const procLabel = proc ? `${proc.manufacturer} ${proc.model}` : '—';

                                    // Total RAM GB
                                    const totalRamGb = pc.ramSpecs?.reduce((sum, r) => sum + (r.capacity_gb || 0), 0);

                                    // Storage Type: show SSD if any disk is SSD, else HDD if any disk is HDD, else —
                                    let storageType = '—';
                                    if (pc.diskSpecs?.length) {
                                        const hasSSD = pc.diskSpecs.some(d => (d.drive_type || '').toUpperCase().includes('SSD'));
                                        const hasHDD = pc.diskSpecs.some(d => (d.drive_type || '').toUpperCase().includes('HDD'));
                                        if (hasSSD && hasHDD) storageType = 'SSD + HDD';
                                        else if (hasSSD) storageType = 'SSD';
                                        else if (hasHDD) storageType = 'HDD';
                                    }

                                    return (
                                        <TableRow key={pc.id}>
                                            <TableCell>
                                                <Checkbox
                                                    checked={selectedPcIds.includes(pc.id)}
                                                    onCheckedChange={(checked) => handleSelectPc(pc.id, checked === true)}
                                                    aria-label={`Select PC ${pc.pc_number || pc.id}`}
                                                />
                                            </TableCell>
                                            <TableCell className="hidden lg:table-cell">{pc.id}</TableCell>
                                            <TableCell className="font-medium">
                                                {pc.pc_number || <span className="text-gray-400">—</span>}
                                            </TableCell>
                                            <TableCell>{pc.manufacturer}</TableCell>
                                            <TableCell>{pc.model}</TableCell>
                                            <TableCell className="hidden xl:table-cell">{procLabel}</TableCell>
                                            <TableCell>{totalRamGb || 0}</TableCell>
                                            <TableCell className="hidden xl:table-cell">{storageType}</TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    {pc.issue ? (
                                                        <span className="text-xs text-red-600 font-medium truncate max-w-[200px]" title={pc.issue}>
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
                                                <Link href={pcSpecEdit.url(pc.id)}>
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
                                                    <DialogContent className="max-w-[95vw] sm:max-w-7xl w-full h-[90vh] flex flex-col">
                                                        <DialogTitle className="text-lg sm:text-xl font-semibold break-words">
                                                            {pc.manufacturer} {pc.model} — Full Specifications
                                                        </DialogTitle>

                                                        {/* Scrollable body */}
                                                        <div className="flex-1 overflow-y-auto pr-2 mt-4 space-y-6 text-sm">
                                                            {/* Motherboard Core Info */}
                                                            <section>
                                                                <h3 className="font-semibold text-base mb-2">PC Spec Details</h3>
                                                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2">
                                                                    <p><span className="font-medium">Manufacturer:</span> {pc.manufacturer}</p>
                                                                    <p><span className="font-medium">Model:</span> {pc.model}</p>
                                                                    <p><span className="font-medium">Memory Type:</span> {pc.memory_type}</p>
                                                                    <p><span className="font-medium">Form Factor:</span> {pc.form_factor ?? "—"}</p>
                                                                </div>
                                                            </section>

                                                            {/* RAM Specs */}
                                                            <section>
                                                                <h3 className="font-semibold text-base mb-2">RAM Specs</h3>
                                                                {pc.ramSpecs?.length ? (
                                                                    <Table>
                                                                        <TableHeader>
                                                                            <TableRow>
                                                                                <TableHead>Manufacturer</TableHead>
                                                                                <TableHead>Model</TableHead>
                                                                                <TableHead>Capacity</TableHead>
                                                                                <TableHead>Type</TableHead>
                                                                                <TableHead>Speed</TableHead>
                                                                            </TableRow>
                                                                        </TableHeader>
                                                                        <TableBody>
                                                                            {pc.ramSpecs.map((r) => (
                                                                                <TableRow key={r.id}>
                                                                                    <TableCell>{r.manufacturer}</TableCell>
                                                                                    <TableCell>{r.model}</TableCell>
                                                                                    <TableCell>{r.capacity_gb} GB</TableCell>
                                                                                    <TableCell>{r.type}</TableCell>
                                                                                    <TableCell>{r.speed}</TableCell>
                                                                                </TableRow>
                                                                            ))}
                                                                        </TableBody>
                                                                    </Table>
                                                                ) : (
                                                                    <p className="text-muted-foreground">No RAM specs available.</p>
                                                                )}
                                                            </section>

                                                            {/* Disk Specs */}
                                                            <section>
                                                                <h3 className="font-semibold text-base mb-2">Disk Specs</h3>
                                                                {pc.diskSpecs?.length ? (
                                                                    <Table>
                                                                        <TableHeader>
                                                                            <TableRow>
                                                                                <TableHead>Manufacturer</TableHead>
                                                                                <TableHead>Model</TableHead>
                                                                                <TableHead>Capacity</TableHead>
                                                                                <TableHead>Type</TableHead>
                                                                                <TableHead>Interface</TableHead>
                                                                                <TableHead>Read</TableHead>
                                                                                <TableHead>Write</TableHead>
                                                                            </TableRow>
                                                                        </TableHeader>
                                                                        <TableBody>
                                                                            {pc.diskSpecs.map((d) => (
                                                                                <TableRow key={d.id}>
                                                                                    <TableCell>{d.manufacturer}</TableCell>
                                                                                    <TableCell>{d.model}</TableCell>
                                                                                    <TableCell>{d.capacity_gb} GB</TableCell>
                                                                                    <TableCell>{d.drive_type}</TableCell>
                                                                                    <TableCell>{d.interface}</TableCell>
                                                                                    <TableCell>{d.sequential_read_mb} MB/s</TableCell>
                                                                                    <TableCell>{d.sequential_write_mb} MB/s</TableCell>
                                                                                </TableRow>
                                                                            ))}
                                                                        </TableBody>
                                                                    </Table>
                                                                ) : (
                                                                    <p className="text-muted-foreground">No disk specs available.</p>
                                                                )}
                                                            </section>

                                                            {/* Processor Specs */}
                                                            <section>
                                                                <h3 className="font-semibold text-base mb-2">Processor Specs</h3>
                                                                {pc.processorSpecs?.length ? (
                                                                    <Table>
                                                                        <TableHeader>
                                                                            <TableRow>
                                                                                <TableHead>manufacturer</TableHead>
                                                                                <TableHead>model</TableHead>
                                                                                <TableHead>Socket</TableHead>
                                                                                <TableHead>Cores</TableHead>
                                                                                <TableHead>Threads</TableHead>
                                                                                <TableHead>Base Clock</TableHead>
                                                                                <TableHead>Boost Clock</TableHead>
                                                                                <TableHead>TDP</TableHead>
                                                                            </TableRow>
                                                                        </TableHeader>
                                                                        <TableBody>
                                                                            {pc.processorSpecs.map((p) => (
                                                                                <TableRow key={p.id}>
                                                                                    <TableCell>{p.manufacturer}</TableCell>
                                                                                    <TableCell>{p.model}</TableCell>
                                                                                    <TableCell>{p.socket_type}</TableCell>
                                                                                    <TableCell>{p.core_count}</TableCell>
                                                                                    <TableCell>{p.thread_count}</TableCell>
                                                                                    <TableCell>{p.base_clock_ghz} GHz</TableCell>
                                                                                    <TableCell>{p.boost_clock_ghz} GHz</TableCell>
                                                                                    <TableCell>{p.tdp_watts} W</TableCell>
                                                                                </TableRow>
                                                                            ))}
                                                                        </TableBody>
                                                                    </Table>
                                                                ) : (
                                                                    <p className="text-muted-foreground">No processor specs available.</p>
                                                                )}
                                                            </section>
                                                        </div>

                                                        {/* Footer */}
                                                        <DialogClose asChild>
                                                            <Button className="mt-4 self-end">Close</Button>
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
                                                                Are you sure you want to delete {pc.manufacturer} {pc.model}? This action cannot be undone.
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
                                    <TableCell colSpan={10} className="text-center font-medium">
                                        PC Specs List
                                    </TableCell>
                                </TableRow>
                            </TableFooter>
                        </Table>
                    </div>
                </div>

                {/* Mobile Card View */}
                <div className="md:hidden space-y-4">
                    {pcspecs.data.map((pc) => {
                        const proc = pc.processorSpecs?.[0];
                        const procLabel = proc ? `${proc.manufacturer} ${proc.model}` : '—';
                        const totalRamGb = pc.ramSpecs?.reduce((sum, r) => sum + (r.capacity_gb || 0), 0);
                        let storageType = '—';
                        if (pc.diskSpecs?.length) {
                            const hasSSD = pc.diskSpecs.some(d => (d.drive_type || '').toUpperCase().includes('SSD'));
                            const hasHDD = pc.diskSpecs.some(d => (d.drive_type || '').toUpperCase().includes('HDD'));
                            if (hasSSD && hasHDD) storageType = 'SSD + HDD';
                            else if (hasSSD) storageType = 'SSD';
                            else if (hasHDD) storageType = 'HDD';
                        }

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
                                    <div className="text-right">
                                        <div className="text-xs text-muted-foreground">ID</div>
                                        <div className="font-medium text-sm">#{pc.id}</div>
                                    </div>
                                </div>

                                <div className="flex justify-between items-start">
                                    <div>
                                        <div className="text-xs text-muted-foreground">Manufacturer</div>
                                        <div className="font-semibold text-lg">{pc.manufacturer}</div>
                                    </div>
                                    <div className="text-right">
                                        <div className="text-xs text-muted-foreground">Model</div>
                                        <div className="font-medium text-sm">{pc.model}</div>
                                    </div>
                                </div>

                                <div className="space-y-2 text-sm">
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Processor:</span>
                                        <span className="font-medium text-right break-words max-w-[60%]">{procLabel}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">RAM:</span>
                                        <span className="font-medium">{totalRamGb || 0} GB</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Storage:</span>
                                        <span className="font-medium">{storageType}</span>
                                    </div>
                                    <div className="pt-2 border-t">
                                        <div className="flex justify-between items-start gap-2">
                                            <span className="text-muted-foreground">Issue:</span>
                                            <div className="flex items-center gap-2 flex-1 justify-end">
                                                {pc.issue ? (
                                                    <span className="text-xs text-red-600 font-medium truncate max-w-[120px]" title={pc.issue}>
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
                                </div>

                                <div className="flex flex-col gap-2 pt-2 border-t">
                                    <div className="flex gap-2">
                                        <Link href={pcSpecEdit.url(pc.id)} className="flex-1">
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
                                            <DialogContent className="max-w-[95vw] sm:max-w-7xl w-full h-[90vh] flex flex-col">
                                                <DialogTitle className="text-lg sm:text-xl font-semibold break-words">
                                                    {pc.manufacturer} {pc.model} — Full Specifications
                                                </DialogTitle>

                                                <div className="flex-1 overflow-y-auto pr-2 mt-4 space-y-6 text-sm">
                                                    <section>
                                                        <h3 className="font-semibold text-base mb-2">PC Spec Details</h3>
                                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2">
                                                            <p><span className="font-medium">Manufacturer:</span> {pc.manufacturer}</p>
                                                            <p><span className="font-medium">Model:</span> {pc.model}</p>
                                                            <p><span className="font-medium">Memory Type:</span> {pc.memory_type}</p>
                                                            <p><span className="font-medium">Form Factor:</span> {pc.form_factor ?? "—"}</p>
                                                        </div>
                                                    </section>

                                                    <section>
                                                        <h3 className="font-semibold text-base mb-2">RAM Specs</h3>
                                                        {pc.ramSpecs?.length ? (
                                                            <div className="space-y-2">
                                                                {pc.ramSpecs.map((r) => (
                                                                    <div key={r.id} className="border p-3 rounded text-sm">
                                                                        <div className="font-medium">{r.manufacturer} {r.model}</div>
                                                                        <div className="text-muted-foreground">
                                                                            {r.capacity_gb} GB • {r.type} • {r.speed}
                                                                        </div>
                                                                    </div>
                                                                ))}
                                                            </div>
                                                        ) : (
                                                            <p className="text-muted-foreground">No RAM specs available.</p>
                                                        )}
                                                    </section>

                                                    <section>
                                                        <h3 className="font-semibold text-base mb-2">Disk Specs</h3>
                                                        {pc.diskSpecs?.length ? (
                                                            <div className="space-y-2">
                                                                {pc.diskSpecs.map((d) => (
                                                                    <div key={d.id} className="border p-3 rounded text-sm">
                                                                        <div className="font-medium">{d.manufacturer} {d.model}</div>
                                                                        <div className="text-muted-foreground">
                                                                            {d.capacity_gb} GB • {d.drive_type} • {d.interface}
                                                                        </div>
                                                                        <div className="text-muted-foreground text-xs">
                                                                            Read: {d.sequential_read_mb} MB/s • Write: {d.sequential_write_mb} MB/s
                                                                        </div>
                                                                    </div>
                                                                ))}
                                                            </div>
                                                        ) : (
                                                            <p className="text-muted-foreground">No disk specs available.</p>
                                                        )}
                                                    </section>

                                                    <section>
                                                        <h3 className="font-semibold text-base mb-2">Processor Specs</h3>
                                                        {pc.processorSpecs?.length ? (
                                                            <div className="space-y-2">
                                                                {pc.processorSpecs.map((p) => (
                                                                    <div key={p.id} className="border p-3 rounded text-sm">
                                                                        <div className="font-medium">{p.manufacturer} {p.model}</div>
                                                                        <div className="text-muted-foreground">
                                                                            {p.socket_type} • {p.core_count} cores / {p.thread_count} threads
                                                                        </div>
                                                                        <div className="text-muted-foreground text-xs">
                                                                            {p.base_clock_ghz} GHz - {p.boost_clock_ghz} GHz • TDP: {p.tdp_watts}W
                                                                        </div>
                                                                    </div>
                                                                ))}
                                                            </div>
                                                        ) : (
                                                            <p className="text-muted-foreground">No processor specs available.</p>
                                                        )}
                                                    </section>
                                                </div>

                                                <DialogClose asChild>
                                                    <Button className="mt-4 self-end">Close</Button>
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
                                                    Are you sure you want to delete {pc.manufacturer} {pc.model}? This action cannot be undone.
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
                                    <span className="text-sm break-words">
                                        {selectedPcSpec.manufacturer} {selectedPcSpec.model}
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

            </div>
        </AppLayout>
    );
}
