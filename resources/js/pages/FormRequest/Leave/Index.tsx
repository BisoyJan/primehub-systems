import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { format, parseISO } from 'date-fns';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Can } from '@/components/authorization';
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
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Plus, Eye, Ban, RefreshCw, Filter, Trash2, Pencil } from 'lucide-react';
import { toast } from 'sonner';
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';
import { usePermission } from '@/hooks/use-permission';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import PaginationNav from '@/components/pagination-nav';
import { index as leaveIndexRoute, create as leaveCreateRoute, show as leaveShowRoute, cancel as leaveCancelRoute, destroy as leaveDestroyRoute, edit as leaveEditRoute } from '@/routes/leave-requests';

interface User {
    id: number;
    name: string;
}

interface LeaveRequest {
    id: number;
    user: User;
    leave_type: string;
    start_date: string;
    end_date: string;
    days_requested: number;
    status: string;
    created_at: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Props {
    leaveRequests: {
        data: LeaveRequest[];
        links?: PaginationLink[];
        meta?: PaginationMeta;
    };
    filters: {
        status?: string;
        type?: string;
        start_date?: string;
        end_date?: string;
    };
    isAdmin: boolean;
    hasPendingRequests: boolean;
    auth: {
        user: {
            id: number;
            name: string;
        };
    };
}

export default function Index({ leaveRequests, filters, isAdmin, hasPendingRequests, auth }: Props) {
    const { title, breadcrumbs } = usePageMeta({
        title: 'Leave Requests',
        breadcrumbs: [
            { title: 'Form Requests', href: '/form-requests' },
            { title: 'Leave Requests', href: leaveIndexRoute().url },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();
    const { can } = usePermission();

    const [filterStatus, setFilterStatus] = useState(filters.status || 'all');
    const [filterType, setFilterType] = useState(filters.type || 'all');
    const [showCancelDialog, setShowCancelDialog] = useState(false);
    const [showDeleteDialog, setShowDeleteDialog] = useState(false);
    const [selectedLeaveId, setSelectedLeaveId] = useState<number | null>(null);
    const [lastRefresh, setLastRefresh] = useState<Date>(new Date());

    const paginationMeta: PaginationMeta = leaveRequests.meta || {
        current_page: 1,
        last_page: 1,
        per_page: leaveRequests.data.length || 1,
        total: leaveRequests.data.length,
    };
    const paginationLinks = leaveRequests.links || [];

    const showClearFilters = filterStatus !== 'all' || filterType !== 'all';

    const buildFilterParams = () => {
        const params: Record<string, string> = {};
        if (filterStatus !== 'all') {
            params.status = filterStatus;
        }
        if (filterType !== 'all') {
            params.type = filterType;
        }
        return params;
    };

    const requestWithFilters = (params: Record<string, string>) => {
        router.get(leaveIndexRoute().url, params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: () => setLastRefresh(new Date()),
        });
    };

    const handleFilter = () => {
        requestWithFilters(buildFilterParams());
    };

    const handleManualRefresh = () => {
        requestWithFilters(buildFilterParams());
    };

    const clearFilters = () => {
        setFilterStatus('all');
        setFilterType('all');
        requestWithFilters({});
    };

    const handleCancelRequest = (id: number) => {
        setSelectedLeaveId(id);
        setShowCancelDialog(true);
    };

    const handleDeleteRequest = (id: number) => {
        setSelectedLeaveId(id);
        setShowDeleteDialog(true);
    };

    const confirmCancel = () => {
        if (!selectedLeaveId) return;

        router.post(
            leaveCancelRoute(selectedLeaveId).url,
            {},
            {
                onSuccess: () => {
                    toast.success('Leave request cancelled successfully');
                    setShowCancelDialog(false);
                    setSelectedLeaveId(null);
                },
                onError: () => {
                    toast.error('Failed to cancel leave request');
                }
            }
        );
    };

    const confirmDelete = () => {
        if (!selectedLeaveId) return;

        router.delete(
            leaveDestroyRoute(selectedLeaveId).url,
            {
                onSuccess: () => {
                    toast.success('Leave request deleted successfully');
                    setShowDeleteDialog(false);
                    setSelectedLeaveId(null);
                },
                onError: () => {
                    toast.error('Failed to delete leave request');
                }
            }
        );
    };

    const getStatusBadge = (status: string) => {
        const variants = {
            pending: 'bg-yellow-100 text-yellow-800 border-yellow-300',
            approved: 'bg-green-100 text-green-800 border-green-300',
            denied: 'bg-red-100 text-red-800 border-red-300',
            cancelled: 'bg-gray-100 text-gray-800 border-gray-300',
        };
        return (
            <Badge variant="outline" className={variants[status as keyof typeof variants]}>
                {status.toUpperCase()}
            </Badge>
        );
    };

    const getLeaveTypeBadge = (type: string) => {
        return <Badge variant="secondary">{type}</Badge>;
    };

    const handleRequestLeaveClick = (e: React.MouseEvent) => {
        if (hasPendingRequests) {
            e.preventDefault();
            toast.warning('Cannot create new leave request', {
                description: 'You have a pending leave request. Please wait for approval or cancel your existing pending request before creating a new one.',
                duration: 6000,
            });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isPageLoading} />

                <PageHeader
                    title="Leave Requests"
                    description={isAdmin ? 'Manage all leave requests' : 'View your leave requests'}
                />

                <div className="flex flex-col gap-4">
                    <div className="flex flex-col sm:flex-row gap-4 justify-between items-start sm:items-center">
                        <div className="w-full sm:w-auto flex-1 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            <Select value={filterStatus} onValueChange={setFilterStatus}>
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="All Statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Statuses</SelectItem>
                                    <SelectItem value="pending">Pending</SelectItem>
                                    <SelectItem value="approved">Approved</SelectItem>
                                    <SelectItem value="denied">Denied</SelectItem>
                                    <SelectItem value="cancelled">Cancelled</SelectItem>
                                </SelectContent>
                            </Select>

                            <Select value={filterType} onValueChange={setFilterType}>
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="All Types" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Types</SelectItem>
                                    <SelectItem value="VL">Vacation Leave</SelectItem>
                                    <SelectItem value="SL">Sick Leave</SelectItem>
                                    <SelectItem value="BL">Birthday Leave</SelectItem>
                                    <SelectItem value="SPL">Solo Parent Leave</SelectItem>
                                    <SelectItem value="LOA">Leave of Absence</SelectItem>
                                    <SelectItem value="LDV">Leave for Doctor's Visit</SelectItem>
                                    <SelectItem value="UPTO">Unpaid Time Off</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="flex gap-2 w-full sm:w-auto">
                            <Button variant="outline" onClick={handleFilter} className="flex-1 sm:flex-none">
                                <Filter className="mr-2 h-4 w-4" />
                                Filter
                            </Button>

                            {showClearFilters && (
                                <Button variant="outline" onClick={clearFilters} className="flex-1 sm:flex-none">
                                    Reset
                                </Button>
                            )}

                            <Button variant="ghost" onClick={handleManualRefresh} className="flex-1 sm:flex-none">
                                <RefreshCw className="mr-2 h-4 w-4" />
                                Refresh
                            </Button>

                            <Can permission="leave.create">
                                <Link href={leaveCreateRoute().url} onClick={handleRequestLeaveClick} className="flex-1 sm:flex-none">
                                    <Button disabled={hasPendingRequests} className="w-full sm:w-auto">
                                        <Plus className="mr-2 h-4 w-4" />
                                        Request Leave
                                    </Button>
                                </Link>
                            </Can>
                        </div>
                    </div>

                    <div className="flex justify-between items-center text-sm">
                        <div className="text-muted-foreground">
                            Showing {leaveRequests.data.length} of {paginationMeta.total} leave request{paginationMeta.total === 1 ? '' : 's'}
                            {showClearFilters && ' (filtered)'}
                        </div>
                        <div className="text-xs text-muted-foreground">Last updated: {lastRefresh.toLocaleTimeString()}</div>
                    </div>
                </div>

                {/* Desktop Table View */}
                <div className="hidden md:block shadow rounded-md overflow-hidden bg-card">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    {isAdmin && <TableHead>Employee</TableHead>}
                                    <TableHead>Type</TableHead>
                                    <TableHead>Start Date</TableHead>
                                    <TableHead>End Date</TableHead>
                                    <TableHead>Days</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Submitted</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {leaveRequests.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={isAdmin ? 8 : 7}
                                            className="text-center py-8 text-muted-foreground"
                                        >
                                            No leave requests found
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    leaveRequests.data.map((request) => (
                                        <TableRow key={request.id}>
                                            {isAdmin && (
                                                <TableCell className="font-medium">
                                                    {request.user.name}
                                                </TableCell>
                                            )}
                                            <TableCell>{getLeaveTypeBadge(request.leave_type)}</TableCell>
                                            <TableCell>
                                                {format(parseISO(request.start_date), 'MMM d, yyyy')}
                                            </TableCell>
                                            <TableCell>
                                                {format(parseISO(request.end_date), 'MMM d, yyyy')}
                                            </TableCell>
                                            <TableCell>{request.days_requested}</TableCell>
                                            <TableCell>{getStatusBadge(request.status)}</TableCell>
                                            <TableCell className="text-sm text-muted-foreground">
                                                {format(parseISO(request.created_at), 'MMM d, yyyy')}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex justify-end gap-2">
                                                    <Link href={leaveShowRoute(request.id).url}>
                                                        <Button size="sm" variant="ghost">
                                                            <Eye className="h-4 w-4" />
                                                        </Button>
                                                    </Link>
                                                    {request.status === 'pending' && (auth.user.id === request.user.id || can('leave.edit')) && (
                                                        <Link href={leaveEditRoute({ leaveRequest: request.id }).url}>
                                                            <Button size="sm" variant="ghost">
                                                                <Pencil className="h-4 w-4" />
                                                            </Button>
                                                        </Link>
                                                    )}
                                                    {request.status === 'pending' && auth.user.id === request.user.id && (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() => handleCancelRequest(request.id)}
                                                        >
                                                            <Ban className="h-4 w-4 text-red-500" />
                                                        </Button>
                                                    )}
                                                    {can('leave.delete') && (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() => handleDeleteRequest(request.id)}
                                                        >
                                                            <Trash2 className="h-4 w-4 text-red-500" />
                                                        </Button>
                                                    )}
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
                    {leaveRequests.data.length === 0 ? (
                        <div className="py-12 text-center text-muted-foreground border rounded-lg bg-card">
                            No leave requests found
                        </div>
                    ) : (
                        leaveRequests.data.map((request) => (
                            <div key={request.id} className="bg-card border rounded-lg p-4 shadow-sm space-y-3">
                                <div className="flex justify-between items-start">
                                    <div>
                                        {isAdmin && (
                                            <div className="text-lg font-semibold">{request.user.name}</div>
                                        )}
                                        <div className="flex items-center gap-2 mt-1">
                                            {getLeaveTypeBadge(request.leave_type)}
                                        </div>
                                    </div>
                                    {getStatusBadge(request.status)}
                                </div>

                                <div className="space-y-2 text-sm">
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Start Date:</span>
                                        <span className="font-medium">{format(parseISO(request.start_date), 'MMM d, yyyy')}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">End Date:</span>
                                        <span className="font-medium">{format(parseISO(request.end_date), 'MMM d, yyyy')}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Days Requested:</span>
                                        <span className="font-medium">{request.days_requested}</span>
                                    </div>
                                    <div className="text-xs text-muted-foreground pt-1">
                                        Submitted: {format(parseISO(request.created_at), 'MMM d, yyyy')}
                                    </div>
                                </div>

                                <div className="flex flex-wrap gap-2 pt-2 border-t">
                                    <Link href={leaveShowRoute(request.id).url} className="flex-1">
                                        <Button size="sm" variant="outline" className="w-full">
                                            <Eye className="mr-2 h-4 w-4" />
                                            View
                                        </Button>
                                    </Link>
                                    {request.status === 'pending' && (auth.user.id === request.user.id || can('leave.edit')) && (
                                        <Link href={leaveEditRoute({ leaveRequest: request.id }).url} className="flex-1">
                                            <Button size="sm" variant="outline" className="w-full">
                                                <Pencil className="mr-2 h-4 w-4" />
                                                Edit
                                            </Button>
                                        </Link>
                                    )}
                                    {request.status === 'pending' && auth.user.id === request.user.id && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => handleCancelRequest(request.id)}
                                            className="flex-1"
                                        >
                                            <Ban className="mr-2 h-4 w-4 text-red-500" />
                                            Cancel
                                        </Button>
                                    )}
                                    {can('leave.delete') && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => handleDeleteRequest(request.id)}
                                            className="flex-1"
                                        >
                                            <Trash2 className="mr-2 h-4 w-4 text-red-500" />
                                            Delete
                                        </Button>
                                    )}
                                </div>
                            </div>
                        ))
                    )}

                    {paginationLinks.length > 0 && (
                        <div className="flex justify-center pt-4">
                            <PaginationNav links={paginationLinks} />
                        </div>
                    )}
                </div>
            </div>

            {/* Cancel Confirmation Dialog */}
            <AlertDialog open={showCancelDialog} onOpenChange={setShowCancelDialog}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Cancel Leave Request</AlertDialogTitle>
                        <AlertDialogDescription>
                            Are you sure you want to cancel this leave request? This action cannot be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel onClick={() => setSelectedLeaveId(null)}>No, Keep It</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmCancel} className="bg-red-600 hover:bg-red-700">
                            Yes, Cancel Request
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* Delete Confirmation Dialog */}
            <AlertDialog open={showDeleteDialog} onOpenChange={setShowDeleteDialog}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete Leave Request</AlertDialogTitle>
                        <AlertDialogDescription>
                            Are you sure you want to permanently delete this leave request? This action cannot be undone and will remove all associated data.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel onClick={() => setSelectedLeaveId(null)}>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmDelete} className="bg-red-600 hover:bg-red-700">
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
