import { useEffect, useState, useRef } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
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
import { Edit, Calendar } from 'lucide-react';

// Reusable components and hooks
import { PageHeader } from '@/components/PageHeader';
import { DeleteConfirmDialog } from '@/components/DeleteConfirmDialog';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { SearchBar } from '@/components/SearchBar';
import { usePageMeta, useFlashMessage, usePageLoading } from '@/hooks';

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
    links: PaginationLink[];
    meta: Meta;
}

interface Site {
    id: number;
    name: string;
}

interface IndexProps {
    maintenances: PaginatedMaintenances;
    sites: Site[];
    filters?: {
        status?: string;
        search?: string;
        site?: string;
    };
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    [key: string]: any;
}

export default function Index() {
    const { maintenances, sites, filters = {} } = usePage<IndexProps>().props;
    const [loading, setLoading] = useState(false);
    const [search, setSearch] = useState(filters.search || '');
    const [debouncedSearch, setDebouncedSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || 'all');
    const [siteFilter, setSiteFilter] = useState(filters.site || 'all');

    // Track initial mount to prevent effect on first render
    const isInitialMount = useRef(true);

    // Use reusable hooks
    const { title, breadcrumbs } = usePageMeta({
        title: 'PC Maintenance',
        breadcrumbs: [{ title: 'PC Maintenance', href: '/pc-maintenance' }]
    });
    useFlashMessage();
    const isPageLoading = usePageLoading();

    const hasData = maintenances && maintenances.data && maintenances.data.length > 0;

    // Debounce search input
    useEffect(() => {
        const timer = setTimeout(() => setDebouncedSearch(search), 500);
        return () => clearTimeout(timer);
    }, [search]);

    // Handle filter changes
    useEffect(() => {
        // Skip the effect on initial mount to avoid duplicate requests
        if (isInitialMount.current) {
            isInitialMount.current = false;
            return;
        }

        const params: Record<string, string | number> = {};
        if (debouncedSearch) params.search = debouncedSearch;
        if (status && status !== 'all') params.status = status;
        if (siteFilter && siteFilter !== 'all') params.site = siteFilter;
        // Reset to page 1 when filters change
        params.page = 1;

        setLoading(true);
        router.get('/pc-maintenance', params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => setLoading(false),
        });
    }, [debouncedSearch, status, siteFilter]);

    const handleDelete = (id: number) => {
        setLoading(true);
        router.delete(`/pc-maintenance/${id}`, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Maintenance record deleted successfully');
            },
            onError: () => {
                toast.error('Failed to delete maintenance record');
            },
            onFinish: () => setLoading(false),
        });
    };

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        // Search is handled by debounced effect
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

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                {/* Loading overlay */}
                <LoadingOverlay isLoading={isPageLoading || loading} message="Loading maintenance records..." />

                {/* Page header with create button */}
                <PageHeader
                    title="PC Maintenance"
                    description="Track PC maintenance schedules and history"
                    createLink="/pc-maintenance/create"
                    createLabel="Add Maintenance Record"
                >
                    {/* Filters */}
                    <div className="flex flex-col sm:flex-row gap-3">
                        <div className="flex-1">
                            <Label htmlFor="search" className="text-sm mb-1.5 block">Search by PC Number</Label>
                            <SearchBar
                                value={search}
                                onChange={setSearch}
                                onSubmit={handleSearch}
                                placeholder="Search PC number..."
                            />
                        </div>
                        <div className="w-full sm:w-48">
                            <Label htmlFor="site" className="text-sm mb-1.5 block">Site</Label>
                            <Select value={siteFilter} onValueChange={setSiteFilter}>
                                <SelectTrigger>
                                    <SelectValue placeholder="All sites" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All sites</SelectItem>
                                    {sites.map(site => (
                                        <SelectItem key={site.id} value={site.id.toString()}>
                                            {site.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="w-full sm:w-48">
                            <Label htmlFor="status" className="text-sm mb-1.5 block">Status</Label>
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
                    </div>
                </PageHeader>

                {/* Table */}
                <div className="border rounded-lg">
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
                                        <p className="text-muted-foreground">
                                            No maintenance records found
                                        </p>
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
                                        <TableCell>
                                            {maintenance.maintenance_type || 'N/A'}
                                        </TableCell>
                                        <TableCell>
                                            {formatDate(maintenance.last_maintenance_date)}
                                        </TableCell>
                                        <TableCell>
                                            {formatDate(maintenance.next_due_date)}
                                        </TableCell>
                                        <TableCell>
                                            <span className={
                                                isDatePast(maintenance.next_due_date)
                                                    ? 'text-red-600 font-semibold'
                                                    : getDaysUntil(maintenance.next_due_date) <= 7
                                                        ? 'text-yellow-600 font-semibold'
                                                        : ''
                                            }>
                                                {getDaysUntilText(maintenance.next_due_date)}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            {maintenance.performed_by || 'N/A'}
                                        </TableCell>
                                        <TableCell>{getStatusBadge(maintenance)}</TableCell>
                                        <TableCell className="text-right">
                                            <div className="flex justify-end gap-2">
                                                <Link href={`/pc-maintenance/${maintenance.id}/edit`}>
                                                    <Button variant="ghost" size="sm" disabled={loading}>
                                                        <Edit className="h-4 w-4" />
                                                    </Button>
                                                </Link>
                                                <DeleteConfirmDialog
                                                    onConfirm={() => handleDelete(maintenance.id)}
                                                    title="Delete Maintenance Record"
                                                    description={`Are you sure you want to delete this maintenance record for PC ${maintenance.station.pc_spec?.pc_number || maintenance.station.station_number}? This action cannot be undone.`}
                                                    disabled={loading}
                                                    triggerClassName="bg-transparent text-destructive hover:bg-transparent hover:text-destructive/90"
                                                    triggerLabel=""
                                                />
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>

                {/* Pagination */}
                {maintenances && maintenances.meta && maintenances.meta.last_page > 1 && (
                    <PaginationNav links={maintenances.links} />
                )}
            </div>
        </AppLayout>
    );
}
