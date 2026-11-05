import type { PageProps as InertiaPageProps } from '@inertiajs/core';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

import PaginationNav, { PaginationLink } from '@/components/pagination-nav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import {
    Check,
    ChevronsUpDown,
    Filter,
    Pause,
    Play,
    Plus,
    RefreshCw,
    X,
} from 'lucide-react';

// New reusable components and hooks
import { DeleteConfirmDialog } from '@/components/DeleteConfirmDialog';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { PageHeader } from '@/components/PageHeader';
import { TableSkeleton } from '@/components/TableSkeleton';
import { Can } from '@/components/authorization';
import {
    useFlashMessage,
    usePageLoading,
    usePageMeta,
    withReturnTo,
} from '@/hooks';
import { usePermission } from '@/hooks/useAuthorization';

import { create, destroy, edit, index } from '@/routes/processorspecs';

interface ProcessorSpec {
    id: number;
    manufacturer: string;
    model: string;
    core_count: number;
    thread_count: number;
    base_clock_ghz: number;
    boost_clock_ghz: number;
}

interface PaginatedProcessorSpecs {
    data: ProcessorSpec[];
    links: PaginationLink[];
}

interface ProcessorOption {
    id: number;
    label: string;
}

interface Props extends InertiaPageProps {
    flash?: { message?: string; type?: string };
    processorspecs: PaginatedProcessorSpecs;
    allProcessors: ProcessorOption[];
    filters: {
        processor_ids: number[];
    };
}

export default function Index() {
    const {
        processorspecs,
        allProcessors = [],
        filters = { processor_ids: [] },
    } = usePage<Props>().props;
    const form = useForm({}); // Keep useForm for delete

    // Use new hooks for cleaner code
    const { title, breadcrumbs } = usePageMeta({
        title: 'Processor Specifications',
        breadcrumbs: [{ title: 'Processor Specifications', href: index().url }],
    });

    useFlashMessage(); // Automatically handles flash messages
    const isLoading = usePageLoading(); // Track page loading state
    const { can } = usePermission(); // Check permissions

    // Multi-select processor search state
    const [processorSearchQuery, setProcessorSearchQuery] = useState('');
    const [isProcessorPopoverOpen, setIsProcessorPopoverOpen] = useState(false);
    const [selectedFilterProcessorIds, setSelectedFilterProcessorIds] =
        useState<number[]>(
            Array.isArray(filters.processor_ids)
                ? filters.processor_ids.map(Number)
                : [],
        );

    const [lastRefresh, setLastRefresh] = useState(new Date());
    const [autoRefreshEnabled, setAutoRefreshEnabled] = useState(false);

    // Filter processor options by search query
    const filteredProcessorOptions = useMemo(() => {
        if (!processorSearchQuery) return allProcessors;
        const lower = processorSearchQuery.toLowerCase();
        return allProcessors.filter((p) =>
            p.label.toLowerCase().includes(lower),
        );
    }, [allProcessors, processorSearchQuery]);

    const handleToggleProcessorSelect = (processorId: number) => {
        setSelectedFilterProcessorIds((prev) =>
            prev.includes(processorId)
                ? prev.filter((id) => id !== processorId)
                : [...prev, processorId],
        );
    };

    const handleRemoveProcessorFilter = (processorId: number) => {
        setSelectedFilterProcessorIds((prev) =>
            prev.filter((id) => id !== processorId),
        );
    };

    const handleManualRefresh = () => {
        setLastRefresh(new Date());
        router.reload({ only: ['processorspecs'] });
    };

    // Auto-refresh every 30 seconds
    const isPollingRef = useRef(false);
    useEffect(() => {
        if (!autoRefreshEnabled) return;
        const interval = setInterval(() => {
            if (isPollingRef.current) return;
            isPollingRef.current = true;
            router.reload({
                only: ['processorspecs'],
                onSuccess: () => setLastRefresh(new Date()),
                onFinish: () => {
                    isPollingRef.current = false;
                },
            });
        }, 30000);

        return () => clearInterval(interval);
    }, [autoRefreshEnabled]);

    const handleFilter = () => {
        const params: Record<string, string | number | number[]> = {};
        if (selectedFilterProcessorIds.length > 0) {
            params.processor_ids = selectedFilterProcessorIds;
        }
        router.get(index().url, params, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleReset = () => {
        setSelectedFilterProcessorIds([]);
        setProcessorSearchQuery('');
        router.get(index().url);
    };

    const handleDelete = (id: number) => {
        form.delete(destroy({ processorspec: id }).url, {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="relative flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                {/* Loading overlay for page transitions */}
                <LoadingOverlay isLoading={isLoading} />

                {/* Reusable page header with create button */}
                <PageHeader
                    title="Processor Specs Management"
                    description="Manage CPU/processor component specifications and inventory"
                />

                <div className="flex flex-col gap-4">
                    <div className="flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center">
                        <div className="grid w-full flex-1 grid-cols-1 gap-3 sm:w-auto sm:grid-cols-2 lg:grid-cols-4">
                            {/* Multi-select Processor Search */}
                            <Popover
                                open={isProcessorPopoverOpen}
                                onOpenChange={setIsProcessorPopoverOpen}
                            >
                                <PopoverTrigger asChild>
                                    <Button
                                        variant="outline"
                                        role="combobox"
                                        aria-expanded={isProcessorPopoverOpen}
                                        className="w-full justify-between font-normal"
                                    >
                                        <span className="truncate">
                                            {selectedFilterProcessorIds.length >
                                            0
                                                ? `${selectedFilterProcessorIds.length} processor(s) selected`
                                                : 'Select processors to filter...'}
                                        </span>
                                        <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent
                                    className="w-full min-w-75 p-0"
                                    align="start"
                                >
                                    <Command shouldFilter={false}>
                                        <CommandInput
                                            placeholder="Search processors..."
                                            value={processorSearchQuery}
                                            onValueChange={
                                                setProcessorSearchQuery
                                            }
                                        />
                                        <CommandList>
                                            <CommandEmpty>
                                                No processors found.
                                            </CommandEmpty>
                                            <CommandGroup>
                                                {filteredProcessorOptions.map(
                                                    (proc) => (
                                                        <CommandItem
                                                            key={proc.id}
                                                            value={String(
                                                                proc.id,
                                                            )}
                                                            onSelect={() =>
                                                                handleToggleProcessorSelect(
                                                                    proc.id,
                                                                )
                                                            }
                                                            className="cursor-pointer"
                                                        >
                                                            <Check
                                                                className={`mr-2 h-4 w-4 ${selectedFilterProcessorIds.includes(proc.id) ? 'opacity-100' : 'opacity-0'}`}
                                                            />
                                                            {proc.label}
                                                        </CommandItem>
                                                    ),
                                                )}
                                            </CommandGroup>
                                        </CommandList>
                                    </Command>
                                </PopoverContent>
                            </Popover>
                        </div>

                        <div className="flex w-full flex-wrap gap-2 sm:w-auto">
                            <Button
                                onClick={handleFilter}
                                className="flex-1 sm:flex-none"
                            >
                                <Filter className="mr-2 h-4 w-4" />
                                Filter
                            </Button>
                            <Button
                                variant="outline"
                                onClick={handleReset}
                                className="flex-1 sm:flex-none"
                            >
                                Reset
                            </Button>
                            <div className="flex gap-2">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    onClick={handleManualRefresh}
                                    title="Refresh"
                                >
                                    <RefreshCw className="h-4 w-4" />
                                </Button>
                                <Button
                                    variant={
                                        autoRefreshEnabled ? 'default' : 'ghost'
                                    }
                                    size="icon"
                                    onClick={() =>
                                        setAutoRefreshEnabled(
                                            !autoRefreshEnabled,
                                        )
                                    }
                                    title={
                                        autoRefreshEnabled
                                            ? 'Disable auto-refresh'
                                            : 'Enable auto-refresh (30s)'
                                    }
                                >
                                    {autoRefreshEnabled ? (
                                        <Pause className="h-4 w-4" />
                                    ) : (
                                        <Play className="h-4 w-4" />
                                    )}
                                </Button>
                            </div>
                            {can('hardware.create') && (
                                <Link href={withReturnTo(create.url())}>
                                    <Button className="flex-1 sm:flex-none">
                                        <Plus className="mr-2 h-4 w-4" />
                                        Add Processor
                                    </Button>
                                </Link>
                            )}
                        </div>
                    </div>

                    {/* Selected processor filter badges */}
                    {selectedFilterProcessorIds.length > 0 && (
                        <div className="flex flex-wrap gap-2">
                            {selectedFilterProcessorIds.map((id) => {
                                const proc = allProcessors.find(
                                    (p) => p.id === id,
                                );
                                return proc ? (
                                    <Badge
                                        key={id}
                                        variant="secondary"
                                        className="flex items-center gap-1"
                                    >
                                        {proc.label}
                                        <X
                                            className="h-3 w-3 cursor-pointer"
                                            onClick={() =>
                                                handleRemoveProcessorFilter(id)
                                            }
                                        />
                                    </Badge>
                                ) : null;
                            })}
                        </div>
                    )}

                    <div className="flex items-center justify-between text-sm">
                        <div className="text-muted-foreground">
                            Showing {processorspecs.data.length} records
                            {selectedFilterProcessorIds.length > 0 &&
                                ` (filtered)`}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Last updated: {lastRefresh.toLocaleTimeString()}
                        </div>
                    </div>
                </div>

                {/* Desktop Table */}
                <div className="hidden overflow-hidden rounded-md shadow md:block">
                    {isLoading ? (
                        <TableSkeleton columns={9} rows={8} />
                    ) : (
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow className="bg-muted/50">
                                        <TableHead className="hidden lg:table-cell">
                                            ID
                                        </TableHead>
                                        <TableHead>Manufacturer</TableHead>
                                        <TableHead>Model</TableHead>
                                        <TableHead>Cores</TableHead>
                                        <TableHead>Threads</TableHead>
                                        <TableHead className="hidden xl:table-cell">
                                            Base Clock
                                        </TableHead>
                                        <TableHead className="hidden xl:table-cell">
                                            Boost Clock
                                        </TableHead>
                                        <TableHead className="text-center">
                                            Actions
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>

                                <TableBody>
                                    {processorspecs.data.map((cpu) => (
                                        <TableRow key={cpu.id}>
                                            <TableCell className="hidden lg:table-cell">
                                                {cpu.id}
                                            </TableCell>
                                            <TableCell className="font-medium">
                                                {cpu.manufacturer}
                                            </TableCell>
                                            <TableCell>{cpu.model}</TableCell>
                                            <TableCell>
                                                {cpu.core_count}
                                            </TableCell>
                                            <TableCell>
                                                {cpu.thread_count}
                                            </TableCell>
                                            <TableCell className="hidden xl:table-cell">
                                                {cpu.base_clock_ghz} GHz
                                            </TableCell>
                                            <TableCell className="hidden xl:table-cell">
                                                {cpu.boost_clock_ghz} GHz
                                            </TableCell>
                                            <TableCell className="flex justify-center gap-2">
                                                <Can permission="hardware.edit">
                                                    <Link
                                                        href={withReturnTo(
                                                            edit.url(cpu.id),
                                                        )}
                                                    >
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            className="bg-green-600 text-white hover:bg-green-700"
                                                        >
                                                            Edit
                                                        </Button>
                                                    </Link>
                                                </Can>

                                                {/* Reusable delete confirmation dialog */}
                                                <Can permission="hardware.delete">
                                                    <DeleteConfirmDialog
                                                        onConfirm={() =>
                                                            handleDelete(cpu.id)
                                                        }
                                                        title="Delete Processor Specification"
                                                        description={`Are you sure you want to delete ${cpu.manufacturer} ${cpu.model}?`}
                                                    />
                                                </Can>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </div>

                {/* Mobile Card View */}
                <div className="space-y-4 md:hidden">
                    {processorspecs.data.map((cpu) => (
                        <div
                            key={cpu.id}
                            className="space-y-3 rounded-lg border bg-card p-4 shadow-sm"
                        >
                            <div className="flex items-start justify-between">
                                <div>
                                    <div className="text-xs text-muted-foreground">
                                        Manufacturer
                                    </div>
                                    <div className="text-lg font-semibold">
                                        {cpu.manufacturer}
                                    </div>
                                </div>
                            </div>

                            <div className="space-y-2 text-sm">
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Model:
                                    </span>
                                    <span className="text-right font-medium break-words">
                                        {cpu.model}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Cores/Threads:
                                    </span>
                                    <span className="font-medium">
                                        {cpu.core_count} / {cpu.thread_count}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Base Clock:
                                    </span>
                                    <span className="font-medium">
                                        {cpu.base_clock_ghz} GHz
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Boost Clock:
                                    </span>
                                    <span className="font-medium">
                                        {cpu.boost_clock_ghz} GHz
                                    </span>
                                </div>
                            </div>

                            {/* Actions */}
                            <div className="flex gap-2">
                                <Can permission="hardware.edit">
                                    <Link
                                        href={withReturnTo(edit.url(cpu.id))}
                                        className="flex-1"
                                    >
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="w-full bg-green-600 text-white hover:bg-green-700"
                                        >
                                            Edit
                                        </Button>
                                    </Link>
                                </Can>

                                {/* Reusable delete confirmation dialog */}
                                <Can permission="hardware.delete">
                                    <div className="flex-1">
                                        <DeleteConfirmDialog
                                            onConfirm={() =>
                                                handleDelete(cpu.id)
                                            }
                                            title="Delete Processor Specification"
                                            description={`Are you sure you want to delete ${cpu.manufacturer} ${cpu.model}?`}
                                        />
                                    </div>
                                </Can>
                            </div>
                        </div>
                    ))}
                    {processorspecs.data.length === 0 && (
                        <div className="rounded-lg border bg-card py-12 text-center text-gray-500">
                            No processor specs found
                        </div>
                    )}
                </div>

                {/* Pagination */}
                <div className="flex justify-center">
                    <PaginationNav links={processorspecs.links} />
                </div>
            </div>
        </AppLayout>
    );
}
