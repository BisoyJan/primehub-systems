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
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Plus, Filter, Eye, Ban } from 'lucide-react';
import { toast } from 'sonner';

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
        links: PaginationLink[];
        meta: PaginationMeta;
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
    const [filterStatus, setFilterStatus] = useState(filters.status || 'all');
    const [filterType, setFilterType] = useState(filters.type || 'all');
    const [showCancelDialog, setShowCancelDialog] = useState(false);
    const [selectedLeaveId, setSelectedLeaveId] = useState<number | null>(null);

    const handleFilter = () => {
        router.get(
            '/leave-requests',
            {
                status: filterStatus === 'all' ? '' : filterStatus,
                type: filterType === 'all' ? '' : filterType
            },
            { preserveState: true }
        );
    };

    const handleCancelRequest = (id: number) => {
        setSelectedLeaveId(id);
        setShowCancelDialog(true);
    };

    const confirmCancel = () => {
        if (!selectedLeaveId) return;

        router.post(
            `/leave-requests/${selectedLeaveId}/cancel`,
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
        <AppLayout>
            <Head title="Leave Requests" />

            <div className="container mx-auto px-4 py-8">
                <div className="flex justify-between items-center mb-6">
                    <div>
                        <h1 className="text-3xl font-bold">Leave Requests</h1>
                        <p className="text-muted-foreground mt-2">
                            {isAdmin ? 'Manage all leave requests' : 'View your leave requests'}
                        </p>
                    </div>
                    <Can permission="leave.create">
                        <Link href="/leave-requests/create" onClick={handleRequestLeaveClick}>
                            <Button disabled={hasPendingRequests}>
                                <Plus className="mr-2 h-4 w-4" />
                                Request Leave
                            </Button>
                        </Link>
                    </Can>
                </div>

                {/* Filters */}
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Filter className="h-5 w-5" />
                            Filters
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-col md:flex-row gap-4">
                            <div className="flex-1">
                                <Select value={filterStatus} onValueChange={setFilterStatus}>
                                    <SelectTrigger>
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
                            </div>

                            <div className="flex-1">
                                <Select value={filterType} onValueChange={setFilterType}>
                                    <SelectTrigger>
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

                            <Button onClick={handleFilter}>Apply Filters</Button>
                        </div>
                    </CardContent>
                </Card>

                {/* Table */}
                <Card>
                    <CardContent className="pt-6">
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
                                                        <Link href={`/leave-requests/${request.id}`}>
                                                            <Button size="sm" variant="ghost">
                                                                <Eye className="h-4 w-4" />
                                                            </Button>
                                                        </Link>
                                                        {request.status === 'pending' && auth.user.id === request.user.id && (
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                onClick={() => handleCancelRequest(request.id)}
                                                            >
                                                                <Ban className="h-4 w-4 text-red-500" />
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

                        {/* Pagination */}
                        {leaveRequests.meta && leaveRequests.meta.last_page > 1 && (
                            <div className="flex justify-center gap-2 mt-6">
                                {leaveRequests.links.map((link: PaginationLink, index: number) => (
                                    <Button
                                        key={index}
                                        variant={link.active ? 'default' : 'outline'}
                                        size="sm"
                                        disabled={!link.url}
                                        onClick={() => link.url && router.visit(link.url)}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
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
        </AppLayout>
    );
}
