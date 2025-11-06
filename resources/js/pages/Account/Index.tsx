import React, { useEffect, useState, useRef } from "react";
import { router, usePage, Link, Head } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from "@/components/ui/select";
import AppLayout from "@/layouts/app-layout";
import { Input } from "@/components/ui/input";
import PaginationNav, { PaginationLink } from "@/components/pagination-nav";
import { index as accountsIndex, create as accountsCreate } from "@/routes/accounts";
import { toast } from "sonner";

// New reusable hooks and components
import { usePageMeta, useFlashMessage, usePageLoading } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { DeleteConfirmDialog } from "@/components/DeleteConfirmDialog";
import { LoadingOverlay } from "@/components/LoadingOverlay";

interface User {
    id: number;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    email: string;
    role: string;
    created_at: string;
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
}

export default function AccountIndex() {
    const { users, filters } = usePage<{ users: UsersPayload; filters: Filters }>().props;
    const [loading, setLoading] = useState(false);
    const [search, setSearch] = useState(filters.search || "");
    const [debouncedSearch, setDebouncedSearch] = useState(filters.search || "");
    const [roleFilter, setRoleFilter] = useState(filters.role || "all");
    const auth = usePage().props.auth as { user?: { id: number } };
    const currentUserId = auth?.user?.id;

    // Track initial mount to prevent effect on first render
    const isInitialMount = useRef(true);

    // Use new hooks
    const { title, breadcrumbs } = usePageMeta({
        title: "Account Management",
        breadcrumbs: [{ title: "Accounts", href: accountsIndex().url }]
    });
    useFlashMessage(); // Automatically handles flash messages
    const isPageLoading = usePageLoading();

    useEffect(() => {
        const timer = setTimeout(() => setDebouncedSearch(search), 500);
        return () => clearTimeout(timer);
    }, [search]);

    useEffect(() => {
        // Skip the effect on initial mount to avoid duplicate requests
        if (isInitialMount.current) {
            isInitialMount.current = false;
            return;
        }

        const params: Record<string, string | number> = {};
        if (debouncedSearch) params.search = debouncedSearch;
        if (roleFilter && roleFilter !== "all") params.role = roleFilter;
        // Reset to page 1 when filters change
        params.page = 1;

        setLoading(true);
        router.get("/accounts", params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => setLoading(false),
        });
    }, [debouncedSearch, roleFilter]);

    const handleDelete = (userId: number) => {
        setLoading(true);
        router.delete(`/accounts/${userId}`, {
            preserveScroll: true,
            onFinish: () => setLoading(false),
            onSuccess: () => toast.success("User account deleted successfully"),
            onError: () => toast.error("Failed to delete user account"),
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

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-3 relative">
                {/* Loading overlay for page transitions */}
                <LoadingOverlay isLoading={isPageLoading || loading} message="Loading accounts..." />

                {/* Page header with create button */}
                <PageHeader
                    title="Account Management"
                    description="Manage user accounts and permissions"
                    createLink={accountsCreate().url}
                    createLabel="Create Account"
                />

                {/* Filters */}
                <div className="flex flex-col gap-3">
                    <div className="grid grid-cols-2 sm:flex sm:flex-row gap-3">
                        <Input
                            type="search"
                            placeholder="Search by name or email..."
                            value={search}
                            onChange={e => setSearch(e.target.value)}
                            className="col-span-2 sm:w-64"
                        />
                        <Select value={roleFilter} onValueChange={setRoleFilter}>
                            <SelectTrigger className="sm:w-48">
                                <SelectValue placeholder="Filter by Role" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Roles</SelectItem>
                                <SelectItem value="Super Admin">Super Admin</SelectItem>
                                <SelectItem value="Admin">Admin</SelectItem>
                                <SelectItem value="Agent">Agent</SelectItem>
                                <SelectItem value="HR">HR</SelectItem>
                            </SelectContent>
                        </Select>

                        {(roleFilter !== "all" || search) && (
                            <Button
                                variant="outline"
                                onClick={() => {
                                    setSearch("");
                                    setRoleFilter("all");
                                }}
                            >
                                Clear Filters
                            </Button>
                        )}
                    </div>
                </div>

                {/* Table section */}
                <div className="text-sm text-muted-foreground mb-2">
                    Showing {users.data.length} of {users.meta.total} user account{users.meta.total !== 1 ? 's' : ''}
                    {(roleFilter !== "all" || search) && ' (filtered)'}
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
                                    <TableHead>Created At</TableHead>
                                    <TableHead>Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {users.data.map((user) => (
                                    <TableRow key={user.id}>
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
                                        <TableCell>{new Date(user.created_at).toLocaleDateString()}</TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <Link href={`/accounts/${user.id}/edit`}>
                                                    <Button variant="outline" size="sm" disabled={loading}>
                                                        Edit
                                                    </Button>
                                                </Link>

                                                <DeleteConfirmDialog
                                                    onConfirm={() => handleDelete(user.id)}
                                                    title="Delete User Account"
                                                    description={`Are you sure you want to delete the account for "${user.first_name} ${user.middle_name ? user.middle_name + '. ' : ''}${user.last_name}"? This action cannot be undone.`}
                                                    disabled={loading || user.id === currentUserId}
                                                />
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {users.data.length === 0 && !loading && (
                                    <TableRow>
                                        <TableCell colSpan={8} className="py-8 text-center text-gray-500">
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
                    {users.data.map((user) => (
                        <div key={user.id} className="rounded-lg shadow p-4 space-y-3">
                            <div className="flex justify-between items-start">
                                <div className="flex-1">
                                    <h3 className="font-semibold text-lg">
                                        {user.first_name} {user.middle_name ? `${user.middle_name}. ` : ''}{user.last_name}
                                    </h3>
                                    <p className="text-sm text-gray-600">{user.email}</p>
                                </div>
                                <span className={`px-2 py-1 rounded-full text-xs font-medium border ${getRoleBadgeColor(user.role)}`}>
                                    {user.role}
                                </span>
                            </div>
                            <div className="text-xs text-gray-500">
                                Created: {new Date(user.created_at).toLocaleDateString()}
                            </div>
                            <div className="flex gap-2 pt-2">
                                <Link href={`/accounts/${user.id}/edit`} className="flex-1">
                                    <Button variant="outline" size="sm" className="w-full" disabled={loading}>
                                        Edit
                                    </Button>
                                </Link>

                                <div className="flex-1">
                                    <DeleteConfirmDialog
                                        onConfirm={() => handleDelete(user.id)}
                                        title="Delete User Account"
                                        description={`Are you sure you want to delete the account for "${user.first_name} ${user.middle_name ? user.middle_name + '. ' : ''}${user.last_name}"? This action cannot be undone.`}
                                        disabled={loading || user.id === currentUserId}
                                        triggerClassName="w-full"
                                    />
                                </div>
                            </div>
                        </div>
                    ))}
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
