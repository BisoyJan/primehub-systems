import { useState, useEffect } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { toast } from 'sonner';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import PaginationNav, { PaginationLink } from '@/components/pagination-nav';
import { Edit, Calendar, Search, RefreshCw, Filter, Plus, Monitor, Wrench } from 'lucide-react';

import { PageHeader } from '@/components/PageHeader';
import { DeleteConfirmDialog } from '@/components/DeleteConfirmDialog';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { usePageMeta, useFlashMessage, usePageLoading } from '@/hooks';
import { Can } from '@/components/authorization';
import { usePermission } from '@/hooks/useAuthorization';
import {
    index as pcMaintenanceIndexRoute,
    create as pcMaintenanceCreateRoute,
    destroy as pcMaintenanceDestroyRoute,
    edit as pcMaintenanceEditRoute,
    bulkUpdate as pcMaintenanceBulkUpdateRoute,
} from '@/routes/pc-maintenance';
import { index as pcSpecsIndexRoute } from '@/routes/pcspecs';

interface PcSpec {
    id: number;
    pc_number: string;
    model: string;
}

interface Site {
    id: number;
    name: string;
}

interface CurrentStation {
    id: number;
    station_number: string;
    site: Site | null;
}

interface Maintenance {
    id: number;
    pc_spec_id: number;
    last_maintenance_date: string;
    next_due_date: string;
    maintenance_type: string | null;
    notes: string | null;
    performed_by: string | null;
    status: 'completed' | 'pending' | 'overdue';
    created_at: string;
    updated_at: string;
    pc_spec: PcSpec;
    current_station: CurrentStation | null;
}

interface Meta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface PaginatedMaintenances {
    data: Maintenance[];
    links?: PaginationLink[];
    meta?: Meta;
}

interface Filters {
    status?: string;
    search?: string;
    site?: string;
}

interface IndexProps {
    maintenances: PaginatedMaintenances;
    sites: Site[];
    filters?: Filters;
    allMatchingIds: number[];
}

export default function Index({ maintenances, sites, filters = {}, allMatchingIds }: IndexProps) {
    const { title, breadcrumbs } = usePageMeta({
        title: 'PC Maintenance',
        breadcrumbs: [
            { title: 'PC Specs', href: pcSpecsIndexRoute().url },
            { title: 'PC Maintenance', href: pcMaintenanceIndexRoute().url },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();
    const { can } = usePermission();

    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || 'all');
    const [siteFilter, setSiteFilter] = useState(filters.site || 'all');
    const [isFilterLoading, setIsFilterLoading] = useState(false);
    const [isMutating, setIsMutating] = useState(false);
    const [lastRefresh, setLastRefresh] = useState<Date>(new Date());

    // LocalStorage keys for persisting selection
    const LOCAL_STORAGE_KEY = 'pc_maintenance_selected_ids';
    const LOCAL_STORAGE_TIMESTAMP_KEY = 'pc_maintenance_selected_ids_timestamp';
    const EXPIRY_TIME_MS = 15 * 60 * 1000; // 15 minutes

    // Bulk selection state - persisted in localStorage
    const [selectedIds, setSelectedIds] = useState<number[]>(() => {
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
            return [];
        }
    });
    const [isAllRecordsSelected, setIsAllRecordsSelected] = useState(false);
    const [isBulkUpdateOpen, setIsBulkUpdateOpen] = useState(false);

    // Save selectedIds to localStorage on change
    useEffect(() => {
        try {
            if (selectedIds.length > 0) {
                localStorage.setItem(LOCAL_STORAGE_KEY, JSON.stringify(selectedIds));
                localStorage.setItem(LOCAL_STORAGE_TIMESTAMP_KEY, Date.now().toString());
            } else {
                localStorage.removeItem(LOCAL_STORAGE_KEY);
                localStorage.removeItem(LOCAL_STORAGE_TIMESTAMP_KEY);
            }
        } catch {
            // Ignore localStorage errors
        }
    }, [selectedIds]);

    // Bulk update form
    const bulkUpdateForm = useForm({
        ids: [] as number[],
        last_maintenance_date: new Date().toISOString().split('T')[0],
        next_due_date: '',
        maintenance_type: '',
        performed_by: '',
        status: 'completed' as 'completed' | 'pending' | 'overdue',
        notes: '',
    });

    // Auto-calculate next_due_date when last_maintenance_date changes
    useEffect(() => {
        if (bulkUpdateForm.data.last_maintenance_date) {
            const lastDate = new Date(bulkUpdateForm.data.last_maintenance_date);
            lastDate.setMonth(lastDate.getMonth() + 4);
            bulkUpdateForm.setData('next_due_date', lastDate.toISOString().split('T')[0]);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [bulkUpdateForm.data.last_maintenance_date]);

    // When "select all records" is active, sync selectedIds with allMatchingIds
    useEffect(() => {
        if (isAllRecordsSelected) {
            setSelectedIds(allMatchingIds);
        }
    }, [isAllRecordsSelected, allMatchingIds]);

    const showClearFilters = Boolean(search.trim()) || status !== 'all' || siteFilter !== 'all';

    // Current page IDs
    const currentPageIds = maintenances.data.map((m) => m.id);

    // Selection handlers
    const isCurrentPageAllSelected = currentPageIds.length > 0 && currentPageIds.every((id) => selectedIds.includes(id));
    const isCurrentPagePartiallySelected = currentPageIds.some((id) => selectedIds.includes(id)) && !isCurrentPageAllSelected;

    // Handle header checkbox - toggles between: none -> current page -> all records -> none
    const handleSelectAll = (checked: boolean) => {
        if (checked) {
            if (!isCurrentPageAllSelected) {
                // First click: select current page items (add to existing selection)
                setSelectedIds((prev) => [...new Set([...prev, ...currentPageIds])]);
            }
        } else {
            // Uncheck: clear all selections
            setSelectedIds([]);
            setIsAllRecordsSelected(false);
        }
    };

    // Handle "Select all X records" button click
    const handleSelectAllRecords = () => {
        setIsAllRecordsSelected(true);
        setSelectedIds(allMatchingIds);
    };

    // Handle individual checkbox
    const handleSelectOne = (id: number, checked: boolean) => {
        if (checked) {
            setSelectedIds((prev) => [...prev, id]);
        } else {
            setSelectedIds((prev) => prev.filter((i) => i !== id));
            // If user unchecks one while "all records" is selected, turn off all-records mode
            if (isAllRecordsSelected) {
                setIsAllRecordsSelected(false);
            }
        }
    };

    // Clear all selections (including localStorage)
    const handleClearSelection = () => {
        setSelectedIds([]);
        setIsAllRecordsSelected(false);
        try {
            localStorage.removeItem(LOCAL_STORAGE_KEY);
            localStorage.removeItem(LOCAL_STORAGE_TIMESTAMP_KEY);
        } catch {
            // Ignore localStorage errors
        }
    };

    const handleOpenBulkUpdate = () => {
        bulkUpdateForm.setData('ids', selectedIds);
        setIsBulkUpdateOpen(true);
    };

    const handleBulkUpdate = () => {
        bulkUpdateForm.post(pcMaintenanceBulkUpdateRoute().url, {
            preserveScroll: true,
            onSuccess: () => {
                setIsBulkUpdateOpen(false);
                setSelectedIds([]);
                setIsAllRecordsSelected(false);
                bulkUpdateForm.reset();
                // Clear localStorage after successful update
                try {
                    localStorage.removeItem(LOCAL_STORAGE_KEY);
                    localStorage.removeItem(LOCAL_STORAGE_TIMESTAMP_KEY);
                } catch {
                    // Ignore localStorage errors
                }
                toast.success('Maintenance records updated successfully');
            },
            onError: () => {
                toast.error('Failed to update maintenance records');
            },
        });
    };

    const buildFilterParams = () => {
        const params: Record<string, string> = {};
        if (search.trim()) {
            params.search = search.trim();
        }
        if (status !== 'all') {
            params.status = status;
        }
        if (siteFilter !== 'all') {
            params.site = siteFilter;
        }
        return params;
    };

    const requestWithFilters = (params: Record<string, string>, clearSelection = false) => {
        setIsFilterLoading(true);
        if (clearSelection) {
            setSelectedIds([]);
            setIsAllRecordsSelected(false);
            // Clear localStorage when filters change
            try {
                localStorage.removeItem(LOCAL_STORAGE_KEY);
                localStorage.removeItem(LOCAL_STORAGE_TIMESTAMP_KEY);
            } catch {
                // Ignore localStorage errors
            }
        }
        router.get(pcMaintenanceIndexRoute().url, params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: () => setLastRefresh(new Date()),
            onFinish: () => setIsFilterLoading(false),
        });
    };

    const handleApplyFilters = () => {
        // Clear selection when filters change since the dataset changes
        requestWithFilters(buildFilterParams(), true);
    };

    const handleClearFilters = () => {
        setSearch('');
        setStatus('all');
        setSiteFilter('all');
        requestWithFilters({}, true);
    };

    const handleManualRefresh = () => {
        // Keep selection on manual refresh
        requestWithFilters(buildFilterParams(), false);
    };

    // Auto-refresh every 30 seconds
    useEffect(() => {
        const interval = setInterval(() => {
            router.get(pcMaintenanceIndexRoute().url, buildFilterParams(), {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['maintenances', 'allMatchingIds'],
                onSuccess: () => setLastRefresh(new Date()),
            });
        }, 30000);

        return () => clearInterval(interval);
    }, [search, status, siteFilter]);

    const handleDelete = (id: number) => {
        setIsMutating(true);
        router.delete(pcMaintenanceDestroyRoute(id).url, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            onSuccess: () => {
                toast.success('Maintenance record deleted successfully');
                setLastRefresh(new Date());
            },
            onError: () => {
                toast.error('Failed to delete maintenance record');
            },
            onFinish: () => setIsMutating(false),
        });
    };

    const formatDate = (dateString: string) => {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: '2-digit' });
    };

    const getDaysUntil = (dateString: string): number => {
        const today = new Date();
        const dueDate = new Date(dateString);
        const diffTime = dueDate.getTime() - today.getTime();
        return Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    };

    const isDatePast = (dateString: string): boolean => {
        return new Date(dateString) < new Date();
    };

    const getStatusBadge = (maintenance: Maintenance) => {
        const daysUntil = getDaysUntil(maintenance.next_due_date);

        if (maintenance.status === 'completed') {
            return <Badge variant="default" className="bg-green-500">Completed</Badge>;
        } else if (maintenance.status === 'overdue' || isDatePast(maintenance.next_due_date)) {
            return <Badge variant="destructive">Overdue</Badge>;
        } else if (daysUntil <= 7) {
            return <Badge variant="secondary" className="bg-yellow-500 text-white">Due Soon</Badge>;
        } else {
            return <Badge variant="outline">Pending</Badge>;
        }
    };

    const getDaysUntilText = (dueDate: string) => {
        const days = getDaysUntil(dueDate);
        if (days < 0) {
            return `${Math.abs(days)} days overdue`;
        } else if (days === 0) {
            return 'Due today';
        } else if (days === 1) {
            return '1 day left';
        } else {
            return `${days} days left`;
        }
    };

    const paginationMeta: Meta = maintenances.meta || {
        current_page: 1,
        last_page: 1,
        per_page: maintenances.data.length || 1,
        total: maintenances.data.length,
    };
    const paginationLinks = maintenances.links || [];
    const hasData = maintenances.data.length > 0;
    const showingStart = hasData ? paginationMeta.per_page * (paginationMeta.current_page - 1) + 1 : 0;
    const showingEnd = hasData ? showingStart + maintenances.data.length - 1 : 0;
    const summaryText = hasData
        ? `Showing ${showingStart}-${showingEnd} of ${paginationMeta.total} maintenance records`
        : 'No maintenance records to display';

    const overlayMessage = isMutating ? 'Processing request...' : 'Loading maintenance records...';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isPageLoading || isFilterLoading || isMutating} message={overlayMessage} />

                <PageHeader
                    title="PC Maintenance"
                    description="Track PC maintenance schedules and history"
                />

                <div className="flex flex-col gap-4">
                    <div className="flex flex-col sm:flex-row gap-4 justify-between items-start sm:items-center">
                        <div className="w-full sm:w-auto flex-1 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            <div className="relative">
                                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search by PC number or model..."
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    onKeyDown={(event) => event.key === 'Enter' && handleApplyFilters()}
                                    className="pl-8"
                                />
                            </div>

                            <Select value={siteFilter} onValueChange={setSiteFilter}>
                                <SelectTrigger>
                                    <SelectValue placeholder="All sites" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All sites</SelectItem>
                                    {sites.map((site) => (
                                        <SelectItem key={site.id} value={site.id.toString()}>
                                            {site.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            <Select value={status} onValueChange={setStatus}>
                                <SelectTrigger>
                                    <SelectValue placeholder="All statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All statuses</SelectItem>
                                    <SelectItem value="completed">Completed</SelectItem>
                                    <SelectItem value="pending">Pending</SelectItem>
                                    <SelectItem value="overdue">Overdue</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="flex gap-2 w-full sm:w-auto">
                            <Button variant="outline" onClick={handleApplyFilters} disabled={isFilterLoading} className="flex-1 sm:flex-none">
                                <Filter className="mr-2 h-4 w-4" />
                                Filter
                            </Button>

                            {showClearFilters && (
                                <Button variant="outline" onClick={handleClearFilters} disabled={isFilterLoading} className="flex-1 sm:flex-none">
                                    Reset
                                </Button>
                            )}

                            <Button variant="ghost" onClick={handleManualRefresh} disabled={isFilterLoading} className="flex-1 sm:flex-none">
                                <RefreshCw className="mr-2 h-4 w-4" />
                                Refresh
                            </Button>

                            {can('pc_maintenance.create') && (
                                <Link href={pcMaintenanceCreateRoute().url} className="flex-1 sm:flex-none">
                                    <Button className="w-full sm:w-auto">
                                        <Plus className="mr-2 h-4 w-4" />
                                        Add Record
                                    </Button>
                                </Link>
                            )}
                        </div>
                    </div>

                    <div className="flex justify-between items-center text-sm">
                        <div className="text-muted-foreground">
                            {summaryText}
                            {showClearFilters && hasData ? ' (filtered)' : ''}
                        </div>
                        <div className="text-xs text-muted-foreground">Last updated: {lastRefresh.toLocaleTimeString()}</div>
                    </div>

                    {/* Bulk Action Bar */}
                    {selectedIds.length > 0 && can('pc_maintenance.edit') && (
                        <div className="flex flex-col sm:flex-row items-start sm:items-center gap-3 p-3 bg-muted rounded-lg">
                            <div className="flex flex-col sm:flex-row items-start sm:items-center gap-2">
                                <span className="text-sm font-medium">
                                    {isAllRecordsSelected
                                        ? `All ${allMatchingIds.length} record${allMatchingIds.length > 1 ? 's' : ''} selected`
                                        : `${selectedIds.length} record${selectedIds.length > 1 ? 's' : ''} selected`}
                                </span>
                                {!isAllRecordsSelected && selectedIds.length < allMatchingIds.length && allMatchingIds.length > currentPageIds.length && (
                                    <Button variant="link" size="sm" className="h-auto p-0 text-primary" onClick={handleSelectAllRecords}>
                                        Select all {allMatchingIds.length} records
                                    </Button>
                                )}
                            </div>
                            <div className="flex gap-2">
                                <Button size="sm" onClick={handleOpenBulkUpdate}>
                                    <Wrench className="mr-2 h-4 w-4" />
                                    Bulk Update Maintenance
                                </Button>
                                <Button variant="outline" size="sm" onClick={handleClearSelection}>
                                    Clear Selection
                                </Button>
                            </div>
                        </div>
                    )}
                </div>

                {/* Desktop Table View */}
                <div className="hidden md:block border rounded-lg overflow-hidden bg-card">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    {can('pc_maintenance.edit') && (
                                        <TableHead className="w-12">
                                            <Checkbox
                                                checked={isAllRecordsSelected || isCurrentPageAllSelected}
                                                ref={(el) => {
                                                    if (el) {
                                                        (el as HTMLButtonElement).dataset.state = isCurrentPagePartiallySelected ? 'indeterminate' : ((isAllRecordsSelected || isCurrentPageAllSelected) ? 'checked' : 'unchecked');
                                                    }
                                                }}
                                                onCheckedChange={handleSelectAll}
                                                aria-label="Select all on this page"
                                            />
                                        </TableHead>
                                    )}
                                    <TableHead>PC Number</TableHead>
                                    <TableHead>Model</TableHead>
                                    <TableHead>Current Station</TableHead>
                                    <TableHead>Site</TableHead>
                                    <TableHead>Maintenance Type</TableHead>
                                    <TableHead>Last Maintenance</TableHead>
                                    <TableHead>Next Due Date</TableHead>
                                    <TableHead>Days Until Due</TableHead>
                                    <TableHead>Performed By</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {!hasData ? (
                                    <TableRow>
                                        <TableCell colSpan={can('pc_maintenance.edit') ? 12 : 11} className="text-center py-8">
                                            <Calendar className="mx-auto h-12 w-12 text-muted-foreground mb-2" />
                                            <p className="text-muted-foreground">No maintenance records found</p>
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    maintenances.data.map((maintenance) => (
                                        <TableRow key={maintenance.id} className={selectedIds.includes(maintenance.id) ? 'bg-muted/50' : ''}>
                                            {can('pc_maintenance.edit') && (
                                                <TableCell>
                                                    <Checkbox
                                                        checked={selectedIds.includes(maintenance.id)}
                                                        onCheckedChange={(checked) => handleSelectOne(maintenance.id, checked as boolean)}
                                                        aria-label={`Select ${maintenance.pc_spec?.pc_number}`}
                                                    />
                                                </TableCell>
                                            )}
                                            <TableCell className="font-medium">
                                                {maintenance.pc_spec?.pc_number || 'N/A'}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {maintenance.pc_spec?.model || 'N/A'}
                                            </TableCell>
                                            <TableCell>
                                                {maintenance.current_station?.station_number || (
                                                    <span className="text-muted-foreground italic">Not assigned</span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {maintenance.current_station?.site?.name || (
                                                    <span className="text-muted-foreground italic">-</span>
                                                )}
                                            </TableCell>
                                            <TableCell>{maintenance.maintenance_type || 'N/A'}</TableCell>
                                            <TableCell>{formatDate(maintenance.last_maintenance_date)}</TableCell>
                                            <TableCell>{formatDate(maintenance.next_due_date)}</TableCell>
                                            <TableCell>
                                                <span
                                                    className={
                                                        isDatePast(maintenance.next_due_date)
                                                            ? 'text-red-600 font-semibold'
                                                            : getDaysUntil(maintenance.next_due_date) <= 7
                                                                ? 'text-yellow-600 font-semibold'
                                                                : ''
                                                    }
                                                >
                                                    {getDaysUntilText(maintenance.next_due_date)}
                                                </span>
                                            </TableCell>
                                            <TableCell>{maintenance.performed_by || 'N/A'}</TableCell>
                                            <TableCell>{getStatusBadge(maintenance)}</TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex justify-end gap-2">
                                                    <Can permission="pc_maintenance.edit">
                                                        <Link href={pcMaintenanceEditRoute(maintenance.id).url}>
                                                            <Button variant="ghost" size="sm" disabled={isMutating}>
                                                                <Edit className="h-4 w-4" />
                                                            </Button>
                                                        </Link>
                                                    </Can>
                                                    <Can permission="pc_maintenance.delete">
                                                        <DeleteConfirmDialog
                                                            onConfirm={() => handleDelete(maintenance.id)}
                                                            title="Delete Maintenance Record"
                                                            description={`Are you sure you want to delete this maintenance record for PC ${maintenance.pc_spec?.pc_number}? This action cannot be undone.`}
                                                            disabled={isMutating}
                                                            triggerClassName="bg-transparent text-destructive hover:bg-transparent hover:text-destructive/90"
                                                            triggerLabel=""
                                                        />
                                                    </Can>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </div>

                    {paginationLinks.length > 0 && (
                        <div className="border-t px-4 py-3 flex justify-center">
                            <PaginationNav links={paginationLinks} />
                        </div>
                    )}
                </div>

                {/* Mobile Card View */}
                <div className="md:hidden space-y-4">
                    {!hasData ? (
                        <div className="text-center py-8 bg-card border rounded-lg">
                            <Calendar className="mx-auto h-12 w-12 text-muted-foreground mb-2" />
                            <p className="text-muted-foreground">No maintenance records found</p>
                        </div>
                    ) : (
                        maintenances.data.map((maintenance) => (
                            <div
                                key={maintenance.id}
                                className={`bg-card border rounded-lg p-4 shadow-sm space-y-3 ${selectedIds.includes(maintenance.id) ? 'ring-2 ring-primary' : ''}`}
                            >
                                <div className="flex justify-between items-start">
                                    <div className="flex items-start gap-3">
                                        {can('pc_maintenance.edit') && (
                                            <Checkbox
                                                checked={selectedIds.includes(maintenance.id)}
                                                onCheckedChange={(checked) => handleSelectOne(maintenance.id, checked as boolean)}
                                                aria-label={`Select ${maintenance.pc_spec?.pc_number}`}
                                                className="mt-1"
                                            />
                                        )}
                                        <div>
                                            <div className="flex items-center gap-2">
                                                <Monitor className="h-4 w-4 text-muted-foreground" />
                                                <span className="font-semibold">{maintenance.pc_spec?.pc_number || 'N/A'}</span>
                                            </div>
                                            <p className="text-sm text-muted-foreground">{maintenance.pc_spec?.model || 'N/A'}</p>
                                        </div>
                                    </div>
                                    {getStatusBadge(maintenance)}
                                </div>

                                <div className="grid grid-cols-2 gap-2 text-sm">
                                    <div>
                                        <span className="text-muted-foreground">Station:</span>
                                        <p className="font-medium">
                                            {maintenance.current_station?.station_number || (
                                                <span className="italic text-muted-foreground">Not assigned</span>
                                            )}
                                        </p>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">Site:</span>
                                        <p className="font-medium">
                                            {maintenance.current_station?.site?.name || '-'}
                                        </p>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">Type:</span>
                                        <p className="font-medium">{maintenance.maintenance_type || 'N/A'}</p>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">Performed By:</span>
                                        <p className="font-medium">{maintenance.performed_by || 'N/A'}</p>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">Last Maintenance:</span>
                                        <p className="font-medium">{formatDate(maintenance.last_maintenance_date)}</p>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">Next Due:</span>
                                        <p className="font-medium">{formatDate(maintenance.next_due_date)}</p>
                                    </div>
                                </div>

                                <div className="text-sm">
                                    <span
                                        className={
                                            isDatePast(maintenance.next_due_date)
                                                ? 'text-red-600 font-semibold'
                                                : getDaysUntil(maintenance.next_due_date) <= 7
                                                    ? 'text-yellow-600 font-semibold'
                                                    : 'text-muted-foreground'
                                        }
                                    >
                                        {getDaysUntilText(maintenance.next_due_date)}
                                    </span>
                                </div>

                                <div className="flex justify-end gap-2 pt-2 border-t">
                                    <Can permission="pc_maintenance.edit">
                                        <Link href={pcMaintenanceEditRoute(maintenance.id).url}>
                                            <Button variant="outline" size="sm" disabled={isMutating}>
                                                <Edit className="h-4 w-4 mr-1" />
                                                Edit
                                            </Button>
                                        </Link>
                                    </Can>
                                    <Can permission="pc_maintenance.delete">
                                        <DeleteConfirmDialog
                                            onConfirm={() => handleDelete(maintenance.id)}
                                            title="Delete Maintenance Record"
                                            description={`Are you sure you want to delete this maintenance record for PC ${maintenance.pc_spec?.pc_number}? This action cannot be undone.`}
                                            disabled={isMutating}
                                            triggerClassName="bg-transparent text-destructive hover:bg-transparent hover:text-destructive/90"
                                            triggerLabel="Delete"
                                        />
                                    </Can>
                                </div>
                            </div>
                        ))
                    )}

                    {paginationLinks.length > 0 && (
                        <div className="flex justify-center">
                            <PaginationNav links={paginationLinks} />
                        </div>
                    )}
                </div>

                {/* Bulk Update Dialog */}
                <Dialog open={isBulkUpdateOpen} onOpenChange={setIsBulkUpdateOpen}>
                    <DialogContent className="max-w-[95vw] sm:max-w-lg">
                        <DialogHeader>
                            <DialogTitle>Bulk Update Maintenance Records</DialogTitle>
                            <DialogDescription>
                                Update maintenance information for {bulkUpdateForm.data.ids.length} selected PC{bulkUpdateForm.data.ids.length > 1 ? 's' : ''}.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-4 py-4">
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label htmlFor="bulk-last-date">Last Maintenance Date</Label>
                                    <Input
                                        id="bulk-last-date"
                                        type="date"
                                        value={bulkUpdateForm.data.last_maintenance_date}
                                        onChange={(e) => bulkUpdateForm.setData('last_maintenance_date', e.target.value)}
                                    />
                                    {bulkUpdateForm.errors.last_maintenance_date && (
                                        <p className="text-sm text-destructive">{bulkUpdateForm.errors.last_maintenance_date}</p>
                                    )}
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="bulk-next-date">Next Due Date</Label>
                                    <Input
                                        id="bulk-next-date"
                                        type="date"
                                        value={bulkUpdateForm.data.next_due_date}
                                        onChange={(e) => bulkUpdateForm.setData('next_due_date', e.target.value)}
                                    />
                                    {bulkUpdateForm.errors.next_due_date && (
                                        <p className="text-sm text-destructive">{bulkUpdateForm.errors.next_due_date}</p>
                                    )}
                                </div>
                            </div>
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label htmlFor="bulk-type">Maintenance Type</Label>
                                    <Input
                                        id="bulk-type"
                                        placeholder="e.g., Quarterly, Annual"
                                        value={bulkUpdateForm.data.maintenance_type}
                                        onChange={(e) => bulkUpdateForm.setData('maintenance_type', e.target.value)}
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="bulk-performed-by">Performed By</Label>
                                    <Input
                                        id="bulk-performed-by"
                                        placeholder="Technician name"
                                        value={bulkUpdateForm.data.performed_by}
                                        onChange={(e) => bulkUpdateForm.setData('performed_by', e.target.value)}
                                    />
                                </div>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="bulk-status">Status</Label>
                                <Select
                                    value={bulkUpdateForm.data.status}
                                    onValueChange={(value: 'completed' | 'pending' | 'overdue') =>
                                        bulkUpdateForm.setData('status', value)
                                    }
                                >
                                    <SelectTrigger id="bulk-status">
                                        <SelectValue placeholder="Select status" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="completed">Completed</SelectItem>
                                        <SelectItem value="pending">Pending</SelectItem>
                                        <SelectItem value="overdue">Overdue</SelectItem>
                                    </SelectContent>
                                </Select>
                                {bulkUpdateForm.errors.status && (
                                    <p className="text-sm text-destructive">{bulkUpdateForm.errors.status}</p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="bulk-notes">Notes</Label>
                                <Textarea
                                    id="bulk-notes"
                                    placeholder="Optional notes about this maintenance"
                                    value={bulkUpdateForm.data.notes}
                                    onChange={(e) => bulkUpdateForm.setData('notes', e.target.value)}
                                    rows={3}
                                />
                            </div>
                        </div>
                        <DialogFooter className="flex-col sm:flex-row gap-2">
                            <Button
                                variant="outline"
                                onClick={() => setIsBulkUpdateOpen(false)}
                                disabled={bulkUpdateForm.processing}
                            >
                                Cancel
                            </Button>
                            <Button
                                onClick={handleBulkUpdate}
                                disabled={bulkUpdateForm.processing}
                            >
                                {bulkUpdateForm.processing ? 'Updating...' : `Update ${bulkUpdateForm.data.ids.length} Record${bulkUpdateForm.data.ids.length > 1 ? 's' : ''}`}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
