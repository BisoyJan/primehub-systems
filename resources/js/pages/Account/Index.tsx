import React, { useState, useMemo, useEffect } from "react";
import { router, usePage, Link, Head } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from "@/components/ui/select";
import { Switch } from "@/components/ui/switch";
import AppLayout from "@/layouts/app-layout";
import { Input } from "@/components/ui/input";
import { Checkbox } from "@/components/ui/checkbox";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import { useInitials } from "@/hooks/use-initials";
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from "@/components/ui/command";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import PaginationNav, { PaginationLink } from "@/components/pagination-nav";
import {
    index as accountsIndex,
    create as accountsCreate,
    edit as accountsEdit,
    destroy as accountsDestroy,
    approve as accountsApprove,
    unapprove as accountsUnapprove,
    toggleActive as accountsToggleActive,
    confirmDelete as accountsConfirmDelete,
    restore as accountsRestore,
    forceDelete as accountsForceDelete,
    bulkApprove as accountsBulkApprove,
    bulkUnapprove as accountsBulkUnapprove,
} from "@/routes/accounts";
import { toast } from "sonner";
import { Plus, RefreshCw, Search, RotateCcw, CheckCircle, XCircle, CheckSquare, XSquare, X, UserX, Play, Pause, Check, ChevronsUpDown, UserCheck, Mail, AlertTriangle, Pencil, Trash2, UserCheck2, UserMinus } from "lucide-react";

// New reusable hooks and components
import { usePageMeta, useFlashMessage, usePageLoading } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { DeleteConfirmDialog } from "@/components/DeleteConfirmDialog";
import { LoadingOverlay } from "@/components/LoadingOverlay";

// Authorization components and hooks
import { Can, HasRole } from "@/components/authorization";

import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from "@/components/ui/alert-dialog";

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
    is_active: boolean;
    approved_at: string | null;
    deleted_at: string | null;
    deletion_confirmed_at: string | null;
    avatar?: string;
    avatar_url?: string;
}

interface UserOption {
    id: number;
    name: string;
    email: string;
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
    employee_status: string;
    user_id: string;
}

export default function AccountIndex() {
    const { users, allUsers = [], filters } = usePage<{ users: UsersPayload; allUsers: UserOption[]; filters: Filters }>().props;
    const [loading, setLoading] = useState(false);
    const getInitials = useInitials();
    const [search, setSearch] = useState(filters.search || "");
    const [selectedUserId, setSelectedUserId] = useState(filters.user_id || "");
    const [isUserPopoverOpen, setIsUserPopoverOpen] = useState(false);
    const [userSearchQuery, setUserSearchQuery] = useState("");
    const [roleFilter, setRoleFilter] = useState(filters.role || "all");
    const [statusFilter, setStatusFilter] = useState(filters.status || "all");
    const [employeeStatusFilter, setEmployeeStatusFilter] = useState(filters.employee_status || "all");
    const [lastRefresh, setLastRefresh] = useState<Date>(new Date());
    const [autoRefreshEnabled, setAutoRefreshEnabled] = useState(false);
    const [selectedApproveIds, setSelectedApproveIds] = useState<number[]>([]);
    const [selectedRevokeIds, setSelectedRevokeIds] = useState<number[]>([]);
    const [toggleActiveDialogOpen, setToggleActiveDialogOpen] = useState(false);
    const [userToToggle, setUserToToggle] = useState<User | null>(null);
    // Revoke dialog state
    const [revokeDialogOpen, setRevokeDialogOpen] = useState(false);
    const [userToRevoke, setUserToRevoke] = useState<User | null>(null);
    const [bulkRevokeDialogOpen, setBulkRevokeDialogOpen] = useState(false);
    const auth = usePage().props.auth as { user?: { id: number } };
    const currentUserId = auth?.user?.id;

    // Get users that can be selected for bulk approve (not approved, not deleted, not current user)
    const approvableUsers = useMemo(() =>
        users.data.filter(user =>
            !user.is_approved &&
            !user.deleted_at &&
            user.id !== currentUserId
        ),
        [users.data, currentUserId]
    );

    // Get users that can be selected for bulk revoke (approved, not deleted, not current user)
    const revokableUsers = useMemo(() =>
        users.data.filter(user =>
            user.is_approved &&
            !user.deleted_at &&
            user.id !== currentUserId
        ),
        [users.data, currentUserId]
    );

    const allApprovableSelected = approvableUsers.length > 0 &&
        approvableUsers.every(user => selectedApproveIds.includes(user.id));

    const allRevokableSelected = revokableUsers.length > 0 &&
        revokableUsers.every(user => selectedRevokeIds.includes(user.id));

    const someApproveSelected = selectedApproveIds.length > 0;
    const someRevokeSelected = selectedRevokeIds.length > 0;

    // Use new hooks
    const { title, breadcrumbs } = usePageMeta({
        title: "Account Management",
        breadcrumbs: [{ title: "Accounts", href: accountsIndex().url }]
    });
    useFlashMessage(); // Automatically handles flash messages
    const isPageLoading = usePageLoading();

    // Filter users based on search query for the popover
    const filteredUsers = useMemo(() => {
        if (!userSearchQuery) return allUsers;
        const query = userSearchQuery.toLowerCase();
        return allUsers.filter(user =>
            user.name.toLowerCase().includes(query) ||
            user.email.toLowerCase().includes(query)
        );
    }, [allUsers, userSearchQuery]);

    // Clear user search query when popover closes
    useEffect(() => {
        if (!isUserPopoverOpen) {
            setUserSearchQuery("");
        }
    }, [isUserPopoverOpen]);

    // Auto-refresh every 30 seconds (only when enabled)
    useEffect(() => {
        if (!autoRefreshEnabled) return;

        const interval = setInterval(() => {
            router.get(accountsIndex().url, buildFilterParams(search, selectedUserId, roleFilter, statusFilter, employeeStatusFilter), {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ["users"],
                onSuccess: () => setLastRefresh(new Date()),
            });
        }, 30000);

        return () => clearInterval(interval);
    }, [autoRefreshEnabled, search, selectedUserId, roleFilter, statusFilter, employeeStatusFilter]);

    const showClearFilters = roleFilter !== "all" || statusFilter !== "all" || employeeStatusFilter !== "all" || Boolean(search) || Boolean(selectedUserId);

    const buildFilterParams = (
        searchValue: string,
        userIdValue: string,
        roleValue: string,
        statusValue: string,
        employeeStatusValue: string,
        options: { resetPage?: boolean } = {}
    ) => {
        const params: Record<string, string | number> = {};
        if (searchValue) params.search = searchValue;
        if (userIdValue) params.user_id = userIdValue;
        if (roleValue && roleValue !== "all") params.role = roleValue;
        if (statusValue && statusValue !== "all") params.status = statusValue;
        if (employeeStatusValue && employeeStatusValue !== "all") params.employee_status = employeeStatusValue;
        if (options.resetPage) params.page = 1;
        return params;
    };

    const handleSearch = () => {
        const params = buildFilterParams(search, selectedUserId, roleFilter, statusFilter, employeeStatusFilter, { resetPage: true });
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

    const handleUnapprove = (user: User) => {
        // If user has a hired date (employee), show dialog with email option
        if (user.hired_date) {
            setUserToRevoke(user);
            setRevokeDialogOpen(true);
        } else {
            // No hired date, just revoke directly
            confirmUnapprove(user.id, false);
        }
    };

    const confirmUnapprove = (userId: number, sendEmail: boolean) => {
        setLoading(true);
        router.post(accountsUnapprove(userId).url, { send_email: sendEmail }, {
            preserveScroll: true,
            onFinish: () => {
                setLoading(false);
                setRevokeDialogOpen(false);
                setUserToRevoke(null);
            },
            onSuccess: () => toast.success(sendEmail
                ? "User account approval revoked and notification sent"
                : "User account approval revoked successfully"),
            onError: () => toast.error("Failed to revoke user approval"),
        });
    };

    const handleToggleActive = (user: User) => {
        // If deactivating, show confirmation dialog
        if (user.is_active) {
            setUserToToggle(user);
            setToggleActiveDialogOpen(true);
        } else {
            // Activating - no confirmation needed
            confirmToggleActive(user.id);
        }
    };

    const confirmToggleActive = (userId: number) => {
        setLoading(true);
        router.post(accountsToggleActive(userId).url, {}, {
            preserveScroll: true,
            onFinish: () => {
                setLoading(false);
                setToggleActiveDialogOpen(false);
                setUserToToggle(null);
            },
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

    const toggleSelectApprove = (userId: number) => {
        setSelectedApproveIds(prev =>
            prev.includes(userId)
                ? prev.filter(id => id !== userId)
                : [...prev, userId]
        );
    };

    const toggleSelectRevoke = (userId: number) => {
        setSelectedRevokeIds(prev =>
            prev.includes(userId)
                ? prev.filter(id => id !== userId)
                : [...prev, userId]
        );
    };

    const toggleSelectAllApprove = () => {
        if (allApprovableSelected) {
            setSelectedApproveIds([]);
        } else {
            setSelectedApproveIds(approvableUsers.map(user => user.id));
        }
    };

    const toggleSelectAllRevoke = () => {
        if (allRevokableSelected) {
            setSelectedRevokeIds([]);
        } else {
            setSelectedRevokeIds(revokableUsers.map(user => user.id));
        }
    };

    const handleBulkApprove = () => {
        if (selectedApproveIds.length === 0) {
            toast.error("Please select at least one account to approve");
            return;
        }

        setLoading(true);
        router.post(accountsBulkApprove().url, { ids: selectedApproveIds }, {
            preserveScroll: true,
            onFinish: () => {
                setLoading(false);
                setSelectedApproveIds([]);
            },
            onSuccess: () => toast.success(`${selectedApproveIds.length} account(s) approved successfully`),
            onError: () => toast.error("Failed to approve selected accounts"),
        });
    };

    const handleBulkRevoke = () => {
        if (selectedRevokeIds.length === 0) {
            toast.error("Please select at least one account to revoke");
            return;
        }

        // Check if any selected users have hired dates (are employees)
        const selectedUsersWithHiredDates = users.data.filter(
            user => selectedRevokeIds.includes(user.id) && user.hired_date
        );

        if (selectedUsersWithHiredDates.length > 0) {
            // Show dialog with email option
            setBulkRevokeDialogOpen(true);
        } else {
            // No employees with hired dates, just revoke directly
            confirmBulkRevoke(false);
        }
    };

    const confirmBulkRevoke = (sendEmail: boolean) => {
        setLoading(true);
        router.post(accountsBulkUnapprove().url, { ids: selectedRevokeIds, send_email: sendEmail }, {
            preserveScroll: true,
            onFinish: () => {
                setLoading(false);
                setSelectedRevokeIds([]);
                setBulkRevokeDialogOpen(false);
            },
            onSuccess: () => toast.success(sendEmail
                ? `${selectedRevokeIds.length} account(s) approval revoked and notifications sent`
                : `${selectedRevokeIds.length} account(s) approval revoked successfully`),
            onError: () => toast.error("Failed to revoke approval for selected accounts"),
        });
    };

    const clearFilters = () => {
        setSearch("");
        setSelectedUserId("");
        setRoleFilter("all");
        setStatusFilter("all");
        setEmployeeStatusFilter("all");
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
        router.get(accountsIndex().url, buildFilterParams(search, selectedUserId, roleFilter, statusFilter, employeeStatusFilter), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ["users"],
            onSuccess: () => setLastRefresh(new Date()),
            onFinish: () => setLoading(false),
        });
    };

    const handlePageChange = (page: number) => {
        setLoading(true);
        router.get(accountsIndex().url, { ...buildFilterParams(search, selectedUserId, roleFilter, statusFilter, employeeStatusFilter), page }, {
            preserveState: true,
            preserveScroll: true,
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
        // User has hired_date but is not approved = Resigned employee
        if (user.hired_date && !user.is_approved) {
            return {
                label: 'Resigned',
                className: 'bg-purple-100 text-purple-800 border-purple-200'
            };
        }
        // User has no hired_date and is not approved = Pending applicant
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
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
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
                                            ? allUsers.find(u => u.id.toString() === selectedUserId)?.name || "Select employee..."
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
                                                    }}
                                                    className="cursor-pointer"
                                                >
                                                    <Check
                                                        className={`mr-2 h-4 w-4 ${selectedUserId === user.id.toString()
                                                            ? "opacity-100"
                                                            : "opacity-0"
                                                            }`}
                                                    />
                                                    <div className="flex flex-col">
                                                        <span>{user.name}</span>
                                                        <span className="text-xs text-muted-foreground">{user.email}</span>
                                                    </div>
                                                </CommandItem>
                                            ))}
                                        </CommandGroup>
                                    </CommandList>
                                </Command>
                            </PopoverContent>
                        </Popover>

                        <Select value={roleFilter} onValueChange={setRoleFilter}>
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Filter by Role" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Roles</SelectItem>
                                <SelectItem value="Super Admin">Super Admin</SelectItem>
                                <SelectItem value="Admin">Admin</SelectItem>
                                <SelectItem value="Team Lead">Team Lead</SelectItem>
                                <SelectItem value="Agent">Agent</SelectItem>
                                <SelectItem value="HR">HR</SelectItem>
                                <SelectItem value="IT">IT</SelectItem>
                                <SelectItem value="Utility">Utility</SelectItem>
                            </SelectContent>
                        </Select>

                        <Select value={statusFilter} onValueChange={setStatusFilter}>
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Filter by Account Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Account Statuses</SelectItem>
                                <SelectItem value="pending">Pending</SelectItem>
                                <SelectItem value="approved">Approved</SelectItem>
                                <SelectItem value="pending_deletion">Pending Deletion</SelectItem>
                                <SelectItem value="deleted">Deleted</SelectItem>
                            </SelectContent>
                        </Select>

                        <Select value={employeeStatusFilter} onValueChange={setEmployeeStatusFilter}>
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Filter by Employee Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Employee Statuses</SelectItem>
                                <SelectItem value="active">Active Employees</SelectItem>
                                <SelectItem value="inactive">Inactive Employees</SelectItem>
                            </SelectContent>
                        </Select>

                        <Input
                            type="search"
                            placeholder="Search by name or email..."
                            value={search}
                            onChange={e => setSearch(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                            className="w-full"
                        />
                    </div>

                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                            <Can permission="accounts.create">
                                <Button onClick={() => router.get(accountsCreate().url)} className="w-full sm:w-auto">
                                    <Plus className="mr-2 h-4 w-4" />
                                    Create Account
                                </Button>
                            </Can>

                            <Can permission="accounts.edit">
                                {someApproveSelected && (
                                    <Button
                                        onClick={handleBulkApprove}
                                        disabled={loading}
                                        className="w-full sm:w-auto bg-green-600 hover:bg-green-700"
                                    >
                                        <CheckSquare className="mr-2 h-4 w-4" />
                                        Bulk Approve ({selectedApproveIds.length})
                                    </Button>
                                )}
                            </Can>

                            <Can permission="accounts.edit">
                                {someRevokeSelected && (
                                    <Button
                                        onClick={handleBulkRevoke}
                                        disabled={loading}
                                        className="w-full sm:w-auto bg-yellow-600 hover:bg-yellow-700"
                                    >
                                        <XSquare className="mr-2 h-4 w-4" />
                                        Bulk Revoke ({selectedRevokeIds.length})
                                    </Button>
                                )}
                            </Can>

                            <Can permission="accounts.edit">
                                {(someApproveSelected || someRevokeSelected) && (
                                    <Button
                                        variant="outline"
                                        onClick={() => {
                                            setSelectedApproveIds([]);
                                            setSelectedRevokeIds([]);
                                        }}
                                        disabled={loading}
                                        className="w-full sm:w-auto"
                                    >
                                        <X className="mr-2 h-4 w-4" />
                                        Clear Selection ({selectedApproveIds.length + selectedRevokeIds.length})
                                    </Button>
                                )}
                            </Can>
                        </div>

                        <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end sm:flex-1">
                            <Button variant="default" onClick={handleSearch} className="w-full sm:w-auto">
                                <Search className="mr-2 h-4 w-4" />
                                Apply Filters
                            </Button>

                            {showClearFilters && (
                                <Button variant="outline" onClick={clearFilters} className="w-full sm:w-auto">
                                    Clear Filters
                                </Button>
                            )}

                            <div className="flex gap-2">
                                <Button variant="ghost" onClick={handleManualRefresh} disabled={loading} size="icon" title="Refresh">
                                    <RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
                                </Button>

                                <Button
                                    variant={autoRefreshEnabled ? "default" : "ghost"}
                                    onClick={() => setAutoRefreshEnabled(!autoRefreshEnabled)}
                                    size="icon"
                                    title={autoRefreshEnabled ? "Disable auto-refresh" : "Enable auto-refresh (30s)"}
                                >
                                    {autoRefreshEnabled ? <Pause className="h-4 w-4" /> : <Play className="h-4 w-4" />}
                                </Button>
                            </div>
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
                                <TableRow className="bg-muted/50">
                                    <Can permission="accounts.edit">
                                        <TableHead className="w-12">
                                            <div className="flex flex-col gap-1">
                                                {approvableUsers.length > 0 && (
                                                    <Checkbox
                                                        checked={allApprovableSelected}
                                                        onCheckedChange={toggleSelectAllApprove}
                                                        aria-label="Select all pending"
                                                        className="border-green-500 data-[state=checked]:bg-green-600"
                                                    />
                                                )}
                                                {revokableUsers.length > 0 && (
                                                    <Checkbox
                                                        checked={allRevokableSelected}
                                                        onCheckedChange={toggleSelectAllRevoke}
                                                        aria-label="Select all approved"
                                                        className="border-yellow-500 data-[state=checked]:bg-yellow-600"
                                                    />
                                                )}
                                            </div>
                                        </TableHead>
                                    </Can>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Email</TableHead>
                                    <TableHead>Role</TableHead>
                                    <TableHead>Employee Status</TableHead>
                                    <TableHead>Account Status</TableHead>
                                    <TableHead>Hired Date</TableHead>
                                    <TableHead>Created At</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {users.data.map((user) => {
                                    const statusBadge = getStatusBadge(user);
                                    return (
                                        <TableRow key={user.id} className={isPendingDeletion(user) ? 'bg-orange-50 dark:bg-orange-950/50' : isDeleted(user) ? 'bg-red-50 dark:bg-red-950/50' : ''}>
                                            <Can permission="accounts.edit">
                                                <TableCell>
                                                    {!user.deleted_at && user.id !== currentUserId && (
                                                        user.is_approved ? (
                                                            <Checkbox
                                                                checked={selectedRevokeIds.includes(user.id)}
                                                                onCheckedChange={() => toggleSelectRevoke(user.id)}
                                                                aria-label={`Select ${user.first_name} ${user.last_name} for revoke`}
                                                                className="border-yellow-500 data-[state=checked]:bg-yellow-600"
                                                            />
                                                        ) : (
                                                            <Checkbox
                                                                checked={selectedApproveIds.includes(user.id)}
                                                                onCheckedChange={() => toggleSelectApprove(user.id)}
                                                                aria-label={`Select ${user.first_name} ${user.last_name} for approve`}
                                                                className="border-green-500 data-[state=checked]:bg-green-600"
                                                            />
                                                        )
                                                    )}
                                                </TableCell>
                                            </Can>
                                            <TableCell className="font-medium">
                                                <div className="flex items-center gap-3">
                                                    <Avatar className="h-10 w-10 overflow-hidden rounded-full">
                                                        <AvatarImage src={user.avatar_url} alt={`${user.first_name} ${user.last_name}`} />
                                                        <AvatarFallback className="rounded-full bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white text-sm">
                                                            {getInitials(`${user.first_name} ${user.last_name}`)}
                                                        </AvatarFallback>
                                                    </Avatar>
                                                    <span>
                                                        {user.first_name} {user.middle_name ? `${user.middle_name}. ` : ''}{user.last_name}
                                                    </span>
                                                </div>
                                            </TableCell>
                                            <TableCell>{user.email}</TableCell>
                                            <TableCell>
                                                <span className={`px-3 py-1 rounded-full text-xs font-medium border ${getRoleBadgeColor(user.role)}`}>
                                                    {user.role}
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-2">
                                                    <Can permission="accounts.edit">
                                                        {!user.deleted_at && user.id !== currentUserId && (
                                                            <Switch
                                                                checked={user.is_active}
                                                                onCheckedChange={() => handleToggleActive(user)}
                                                                aria-label="Toggle employee active status"
                                                            />
                                                        )}
                                                    </Can>
                                                    <div className={`flex items-center justify-center px-3 py-1 rounded-full border ${user.is_active
                                                        ? 'bg-green-100 text-green-800 border-green-300 dark:bg-green-900/30 dark:text-green-400 dark:border-green-700'
                                                        : 'bg-gray-100 text-gray-800 border-gray-300 dark:bg-gray-900/30 dark:text-gray-400 dark:border-gray-700'
                                                        }`}>
                                                        {user.is_active ? <UserCheck className="h-4 w-4" /> : <UserX className="h-4 w-4" />}
                                                    </div>
                                                </div>
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
                                                                    size="icon"
                                                                    onClick={() => handleConfirmDelete(user.id)}
                                                                    disabled={loading || user.id === currentUserId}
                                                                    className="text-red-600 hover:text-red-700 border-red-300"
                                                                    title="Confirm Delete"
                                                                >
                                                                    <CheckCircle className="h-4 w-4" />
                                                                </Button>
                                                            </HasRole>

                                                            {/* Restore Button */}
                                                            <Can permission="accounts.edit">
                                                                <Button
                                                                    variant="outline"
                                                                    size="icon"
                                                                    onClick={() => handleRestore(user.id)}
                                                                    disabled={loading}
                                                                    className="text-green-600 hover:text-green-700 border-green-300"
                                                                    title="Restore Account"
                                                                >
                                                                    <RotateCcw className="h-4 w-4" />
                                                                </Button>
                                                            </Can>
                                                        </>
                                                    ) : isDeleted(user) ? (
                                                        <>
                                                            {/* Restore Button for deleted accounts */}
                                                            <HasRole role={['Super Admin', 'Admin', 'HR', 'IT']}>
                                                                <Button
                                                                    variant="outline"
                                                                    size="icon"
                                                                    onClick={() => handleRestore(user.id)}
                                                                    disabled={loading}
                                                                    className="text-green-600 hover:text-green-700 border-green-300"
                                                                    title="Restore Account"
                                                                >
                                                                    <RotateCcw className="h-4 w-4" />
                                                                </Button>
                                                            </HasRole>

                                                            {/* Permanently Delete Button */}
                                                            <HasRole role={['Super Admin', 'Admin', 'IT']}>
                                                                <DeleteConfirmDialog
                                                                    onConfirm={() => handleForceDelete(user.id)}
                                                                    title="Permanently Delete Account"
                                                                    description={`Are you sure you want to PERMANENTLY delete the account for "${user.first_name} ${user.middle_name ? user.middle_name + '. ' : ''}${user.last_name}"? This action cannot be undone and all data will be lost forever.`}
                                                                    disabled={loading || user.id === currentUserId}
                                                                    trigger={
                                                                        <Button
                                                                            variant="destructive"
                                                                            size="icon"
                                                                            disabled={loading || user.id === currentUserId}
                                                                            title="Permanently Delete"
                                                                        >
                                                                            <XCircle className="h-4 w-4" />
                                                                        </Button>
                                                                    }
                                                                />
                                                            </HasRole>
                                                        </>
                                                    ) : (
                                                        <>
                                                            <Can permission="accounts.edit">
                                                                <Link href={accountsEdit(user.id).url}>
                                                                    <Button variant="outline" size="icon" disabled={loading} title="Edit Account">
                                                                        <Pencil className="h-4 w-4" />
                                                                    </Button>
                                                                </Link>
                                                            </Can>

                                                            <Can permission="accounts.edit">
                                                                {user.is_approved ? (
                                                                    <Button
                                                                        variant="outline"
                                                                        size="icon"
                                                                        onClick={() => handleUnapprove(user)}
                                                                        disabled={loading || user.id === currentUserId}
                                                                        className="text-yellow-600 hover:text-yellow-700 border-yellow-300"
                                                                        title="Revoke Access"
                                                                    >
                                                                        <UserMinus className="h-4 w-4" />
                                                                    </Button>
                                                                ) : (
                                                                    <Button
                                                                        variant="outline"
                                                                        size="icon"
                                                                        onClick={() => handleApprove(user.id)}
                                                                        disabled={loading}
                                                                        className="text-green-600 hover:text-green-700 border-green-300"
                                                                        title="Approve Account"
                                                                    >
                                                                        <UserCheck2 className="h-4 w-4" />
                                                                    </Button>
                                                                )}
                                                            </Can>

                                                            <Can permission="accounts.delete">
                                                                <DeleteConfirmDialog
                                                                    onConfirm={() => handleDelete(user.id)}
                                                                    title="Delete User Account"
                                                                    description={`Are you sure you want to delete the account for "${user.first_name} ${user.middle_name ? user.middle_name + '. ' : ''}${user.last_name}"? The account will be marked for deletion and require admin confirmation.`}
                                                                    disabled={loading || user.id === currentUserId}
                                                                    trigger={
                                                                        <Button
                                                                            variant="outline"
                                                                            size="icon"
                                                                            disabled={loading || user.id === currentUserId}
                                                                            className="text-red-600 hover:text-red-700 border-red-300"
                                                                            title="Delete Account"
                                                                        >
                                                                            <Trash2 className="h-4 w-4" />
                                                                        </Button>
                                                                    }
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
                            <div key={user.id} className={`rounded-lg shadow p-4 space-y-3 ${isPendingDeletion(user) ? 'bg-orange-50 dark:bg-orange-950/50 border border-orange-200 dark:border-orange-800' : isDeleted(user) ? 'bg-red-50 dark:bg-red-950/50 border border-red-200 dark:border-red-800' : 'bg-card border'}`}>
                                <div className="flex justify-between items-start">
                                    <div className="flex items-start gap-3 flex-1">
                                        <Can permission="accounts.edit">
                                            {!user.deleted_at && user.id !== currentUserId && (
                                                user.is_approved ? (
                                                    <Checkbox
                                                        checked={selectedRevokeIds.includes(user.id)}
                                                        onCheckedChange={() => toggleSelectRevoke(user.id)}
                                                        aria-label={`Select ${user.first_name} ${user.last_name} for revoke`}
                                                        className="mt-1 border-yellow-500 data-[state=checked]:bg-yellow-600"
                                                    />
                                                ) : (
                                                    <Checkbox
                                                        checked={selectedApproveIds.includes(user.id)}
                                                        onCheckedChange={() => toggleSelectApprove(user.id)}
                                                        aria-label={`Select ${user.first_name} ${user.last_name} for approve`}
                                                        className="mt-1 border-green-500 data-[state=checked]:bg-green-600"
                                                    />
                                                )
                                            )}
                                        </Can>
                                        <div>
                                            <div className="flex items-center gap-3">
                                                <Avatar className="h-10 w-10 overflow-hidden rounded-full">
                                                    <AvatarImage src={user.avatar_url} alt={`${user.first_name} ${user.last_name}`} />
                                                    <AvatarFallback className="rounded-full bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white text-sm">
                                                        {getInitials(`${user.first_name} ${user.last_name}`)}
                                                    </AvatarFallback>
                                                </Avatar>
                                                <div>
                                                    <h3 className="font-semibold text-lg">
                                                        {user.first_name} {user.middle_name ? `${user.middle_name}. ` : ''}{user.last_name}
                                                    </h3>
                                                    <p className="text-sm text-gray-600">{user.email}</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div className="flex flex-col gap-2 items-end">
                                        <span className={`px-2 py-1 rounded-full text-xs font-medium border ${getRoleBadgeColor(user.role)}`}>
                                            {user.role}
                                        </span>
                                        <div className="flex items-center gap-2">
                                            <Can permission="accounts.edit">
                                                {!user.deleted_at && user.id !== currentUserId && (
                                                    <Switch
                                                        checked={user.is_active}
                                                        onCheckedChange={() => handleToggleActive(user)}
                                                        aria-label="Toggle employee active status"
                                                    />
                                                )}
                                            </Can>
                                            <div className={`flex items-center justify-center px-2 py-1 rounded-full border ${user.is_active
                                                ? 'bg-green-100 text-green-800 border-green-300 dark:bg-green-900/30 dark:text-green-400 dark:border-green-700'
                                                : 'bg-gray-100 text-gray-800 border-gray-300 dark:bg-gray-900/30 dark:text-gray-400 dark:border-gray-700'
                                                }`}>
                                                {user.is_active ? <UserCheck className="h-4 w-4" /> : <UserX className="h-4 w-4" />}
                                            </div>
                                        </div>
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
                                                    <CheckCircle className="mr-2 h-4 w-4" />
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
                                                    <RotateCcw className="mr-2 h-4 w-4" />
                                                    Restore Account
                                                </Button>
                                            </Can>
                                        </>
                                    ) : isDeleted(user) ? (
                                        <>
                                            {/* Restore Button for deleted accounts */}
                                            <HasRole role={['Super Admin', 'Admin', 'HR', 'IT']}>
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
                                            </HasRole>

                                            {/* Permanently Delete Button */}
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
                                                            <XCircle className="mr-2 h-4 w-4" />
                                                            Permanently Delete
                                                        </Button>
                                                    }
                                                />
                                            </HasRole>
                                        </>
                                    ) : (
                                        <>
                                            <div className="flex gap-2">
                                                <Can permission="accounts.edit">
                                                    <Link href={accountsEdit(user.id).url} className="flex-1">
                                                        <Button variant="outline" size="sm" className="w-full" disabled={loading}>
                                                            <Pencil className="mr-2 h-4 w-4" />
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
                                                        onClick={() => handleUnapprove(user)}
                                                        disabled={loading || user.id === currentUserId}
                                                        className="w-full text-yellow-600 hover:text-yellow-700 border-yellow-300"
                                                    >
                                                        <UserMinus className="mr-2 h-4 w-4" />
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
                                                        <UserCheck2 className="mr-2 h-4 w-4" />
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
                        <PaginationNav
                            links={users.links}
                            onPageChange={handlePageChange}
                        />
                    )}
                </div>

                {/* Toggle Active Confirmation Dialog */}
                <AlertDialog open={toggleActiveDialogOpen} onOpenChange={setToggleActiveDialogOpen}>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle className="flex items-center gap-2">
                                <UserX className="h-5 w-5 text-amber-500" />
                                Deactivate Employee?
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                {userToToggle && (
                                    <>
                                        Are you sure you want to deactivate <strong>{userToToggle.first_name} {userToToggle.last_name}</strong>?
                                        <br /><br />
                                        <span className="text-amber-600 dark:text-amber-400">
                                            ⚠️ This will also deactivate all schedules assigned to this employee.
                                        </span>
                                    </>
                                )}
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={() => userToToggle && confirmToggleActive(userToToggle.id)}
                                className="bg-amber-600 hover:bg-amber-700"
                            >
                                Deactivate
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>

                {/* Single User Revoke Confirmation Dialog with Email Option */}
                <AlertDialog open={revokeDialogOpen} onOpenChange={setRevokeDialogOpen}>
                    <AlertDialogContent className="max-w-md">
                        <AlertDialogHeader>
                            <AlertDialogTitle className="flex items-center gap-2">
                                <AlertTriangle className="h-5 w-5 text-yellow-500" />
                                Revoke Employee Access
                            </AlertDialogTitle>
                            <AlertDialogDescription asChild>
                                <div className="space-y-3">
                                    {userToRevoke && (
                                        <>
                                            <p>
                                                You are about to revoke access for <strong>{userToRevoke.first_name} {userToRevoke.middle_name ? userToRevoke.middle_name + '. ' : ''}{userToRevoke.last_name}</strong>.
                                            </p>
                                            <p className="text-sm">
                                                This employee has a hire date of <strong>{userToRevoke.hired_date ? new Date(userToRevoke.hired_date).toLocaleDateString() : 'N/A'}</strong>, indicating they may be resigning from the company.
                                            </p>
                                            <div className="bg-amber-50 dark:bg-amber-950/50 border border-amber-200 dark:border-amber-800 rounded-lg p-3">
                                                <p className="text-sm text-amber-800 dark:text-amber-300 flex items-start gap-2">
                                                    <AlertTriangle className="h-4 w-4 mt-0.5 shrink-0" />
                                                    <span>
                                                        This action will also <strong>deactivate the employee status</strong> and <strong>all active schedules</strong> for this employee.
                                                    </span>
                                                </p>
                                            </div>
                                            <div className="bg-blue-50 dark:bg-blue-950/50 border border-blue-200 dark:border-blue-800 rounded-lg p-3">
                                                <p className="text-sm text-blue-800 dark:text-blue-300 flex items-start gap-2">
                                                    <Mail className="h-4 w-4 mt-0.5 shrink-0" />
                                                    <span>
                                                        Would you like to send an automated notification email to the management team (Super Admin, Admin, Team Leads, HR, IT) about this access revocation?
                                                    </span>
                                                </p>
                                            </div>
                                        </>
                                    )}
                                </div>
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter className="flex-col sm:flex-row gap-2">
                            <AlertDialogCancel className="mt-0">Cancel</AlertDialogCancel>
                            <Button
                                variant="outline"
                                onClick={() => userToRevoke && confirmUnapprove(userToRevoke.id, false)}
                                disabled={loading}
                                className="border-yellow-300 text-yellow-700 hover:bg-yellow-50 dark:border-yellow-700 dark:text-yellow-400 dark:hover:bg-yellow-950"
                            >
                                Revoke Only
                            </Button>
                            <Button
                                onClick={() => userToRevoke && confirmUnapprove(userToRevoke.id, true)}
                                disabled={loading}
                                className="bg-yellow-600 hover:bg-yellow-700 text-white"
                            >
                                <Mail className="mr-2 h-4 w-4" />
                                Revoke & Send Email
                            </Button>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>

                {/* Bulk Revoke Confirmation Dialog with Email Option */}
                <AlertDialog open={bulkRevokeDialogOpen} onOpenChange={setBulkRevokeDialogOpen}>
                    <AlertDialogContent className="max-w-md">
                        <AlertDialogHeader>
                            <AlertDialogTitle className="flex items-center gap-2">
                                <AlertTriangle className="h-5 w-5 text-yellow-500" />
                                Revoke Multiple Employee Access
                            </AlertDialogTitle>
                            <AlertDialogDescription asChild>
                                <div className="space-y-3">
                                    <p>
                                        You are about to revoke access for <strong>{selectedRevokeIds.length} employee(s)</strong>.
                                    </p>
                                    <div className="bg-amber-50 dark:bg-amber-950/50 border border-amber-200 dark:border-amber-800 rounded-lg p-3">
                                        <p className="text-sm text-amber-800 dark:text-amber-300 flex items-start gap-2">
                                            <AlertTriangle className="h-4 w-4 mt-0.5 shrink-0" />
                                            <span>
                                                This action will also <strong>deactivate employee status</strong> and <strong>all active schedules</strong> for these employees.
                                            </span>
                                        </p>
                                    </div>
                                    {(() => {
                                        const employeesWithHiredDates = users.data.filter(
                                            user => selectedRevokeIds.includes(user.id) && user.hired_date
                                        );
                                        return employeesWithHiredDates.length > 0 ? (
                                            <>
                                                <p className="text-sm">
                                                    <strong>{employeesWithHiredDates.length}</strong> of these employees have hire dates, indicating they may be resigning.
                                                </p>
                                                <div className="bg-blue-50 dark:bg-blue-950/50 border border-blue-200 dark:border-blue-800 rounded-lg p-3">
                                                    <p className="text-sm text-blue-800 dark:text-blue-300 flex items-start gap-2">
                                                        <Mail className="h-4 w-4 mt-0.5 shrink-0" />
                                                        <span>
                                                            Would you like to send automated notification emails to the management team for employees with hire dates?
                                                        </span>
                                                    </p>
                                                </div>
                                            </>
                                        ) : null;
                                    })()}
                                </div>
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter className="flex-col sm:flex-row gap-2">
                            <AlertDialogCancel className="mt-0">Cancel</AlertDialogCancel>
                            <Button
                                variant="outline"
                                onClick={() => confirmBulkRevoke(false)}
                                disabled={loading}
                                className="border-yellow-300 text-yellow-700 hover:bg-yellow-50 dark:border-yellow-700 dark:text-yellow-400 dark:hover:bg-yellow-950"
                            >
                                Revoke Only
                            </Button>
                            <Button
                                onClick={() => confirmBulkRevoke(true)}
                                disabled={loading}
                                className="bg-yellow-600 hover:bg-yellow-700 text-white"
                            >
                                <Mail className="mr-2 h-4 w-4" />
                                Revoke & Send Emails
                            </Button>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </div>
        </AppLayout>
    );
}
