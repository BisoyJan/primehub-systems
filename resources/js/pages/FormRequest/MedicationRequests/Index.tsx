import { Head, Link, router, usePage } from '@inertiajs/react';
import React, { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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
import { Plus, Search, Eye, Trash2, X, Check, ChevronsUpDown, XCircle } from 'lucide-react';
import { Can } from '@/components/authorization';
import type { SharedData } from '@/types';
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';

interface MedicationRequest {
    id: number;
    name: string;
    medication_type: string;
    reason: string;
    onset_of_symptoms: string;
    status: 'pending' | 'approved' | 'dispensed' | 'rejected';
    created_at: string;
    user?: {
        name: string;
    };
}

interface User {
    id: number;
    name: string;
}

interface Props {
    medicationRequests: {
        data: MedicationRequest[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: {
        search?: string;
        status?: string;
        medication_type?: string;
    };
    medicationTypes: string[];
    users: User[];
}

export default function Index({ medicationRequests, filters, medicationTypes, users }: Props) {
    const { auth } = usePage<SharedData>().props;
    const canViewActions = ['Super Admin', 'Admin', 'Team Lead', 'HR'].includes(auth.user?.role || '');

    const { title, breadcrumbs } = usePageMeta({
        title: 'Medication Requests',
        breadcrumbs: [
            { title: 'Medication Requests', href: '/form-requests/medication-requests' },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const [selectedUserId, setSelectedUserId] = useState(filters.search || '');
    const [isUserPopoverOpen, setIsUserPopoverOpen] = useState(false);
    const [userSearchQuery, setUserSearchQuery] = useState('');
    const [status, setStatus] = useState(filters.status || '');
    const [medicationType, setMedicationType] = useState(filters.medication_type || '');
    const [deleteId, setDeleteId] = useState<number | null>(null);
    const [cancelId, setCancelId] = useState<number | null>(null);

    // Filter users based on search query
    const filteredUsers = React.useMemo(() => {
        if (!userSearchQuery) return users;
        return users.filter(user =>
            user.name.toLowerCase().includes(userSearchQuery.toLowerCase())
        );
    }, [users, userSearchQuery]);

    const handleSearch = () => {
        router.get('/form-requests/medication-requests', {
            search: selectedUserId || undefined,
            status: status && status !== 'all' ? status : undefined,
            medication_type: medicationType && medicationType !== 'all' ? medicationType : undefined,
        }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const clearFilters = () => {
        setSelectedUserId('');
        setUserSearchQuery('');
        setStatus('');
        setMedicationType('');
        router.get('/form-requests/medication-requests');
    };

    const handleDelete = (id: number) => {
        router.delete(`/form-requests/medication-requests/${id}`, {
            preserveScroll: true,
            onSuccess: () => {
                setDeleteId(null);
            },
        });
    };

    const handleCancel = (id: number) => {
        router.delete(`/form-requests/medication-requests/${id}/cancel`, {
            preserveScroll: true,
            onSuccess: () => {
                setCancelId(null);
            },
        });
    };

    const getStatusBadge = (status: string) => {
        const colors = {
            pending: 'bg-yellow-100 text-yellow-800 border-yellow-300',
            approved: 'bg-blue-100 text-blue-800 border-blue-300',
            dispensed: 'bg-green-100 text-green-800 border-green-300',
            rejected: 'bg-red-100 text-red-800 border-red-300',
        };

        return (
            <Badge variant="outline" className={colors[status as keyof typeof colors]}>
                {status.charAt(0).toUpperCase() + status.slice(1)}
            </Badge>
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isPageLoading} />

                <PageHeader
                    title="Medication Requests"
                    description="Manage medication requests from employees"
                />

                <div className="flex justify-end">
                    <Can permission="medication_requests.create">
                        <Link href="/form-requests/medication-requests/create">
                            <Button>
                                <Plus className="mr-2 h-4 w-4" />
                                New Request
                            </Button>
                        </Link>
                    </Can>
                </div>

                {/* Filters */}
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle>Filter Requests</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-wrap gap-4">
                            <div className="flex-1 min-w-[200px]">
                                <Popover open={isUserPopoverOpen} onOpenChange={setIsUserPopoverOpen}>
                                    <PopoverTrigger asChild>
                                        <Button
                                            variant="outline"
                                            role="combobox"
                                            aria-expanded={isUserPopoverOpen}
                                            className="w-full justify-between font-normal"
                                        >
                                            <span className="truncate">
                                                {selectedUserId
                                                    ? users.find(u => u.id.toString() === selectedUserId)?.name || "Select employee..."
                                                    : "All Employees"}
                                            </span>
                                            <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                        </Button>
                                    </PopoverTrigger>
                                    <PopoverContent className="w-full p-0" align="start">
                                        <Command shouldFilter={false}>
                                            <CommandInput
                                                placeholder="Search employee..."
                                                value={userSearchQuery}
                                                onValueChange={setUserSearchQuery}
                                            />
                                            <CommandList>
                                                <CommandEmpty>No employee found.</CommandEmpty>
                                                <CommandGroup>
                                                    <CommandItem
                                                        value="all"
                                                        onSelect={() => {
                                                            setSelectedUserId("");
                                                            setIsUserPopoverOpen(false);
                                                            setUserSearchQuery("");
                                                        }}
                                                        className="cursor-pointer"
                                                    >
                                                        <Check
                                                            className={`mr-2 h-4 w-4 ${!selectedUserId ? "opacity-100" : "opacity-0"}`}
                                                        />
                                                        All Employees
                                                    </CommandItem>
                                                    {filteredUsers.map((user) => (
                                                        <CommandItem
                                                            key={user.id}
                                                            value={user.name}
                                                            onSelect={() => {
                                                                setSelectedUserId(user.id.toString());
                                                                setIsUserPopoverOpen(false);
                                                                setUserSearchQuery("");
                                                            }}
                                                            className="cursor-pointer"
                                                        >
                                                            <Check
                                                                className={`mr-2 h-4 w-4 ${selectedUserId === user.id.toString()
                                                                    ? "opacity-100"
                                                                    : "opacity-0"
                                                                    }`}
                                                            />
                                                            {user.name}
                                                        </CommandItem>
                                                    ))}
                                                </CommandGroup>
                                            </CommandList>
                                        </Command>
                                    </PopoverContent>
                                </Popover>
                            </div>
                            <Select value={medicationType} onValueChange={setMedicationType}>
                                <SelectTrigger className="w-[180px]">
                                    <SelectValue placeholder="Medication Type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Medications</SelectItem>
                                    {medicationTypes.map((type) => (
                                        <SelectItem key={type} value={type}>
                                            {type}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select value={status} onValueChange={setStatus}>
                                <SelectTrigger className="w-[180px]">
                                    <SelectValue placeholder="Status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Statuses</SelectItem>
                                    <SelectItem value="pending">Pending</SelectItem>
                                    <SelectItem value="approved">Approved</SelectItem>
                                    <SelectItem value="dispensed">Dispensed</SelectItem>
                                    <SelectItem value="rejected">Rejected</SelectItem>
                                </SelectContent>
                            </Select>
                            <Button onClick={handleSearch}>
                                <Search className="mr-2 h-4 w-4" />
                                Search
                            </Button>
                            {(selectedUserId || status || medicationType) && (
                                <Button onClick={clearFilters} variant="outline">
                                    <X className="mr-2 h-4 w-4" />
                                    Clear
                                </Button>
                            )}
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
                                        <TableHead>Employee Name</TableHead>
                                        <TableHead>Medication Type</TableHead>
                                        <TableHead>Onset of Symptoms</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Requested Date</TableHead>
                                        <TableHead className="text-right">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {medicationRequests.data.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={6} className="text-center py-8 text-muted-foreground">
                                                No medication requests found
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        medicationRequests.data.map((request) => (
                                            <TableRow key={request.id}>
                                                <TableCell className="font-medium">{request.name}</TableCell>
                                                <TableCell>{request.medication_type}</TableCell>
                                                <TableCell className="capitalize">{request.onset_of_symptoms.replace(/_/g, ' ')}</TableCell>
                                                <TableCell>{getStatusBadge(request.status)}</TableCell>
                                                <TableCell>{new Date(request.created_at).toLocaleDateString()}</TableCell>
                                                <TableCell className="text-right">
                                                    <div className="flex justify-end gap-2">
                                                        {canViewActions && (
                                                            <Link href={`/form-requests/medication-requests/${request.id}`}>
                                                                <Button variant="ghost" size="sm">
                                                                    <Eye className="h-4 w-4" />
                                                                </Button>
                                                            </Link>
                                                        )}
                                                        {request.user && request.user.name === auth.user?.name && request.status === 'pending' && (
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                onClick={() => setCancelId(request.id)}
                                                            >
                                                                <XCircle className="h-4 w-4 text-orange-600" />
                                                            </Button>
                                                        )}
                                                        {canViewActions && (
                                                            <Can permission="medication_requests.delete">
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    onClick={() => setDeleteId(request.id)}
                                                                >
                                                                    <Trash2 className="h-4 w-4 text-red-600" />
                                                                </Button>
                                                            </Can>
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
                        {medicationRequests.last_page > 1 && (
                            <div className="flex items-center justify-between mt-6">
                                <p className="text-sm text-muted-foreground">
                                    Showing {((medicationRequests.current_page - 1) * medicationRequests.per_page) + 1} to{' '}
                                    {Math.min(medicationRequests.current_page * medicationRequests.per_page, medicationRequests.total)} of{' '}
                                    {medicationRequests.total} results
                                </p>
                                <div className="flex gap-2">
                                    {medicationRequests.current_page > 1 && (
                                        <Button
                                            variant="outline"
                                            onClick={() => router.get(`/form-requests/medication-requests?page=${medicationRequests.current_page - 1}`, {
                                                search: selectedUserId || undefined,
                                                status: status && status !== 'all' ? status : undefined,
                                                medication_type: medicationType && medicationType !== 'all' ? medicationType : undefined,
                                            })}
                                        >
                                            Previous
                                        </Button>
                                    )}
                                    {medicationRequests.current_page < medicationRequests.last_page && (
                                        <Button
                                            variant="outline"
                                            onClick={() => router.get(`/form-requests/medication-requests?page=${medicationRequests.current_page + 1}`, {
                                                search: selectedUserId || undefined,
                                                status: status && status !== 'all' ? status : undefined,
                                                medication_type: medicationType && medicationType !== 'all' ? medicationType : undefined,
                                            })}
                                        >
                                            Next
                                        </Button>
                                    )}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <AlertDialog open={deleteId !== null} onOpenChange={() => setDeleteId(null)}>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Delete Medication Request</AlertDialogTitle>
                            <AlertDialogDescription>
                                Are you sure you want to delete this medication request? This action cannot be undone.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={() => deleteId && handleDelete(deleteId)}
                                className="bg-red-600 hover:bg-red-700"
                            >
                                Delete
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>

                <AlertDialog open={cancelId !== null} onOpenChange={() => setCancelId(null)}>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Cancel Medication Request</AlertDialogTitle>
                            <AlertDialogDescription>
                                Are you sure you want to cancel this medication request? This action cannot be undone.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>No, Keep it</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={() => cancelId && handleCancel(cancelId)}
                                className="bg-orange-600 hover:bg-orange-700"
                            >
                                Yes, Cancel Request
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </div>
        </AppLayout>
    );
}
