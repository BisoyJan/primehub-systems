import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { toast } from 'sonner';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
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
import PaginationNav, { PaginationLink } from '@/components/pagination-nav';
import { Edit, Calendar, Search, RefreshCw, Filter, Plus } from 'lucide-react';

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
} from '@/routes/pc-maintenance';
import { index as stationsIndexRoute } from '@/routes/stations';

interface PcSpec {
    id: number;
    pc_number: string;
    model: string;
}

interface Site {
    id: number;
    name: string;
}

interface Station {
    id: number;
    station_number: string;
    site_id: number;
    pc_spec_id: number | null;
    site: Site;
    pc_spec: PcSpec | null;
}

interface Maintenance {
    id: number;
    station_id: number;
    last_maintenance_date: string;
    next_due_date: string;
    maintenance_type: string | null;
    notes: string | null;
    performed_by: string | null;
    status: 'completed' | 'pending' | 'overdue';
    created_at: string;
    updated_at: string;
    station: Station;
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
}

export default function Index({ maintenances, sites, filters = {} }: IndexProps) {
    const { title, breadcrumbs } = usePageMeta({
        title: 'PC Maintenance',
        breadcrumbs: [
            { title: 'Stations', href: stationsIndexRoute().url },
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

    const showClearFilters = Boolean(search.trim()) || status !== 'all' || siteFilter !== 'all';

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

    const requestWithFilters = (params: Record<string, string>) => {
        setIsFilterLoading(true);
        router.get(pcMaintenanceIndexRoute().url, params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: () => setLastRefresh(new Date()),
            onFinish: () => setIsFilterLoading(false),
        });
    };

    const handleApplyFilters = () => {
        requestWithFilters(buildFilterParams());
    };

    const handleClearFilters = () => {
        setSearch('');
        setStatus('all');
        setSiteFilter('all');
        requestWithFilters({});
    };

    const handleManualRefresh = () => {
        requestWithFilters(buildFilterParams());
    };

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
                                    placeholder="Search by PC or station number..."
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
                </div>

                <div className="border rounded-lg overflow-hidden bg-card">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>PC Number</TableHead>
                                    <TableHead>Model</TableHead>
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
                                        <TableCell colSpan={10} className="text-center py-8">
                                            <Calendar className="mx-auto h-12 w-12 text-muted-foreground mb-2" />
                                            <p className="text-muted-foreground">No maintenance records found</p>
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    maintenances.data.map((maintenance) => (
                                        <TableRow key={maintenance.id}>
                                            <TableCell className="font-medium">
                                                {maintenance.station.pc_spec?.pc_number || 'N/A'}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {maintenance.station.pc_spec?.model || 'N/A'}
                                            </TableCell>
                                            <TableCell>{maintenance.station.site.name}</TableCell>
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
                                                            description={`Are you sure you want to delete this maintenance record for PC ${maintenance.station.pc_spec?.pc_number || maintenance.station.station_number}? This action cannot be undone.`}
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
            </div>
        </AppLayout>
    );
}
