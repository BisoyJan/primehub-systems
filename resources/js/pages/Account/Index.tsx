import React, { useEffect, useState, useRef } from "react";
import { router, usePage, Link, Head } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from "@/components/ui/select";
import AppLayout from "@/layouts/app-layout";
import { Input } from "@/components/ui/input";
import PaginationNav, { PaginationLink } from "@/components/pagination-nav";
import {
    index as accountsIndex,
    create as accountsCreate,
    edit as accountsEdit,
    destroy as accountsDestroy,
    approve as accountsApprove,
    unapprove as accountsUnapprove,
    confirmDelete as accountsConfirmDelete,
    restore as accountsRestore,
    forceDelete as accountsForceDelete,
} from "@/routes/accounts";
import { toast } from "sonner";
import { Plus, RefreshCw, Search, Trash2, RotateCcw, CheckCircle, XCircle } from "lucide-react";

// New reusable hooks and components
import { usePageMeta, useFlashMessage, usePageLoading } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { DeleteConfirmDialog } from "@/components/DeleteConfirmDialog";
import { LoadingOverlay } from "@/components/LoadingOverlay";

// Authorization components and hooks
import { Can, HasRole } from "@/components/authorization";

interface User {
    id: number;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    email: string;
    role: string;
    hired_date: string | null;
    created_at: string;
    is_approved: boolean;
    approved_at: string | null;
    deleted_at: string | null;
    deletion_confirmed_at: string | null;
}

interface Meta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}
interface UsersPayload {
    data: User[];
    links: PaginationLink[];
    meta: Meta;
}

interface Filters {
    search: string;
    role: string;
    status: string;
}

export default function AccountIndex() {
    const { users, filters } = usePage<{ users: UsersPayload; filters: Filters }>().props;
    const [loading, setLoading] = useState(false);
    const [search, setSearch] = useState(filters.search || "");
    const [roleFilter, setRoleFilter] = useState(filters.role || "all");
    const [statusFilter, setStatusFilter] = useState(filters.status || "all");
    const [lastRefresh, setLastRefresh] = useState<Date>(new Date());
    const auth = usePage().props.auth as { user?: { id: number } };
    const currentUserId = auth?.user?.id;

    // Use new hooks
    const { title, breadcrumbs } = usePageMeta({
        title: "Account Management",
        breadcrumbs: [{ title: "Accounts", href: accountsIndex().url }]
    });
    useFlashMessage(); // Automatically handles flash messages
    const isPageLoading = usePageLoading();

    const showClearFilters = roleFilter !== "all" || statusFilter !== "all" || Boolean(search);

    const buildFilterParams = (
        searchValue: string,
        roleValue: string,
        statusValue: string,
        options: { resetPage?: boolean } = {}
    ) => {
        const params: Record<string, string | number> = {};
        if (searchValue) params.search = searchValue;
        if (roleValue && roleValue !== "all") params.role = roleValue;
        if (statusValue && statusValue !== "all") params.status = statusValue;
        if (options.resetPage) params.page = 1;
        return params;
    };

    const handleSearch = () => {
        const params = buildFilterParams(search, roleFilter, statusFilter, { resetPage: true });
        setLoading(true);
        router.get(accountsIndex().url, params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: () => setLastRefresh(new Date()),
            onFinish: () => setLoading(false),
        });
    };

    const handleDelete = (userId: number) => {
        setLoading(true);
        router.delete(accountsDestroy(userId).url, {
            preserveScroll: true,
            onFinish: () => setLoading(false),
            onSuccess: () => toast.success("User account deleted successfully"),
            onError: () => toast.error("Failed to delete user account"),
        });
    };

    const handleApprove = (userId: number) => {
        setLoading(true);
        router.post(accountsApprove(userId).url, {}, {
            preserveScroll: true,
            onFinish: () => setLoading(false),
            onSuccess: () => toast.success("User account approved successfully"),
            onError: () => toast.error("Failed to approve user account"),
        });
    };

    const handleUnapprove = (userId: number) => {
        setLoading(true);
        router.post(accountsUnapprove(userId).url, {}, {
            preserveScroll: true,
            onFinish: () => setLoading(false),
            onSuccess: () => toast.success("User account approval revoked successfully"),
            onError: () => toast.error("Failed to revoke user approval"),
        });
    };

    const handleConfirmDelete = (userId: number) => {
        setLoading(true);
        router.post(accountsConfirmDelete(userId).url, {}, {
            preserveScroll: true,
            onFinish: () => setLoading(false),
            onSuccess: () => toast.success("Account deletion confirmed successfully"),
            onError: () => toast.error("Failed to confirm account deletion"),
        });
    };

    const handleRestore = (userId: number) => {
        setLoading(true);
        router.post(accountsRestore(userId).url, {}, {
            preserveScroll: true,
            onFinish: () => setLoading(false),
            onSuccess: () => toast.success("Account restored successfully"),
            onError: () => toast.error("Failed to restore account"),
        });
    };

    const handleForceDelete = (userId: number) => {
        setLoading(true);
        router.delete(accountsForceDelete(userId).url, {
            preserveScroll: true,
            onFinish: () => setLoading(false),
            onSuccess: () => toast.success("Account permanently deleted"),
            onError: () => toast.error("Failed to permanently delete account"),
        });
    };

    const clearFilters = () => {
        setSearch("");
        setRoleFilter("all");
        setStatusFilter("all");
        // Trigger a reload with cleared filters
        setLoading(true);
        router.get(accountsIndex().url, {}, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: () => setLastRefresh(new Date()),
            onFinish: () => setLoading(false),
        });
    };

    const handleManualRefresh = () => {
        setLoading(true);
        router.get(accountsIndex().url, buildFilterParams(search, roleFilter, statusFilter), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ["users"],
            onSuccess: () => setLastRefresh(new Date()),
            onFinish: () => setLoading(false),
        });
    };

    const getRoleBadgeColor = (role: string) => {
        switch (role) {
            case 'Super Admin':
                return 'bg-purple-100 text-purple-800 border-purple-200';
            case 'Admin':
                return 'bg-blue-100 text-blue-800 border-blue-200';
            case 'HR':
                return 'bg-green-100 text-green-800 border-green-200';
            case 'Agent':
                return 'bg-gray-100 text-gray-800 border-gray-200';
            default:
                return 'bg-gray-100 text-gray-800 border-gray-200';
        }
    };

    const getStatusBadge = (user: User) => {
        if (user.deleted_at && user.deletion_confirmed_at) {
            return {
                label: 'Deleted',
                className: 'bg-red-100 text-red-800 border-red-200'
            };
        }
        if (user.deleted_at && !user.deletion_confirmed_at) {
            return {
                label: 'Pending Deletion',
                className: 'bg-orange-100 text-orange-800 border-orange-200'
            };
        }
        if (user.is_approved) {
            return {
                label: 'Approved',
                className: 'bg-green-100 text-green-800 border-green-200'
            };
        }
        return {
            label: 'Pending',
            className: 'bg-yellow-100 text-yellow-800 border-yellow-200'
        };
    };

    const isPendingDeletion = (user: User) => user.deleted_at && !user.deletion_confirmed_at;
    const isDeleted = (user: User) => user.deleted_at && user.deletion_confirmed_at;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3 relative">
                {/* Loading overlay for page transitions */}
                <LoadingOverlay isLoading={isPageLoading || loading} message="Loading accounts..." />

                <PageHeader
                    title="Account Management"
                    description="Manage user accounts and permissions"
                />

                <div className="flex flex-col gap-3">
                    <div className="grid grid-cols-1 gap-3 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)]">
                        <Input
                            type="search"
                            placeholder="Search by name or email..."
                            value={search}
                            onChange={e => setSearch(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                            className="w-full"
                        />

                        <Select value={roleFilter} onValueChange={setRoleFilter}>
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Filter by Role" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Roles</SelectItem>
                                <SelectItem value="Super Admin">Super Admin</SelectItem>
                                <SelectItem value="Admin">Admin</SelectItem>
                                <SelectItem value="Agent">Agent</SelectItem>
                                <SelectItem value="HR">HR</SelectItem>
                                <SelectItem value="IT">IT</SelectItem>
                            </SelectContent>
                        </Select>

                        <Select value={statusFilter} onValueChange={setStatusFilter}>
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Filter by Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Statuses</SelectItem>
                                <SelectItem value="active">Active</SelectItem>
                                <SelectItem value="pending_deletion">Pending Deletion</SelectItem>
                                <SelectItem value="deleted">Deleted</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <Can permission="accounts.create">
                            <Button onClick={() => router.get(accountsCreate().url)} className="w-full sm:w-auto">
                                <Plus className="mr-2 h-4 w-4" />
                                Create Account
                            </Button>
                        </Can>

                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end sm:flex-1">
                            <Button variant="default" onClick={handleSearch} className="w-full sm:w-auto">
                                <Search className="mr-2 h-4 w-4" />
                                Apply Filters
                            </Button>

                            {showClearFilters && (
                                <Button variant="outline" onClick={clearFilters} className="w-full sm:w-auto">
                                    Clear Filters
                                </Button>
                            )}

                            <Button variant="ghost" onClick={handleManualRefresh} disabled={loading} className="w-full sm:w-auto">
                                <RefreshCw className={`mr-2 h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
                                Refresh
                            </Button>
                        </div>
                    </div>
                </div>

                <div className="flex flex-col gap-2 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        Showing {users.data.length} of {users.meta.total} user account{users.meta.total !== 1 ? 's' : ''}
                        {showClearFilters && ' (filtered)'}
                    </div>
                    <div className="text-xs">
                        Last updated: {lastRefresh.toLocaleTimeString()}
                    </div>
                </div>

                {/* Desktop Table */}
                <div className="hidden md:block shadow rounded-md overflow-hidden">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>ID</TableHead>
                                    <TableHead>First Name</TableHead>
                                    <TableHead>M.I.</TableHead>
                                    <TableHead>Last Name</TableHead>
                                    <TableHead>Email</TableHead>
                                    <TableHead>Role</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Hired Date</TableHead>
                                    <TableHead>Created At</TableHead>
                                    <TableHead>Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {users.data.map((user) => {
                                    const statusBadge = getStatusBadge(user);
                                    return (
                                        <TableRow key={user.id} className={isPendingDeletion(user) ? 'bg-orange-50' : isDeleted(user) ? 'bg-red-50' : ''}>
                                            <TableCell>{user.id}</TableCell>
                                            <TableCell className="font-medium">{user.first_name}</TableCell>
                                            <TableCell>{user.middle_name || '-'}</TableCell>
                                            <TableCell className="font-medium">{user.last_name}</TableCell>
                                            <TableCell>{user.email}</TableCell>
                                            <TableCell>
                                                <span className={`px-3 py-1 rounded-full text-xs font-medium border ${getRoleBadgeColor(user.role)}`}>
                                                    {user.role}
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                <span className={`px-3 py-1 rounded-full text-xs font-medium border ${statusBadge.className}`}>
                                                    {statusBadge.label}
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                {user.hired_date ? new Date(user.hired_date).toLocaleDateString() : '-'}
                                            </TableCell>
                                            <TableCell>{new Date(user.created_at).toLocaleDateString()}</TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    {/* Show different actions based on deletion status */}
                                                    {isPendingDeletion(user) ? (
                                                        <>
                                                            {/* Confirm Delete Button - Only for Super Admin, Admin, IT */}
                                                            <HasRole role={['Super Admin', 'Admin', 'IT']}>
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    onClick={() => handleConfirmDelete(user.id)}
                                                                    disabled={loading || user.id === currentUserId}
                                                                    className="text-red-600 hover:text-red-700 border-red-300"
                                                                >
                                                                    <CheckCircle className="mr-1 h-4 w-4" />
                                                                    Confirm Delete
                                                                </Button>
                                                            </HasRole>

                                                            {/* Restore Button */}
                                                            <Can permission="accounts.edit">
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    onClick={() => handleRestore(user.id)}
                                                                    disabled={loading}
                                                                    className="text-green-600 hover:text-green-700 border-green-300"
                                                                >
                                                                    <RotateCcw className="mr-1 h-4 w-4" />
                                                                    Restore
                                                                </Button>
                                                            </Can>
                                                        </>
                                                    ) : isDeleted(user) ? (
                                                        <HasRole role={['Super Admin', 'Admin', 'IT']}>
                                                            <DeleteConfirmDialog
                                                                onConfirm={() => handleForceDelete(user.id)}
                                                                title="Permanently Delete Account"
                                                                description={`Are you sure you want to PERMANENTLY delete the account for "${user.first_name} ${user.middle_name ? user.middle_name + '. ' : ''}${user.last_name}"? This action cannot be undone and all data will be lost forever.`}
                                                                disabled={loading || user.id === currentUserId}
                                                                trigger={
                                                                    <Button
                                                                        variant="destructive"
                                                                        size="sm"
                                                                        disabled={loading || user.id === currentUserId}
                                                                    >
                                                                        <XCircle className="mr-1 h-4 w-4" />
                                                                        Permanently Delete
                                                                    </Button>
                                                                }
                                                            />
                                                        </HasRole>
                                                    ) : (
                                                        <>
                                                            <Can permission="accounts.edit">
                                                                <Link href={accountsEdit(user.id).url}>
                                                                    <Button variant="outline" size="sm" disabled={loading}>
                                                                        Edit
                                                                    </Button>
                                                                </Link>
                                                            </Can>

                                                            <Can permission="accounts.edit">
                                                                {user.is_approved ? (
                                                                    <Button
                                                                        variant="outline"
                                                                        size="sm"
                                                                        onClick={() => handleUnapprove(user.id)}
                                                                        disabled={loading || user.id === currentUserId}
                                                                        className="text-yellow-600 hover:text-yellow-700 border-yellow-300"
                                                                    >
                                                                        Revoke
                                                                    </Button>
                                                                ) : (
                                                                    <Button
                                                                        variant="outline"
                                                                        size="sm"
                                                                        onClick={() => handleApprove(user.id)}
                                                                        disabled={loading}
                                                                        className="text-green-600 hover:text-green-700 border-green-300"
                                                                    >
                                                                        Approve
                                                                    </Button>
                                                                )}
                                                            </Can>

                                                            <Can permission="accounts.delete">
                                                                <DeleteConfirmDialog
                                                                    onConfirm={() => handleDelete(user.id)}
                                                                    title="Delete User Account"
                                                                    description={`Are you sure you want to delete the account for "${user.first_name} ${user.middle_name ? user.middle_name + '. ' : ''}${user.last_name}"? The account will be marked for deletion and require admin confirmation.`}
                                                                    disabled={loading || user.id === currentUserId}
                                                                />
                                                            </Can>
                                                        </>
                                                    )}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                                {users.data.length === 0 && !loading && (
                                    <TableRow>
                                        <TableCell colSpan={10} className="py-8 text-center text-gray-500">
                                            No user accounts found
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </div>

                {/* Mobile Cards */}
                <div className="md:hidden space-y-3">
                    {users.data.map((user) => {
                        const statusBadge = getStatusBadge(user);
                        return (
                            <div key={user.id} className={`rounded-lg shadow p-4 space-y-3 ${isPendingDeletion(user) ? 'bg-orange-50 border border-orange-200' : isDeleted(user) ? 'bg-red-50 border border-red-200' : ''}`}>
                                <div className="flex justify-between items-start">
                                    <div className="flex-1">
                                        <h3 className="font-semibold text-lg">
                                            {user.first_name} {user.middle_name ? `${user.middle_name}. ` : ''}{user.last_name}
                                        </h3>
                                        <p className="text-sm text-gray-600">{user.email}</p>
                                    </div>
                                    <div className="flex flex-col gap-2 items-end">
                                        <span className={`px-2 py-1 rounded-full text-xs font-medium border ${getRoleBadgeColor(user.role)}`}>
                                            {user.role}
                                        </span>
                                        <span className={`px-2 py-1 rounded-full text-xs font-medium border ${statusBadge.className}`}>
                                            {statusBadge.label}
                                        </span>
                                    </div>
                                </div>
                                <div className="text-xs text-gray-500">
                                    Hired: {user.hired_date ? new Date(user.hired_date).toLocaleDateString() : 'Not set'}
                                </div>
                                <div className="text-xs text-gray-500">
                                    Created: {new Date(user.created_at).toLocaleDateString()}
                                </div>
                                <div className="flex flex-col gap-2 pt-2">
                                    {isPendingDeletion(user) ? (
                                        <>
                                            <HasRole role={['Super Admin', 'Admin', 'IT']}>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => handleConfirmDelete(user.id)}
                                                    disabled={loading || user.id === currentUserId}
                                                    className="w-full text-red-600 hover:text-red-700 border-red-300"
                                                >
                                                    <CheckCircle className="mr-1 h-4 w-4" />
                                                    Confirm Delete
                                                </Button>
                                            </HasRole>
                                            <Can permission="accounts.edit">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => handleRestore(user.id)}
                                                    disabled={loading}
                                                    className="w-full text-green-600 hover:text-green-700 border-green-300"
                                                >
                                                    <RotateCcw className="mr-1 h-4 w-4" />
                                                    Restore Account
                                                </Button>
                                            </Can>
                                        </>
                                    ) : isDeleted(user) ? (
                                        <HasRole role={['Super Admin', 'Admin', 'IT']}>
                                            <DeleteConfirmDialog
                                                onConfirm={() => handleForceDelete(user.id)}
                                                title="Permanently Delete Account"
                                                description={`Are you sure you want to PERMANENTLY delete the account for "${user.first_name} ${user.middle_name ? user.middle_name + '. ' : ''}${user.last_name}"? This action cannot be undone and all data will be lost forever.`}
                                                disabled={loading || user.id === currentUserId}
                                                trigger={
                                                    <Button
                                                        variant="destructive"
                                                        size="sm"
                                                        className="w-full"
                                                        disabled={loading || user.id === currentUserId}
                                                    >
                                                        <XCircle className="mr-1 h-4 w-4" />
                                                        Permanently Delete
                                                    </Button>
                                                }
                                            />
                                        </HasRole>
                                    ) : (
                                        <>
                                            <div className="flex gap-2">
                                                <Can permission="accounts.edit">
                                                    <Link href={accountsEdit(user.id).url} className="flex-1">
                                                        <Button variant="outline" size="sm" className="w-full" disabled={loading}>
                                                            Edit
                                                        </Button>
                                                    </Link>
                                                </Can>

                                                <Can permission="accounts.delete">
                                                    <div className="flex-1">
                                                        <DeleteConfirmDialog
                                                            onConfirm={() => handleDelete(user.id)}
                                                            title="Delete User Account"
                                                            description={`Are you sure you want to delete the account for "${user.first_name} ${user.middle_name ? user.middle_name + '. ' : ''}${user.last_name}"? The account will be marked for deletion and require admin confirmation.`}
                                                            disabled={loading || user.id === currentUserId}
                                                            triggerClassName="w-full"
                                                        />
                                                    </div>
                                                </Can>
                                            </div>
                                            <Can permission="accounts.edit">
                                                {user.is_approved ? (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => handleUnapprove(user.id)}
                                                        disabled={loading || user.id === currentUserId}
                                                        className="w-full text-yellow-600 hover:text-yellow-700 border-yellow-300"
                                                    >
                                                        Revoke Approval
                                                    </Button>
                                                ) : (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => handleApprove(user.id)}
                                                        disabled={loading}
                                                        className="w-full text-green-600 hover:text-green-700 border-green-300"
                                                    >
                                                        Approve Account
                                                    </Button>
                                                )}
                                            </Can>
                                        </>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                    {users.data.length === 0 && !loading && (
                        <div className="text-center py-8 text-gray-500">
                            No user accounts found
                        </div>
                    )}
                </div>

                {/* Pagination */}
                <div className="flex justify-center mt-4">
                    {users.links && users.links.length > 0 && (
                        <PaginationNav links={users.links} />
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
