import { LoadingOverlay } from '@/components/LoadingOverlay';
import { PageHeader } from '@/components/PageHeader';
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
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { DatePicker } from '@/components/ui/date-picker';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import {
    useFlashMessage,
    usePageLoading,
    usePageMeta,
    useReturnTo,
} from '@/hooks';
import AppLayout from '@/layouts/app-layout';
import {
    edit as accountsEdit,
    index as accountsIndex,
    update as accountsUpdate,
    toggleActive,
} from '@/routes/accounts';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Eye, EyeOff } from 'lucide-react';
import React, { useState } from 'react';
import { toast } from 'sonner';

interface User {
    id: number;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    email: string;
    role: string;
    hired_date: string;
    is_active: boolean;
    is_solo_parent: boolean;
}

export default function AccountEdit() {
    const { user, roles, auth } = usePage<{
        user: User;
        roles: string[];
        auth: { user: { id: number } };
    }>().props;

    const fullName = `${user.first_name} ${user.last_name}`;
    const currentUserId = auth.user.id;

    const [employeeStatus, setEmployeeStatus] = useState(user.is_active);
    const [toggleActiveDialogOpen, setToggleActiveDialogOpen] = useState(false);
    const [showPassword, setShowPassword] = useState(false);
    const [showPasswordConfirmation, setShowPasswordConfirmation] =
        useState(false);

    const { title, breadcrumbs } = usePageMeta({
        title: `Edit ${fullName}`,
        breadcrumbs: [
            { title: 'Accounts', href: accountsIndex().url },
            { title: `Edit ${fullName}`, href: accountsEdit(user.id).url },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();
    const { returnTo, backUrl } = useReturnTo(accountsIndex().url);

    const { data, setData, patch, processing, errors } = useForm({
        first_name: user.first_name,
        middle_name: user.middle_name || '',
        last_name: user.last_name,
        email: user.email,
        password: '',
        password_confirmation: '',
        role: user.role,
        hired_date: user.hired_date,
        is_solo_parent: user.is_solo_parent,
        _return_to: returnTo,
    });

    const handleToggleActive = () => {
        if (employeeStatus) {
            // If currently active, show confirmation dialog
            setToggleActiveDialogOpen(true);
        } else {
            // If currently inactive, activate immediately
            confirmToggleActive();
        }
    };

    const confirmToggleActive = () => {
        router.post(
            toggleActive(user.id).url,
            {},
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => {
                    setEmployeeStatus(!employeeStatus);
                    setToggleActiveDialogOpen(false);
                    toast.success(
                        `Employee ${employeeStatus ? 'deactivated' : 'activated'} successfully`,
                    );
                },
                onError: () => {
                    toast.error('Failed to update employee status');
                },
            },
        );
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        patch(accountsUpdate(user.id).url, {
            onSuccess: () => {
                toast.success('User account updated successfully');
                router.get(backUrl);
            },
            onError: (errors) => {
                const firstError = Object.values(errors)[0] as string;
                toast.error(firstError || 'Validation error');
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <LoadingOverlay
                isLoading={isPageLoading || processing}
                message={processing ? 'Updating account...' : undefined}
            />
            <div className="mx-auto max-w-4xl space-y-6 p-6">
                <PageHeader
                    title="Edit User Account"
                    description={`Update information for ${fullName}`}
                />
                <Card>
                    <CardHeader>
                        <CardTitle>Edit User Account</CardTitle>
                        <CardDescription>
                            Update user information and settings
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="space-y-6">
                            {/* Personal Information Section */}
                            <div className="space-y-4">
                                <h3 className="text-lg font-semibold">
                                    Personal Information
                                </h3>
                                <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                                    <div>
                                        <Label htmlFor="first_name">
                                            First Name
                                        </Label>
                                        <Input
                                            id="first_name"
                                            type="text"
                                            value={data.first_name}
                                            onChange={(e) =>
                                                setData(
                                                    'first_name',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="John"
                                            required
                                        />
                                        {errors.first_name && (
                                            <p className="mt-1 text-sm text-red-600">
                                                {errors.first_name}
                                            </p>
                                        )}
                                    </div>

                                    <div>
                                        <Label htmlFor="middle_name">
                                            Middle Initial (Optional)
                                        </Label>
                                        <Input
                                            id="middle_name"
                                            type="text"
                                            value={data.middle_name}
                                            onChange={(e) =>
                                                setData(
                                                    'middle_name',
                                                    e.target.value
                                                        .toUpperCase()
                                                        .charAt(0),
                                                )
                                            }
                                            placeholder="M"
                                            maxLength={1}
                                        />
                                        {errors.middle_name && (
                                            <p className="mt-1 text-sm text-red-600">
                                                {errors.middle_name}
                                            </p>
                                        )}
                                    </div>

                                    <div>
                                        <Label htmlFor="last_name">
                                            Last Name
                                        </Label>
                                        <Input
                                            id="last_name"
                                            type="text"
                                            value={data.last_name}
                                            onChange={(e) =>
                                                setData(
                                                    'last_name',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="Doe"
                                            required
                                        />
                                        {errors.last_name && (
                                            <p className="mt-1 text-sm text-red-600">
                                                {errors.last_name}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            </div>

                            {/* Account Information Section */}
                            <div className="space-y-4">
                                <h3 className="text-lg font-semibold">
                                    Account Information
                                </h3>
                                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <div>
                                        <Label htmlFor="email">
                                            Email Address
                                        </Label>
                                        <Input
                                            id="email"
                                            type="email"
                                            value={data.email}
                                            onChange={(e) =>
                                                setData('email', e.target.value)
                                            }
                                            placeholder="john.doe@primehubmail.com"
                                            required
                                        />
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Only @primehubmail.com and
                                            @prmhubsolutions.com emails are
                                            accepted.
                                        </p>
                                        {errors.email && (
                                            <p className="mt-1 text-sm text-red-600">
                                                {errors.email}
                                            </p>
                                        )}
                                    </div>

                                    <div>
                                        <Label htmlFor="role">Role</Label>
                                        <Select
                                            value={data.role}
                                            onValueChange={(val) =>
                                                setData('role', val)
                                            }
                                        >
                                            <SelectTrigger className="w-full">
                                                <SelectValue placeholder="Select Role" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {roles.map((role) => (
                                                    <SelectItem
                                                        key={role}
                                                        value={role}
                                                    >
                                                        {role}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {errors.role && (
                                            <p className="mt-1 text-sm text-red-600">
                                                {errors.role}
                                            </p>
                                        )}
                                    </div>

                                    <div>
                                        <Label htmlFor="hired_date">
                                            Hired Date
                                        </Label>
                                        <DatePicker
                                            value={data.hired_date}
                                            onChange={(value) =>
                                                setData('hired_date', value)
                                            }
                                            placeholder="Select hired date"
                                        />
                                        {errors.hired_date && (
                                            <p className="mt-1 text-sm text-red-600">
                                                {errors.hired_date}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            </div>

                            {/* Employee Status Section */}
                            {user.id !== currentUserId && (
                                <div className="space-y-4">
                                    <h3 className="text-lg font-semibold">
                                        Employee Status
                                    </h3>
                                    <div className="flex items-center justify-between rounded-lg border p-4">
                                        <div className="space-y-1">
                                            <p className="font-medium">
                                                Active Status
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {employeeStatus
                                                    ? 'Employee is currently active and can access the system'
                                                    : 'Employee is inactive and all their schedules are deactivated'}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <span
                                                className={`rounded-full border px-2 py-1 text-xs font-medium ${
                                                    employeeStatus
                                                        ? 'border-green-300 bg-green-100 text-green-800 dark:border-green-700 dark:bg-green-900/30 dark:text-green-400'
                                                        : 'border-gray-300 bg-gray-100 text-gray-800 dark:border-gray-700 dark:bg-gray-900/30 dark:text-gray-400'
                                                }`}
                                            >
                                                {employeeStatus
                                                    ? 'Active'
                                                    : 'Inactive'}
                                            </span>
                                            <Switch
                                                checked={employeeStatus}
                                                onCheckedChange={
                                                    handleToggleActive
                                                }
                                                aria-label="Toggle employee active status"
                                            />
                                        </div>
                                    </div>
                                    <div className="flex items-center justify-between rounded-lg border p-4">
                                        <div className="space-y-1">
                                            <p className="font-medium">
                                                Solo Parent
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {data.is_solo_parent
                                                    ? 'Employee is eligible for Solo Parent Leave (SPL) credits'
                                                    : 'Enable to grant Solo Parent Leave (SPL) eligibility'}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <span
                                                className={`rounded-full border px-2 py-1 text-xs font-medium ${
                                                    data.is_solo_parent
                                                        ? 'border-blue-300 bg-blue-100 text-blue-800 dark:border-blue-700 dark:bg-blue-900/30 dark:text-blue-400'
                                                        : 'border-gray-300 bg-gray-100 text-gray-800 dark:border-gray-700 dark:bg-gray-900/30 dark:text-gray-400'
                                                }`}
                                            >
                                                {data.is_solo_parent
                                                    ? 'Solo Parent'
                                                    : 'Not Solo Parent'}
                                            </span>
                                            <Switch
                                                checked={data.is_solo_parent}
                                                onCheckedChange={(checked) =>
                                                    setData(
                                                        'is_solo_parent',
                                                        checked,
                                                    )
                                                }
                                                aria-label="Toggle solo parent status"
                                            />
                                        </div>
                                    </div>
                                </div>
                            )}

                            {/* Security Section */}
                            <div className="space-y-4">
                                <div>
                                    <h3 className="text-lg font-semibold">
                                        Change Password
                                    </h3>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Leave password fields blank if you don't
                                        want to change the password
                                    </p>
                                </div>
                                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <div>
                                        <Label htmlFor="password">
                                            New Password
                                        </Label>
                                        <div className="relative">
                                            <Input
                                                id="password"
                                                type={
                                                    showPassword
                                                        ? 'text'
                                                        : 'password'
                                                }
                                                value={data.password}
                                                onChange={(e) =>
                                                    setData(
                                                        'password',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="••••••••"
                                                className="pr-10"
                                            />
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setShowPassword(
                                                        !showPassword,
                                                    )
                                                }
                                                className="absolute top-1/2 right-3 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                                aria-label={
                                                    showPassword
                                                        ? 'Hide password'
                                                        : 'Show password'
                                                }
                                            >
                                                {showPassword ? (
                                                    <EyeOff className="h-4 w-4" />
                                                ) : (
                                                    <Eye className="h-4 w-4" />
                                                )}
                                            </button>
                                        </div>
                                        {errors.password && (
                                            <p className="mt-1 text-sm text-red-600">
                                                {errors.password}
                                            </p>
                                        )}
                                    </div>

                                    <div>
                                        <Label htmlFor="password_confirmation">
                                            Confirm New Password
                                        </Label>
                                        <div className="relative">
                                            <Input
                                                id="password_confirmation"
                                                type={
                                                    showPasswordConfirmation
                                                        ? 'text'
                                                        : 'password'
                                                }
                                                value={
                                                    data.password_confirmation
                                                }
                                                onChange={(e) =>
                                                    setData(
                                                        'password_confirmation',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="••••••••"
                                                className="pr-10"
                                            />
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setShowPasswordConfirmation(
                                                        !showPasswordConfirmation,
                                                    )
                                                }
                                                className="absolute top-1/2 right-3 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                                aria-label={
                                                    showPasswordConfirmation
                                                        ? 'Hide password confirmation'
                                                        : 'Show password confirmation'
                                                }
                                            >
                                                {showPasswordConfirmation ? (
                                                    <EyeOff className="h-4 w-4" />
                                                ) : (
                                                    <Eye className="h-4 w-4" />
                                                )}
                                            </button>
                                        </div>
                                        {errors.password_confirmation && (
                                            <p className="mt-1 text-sm text-red-600">
                                                {errors.password_confirmation}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            </div>

                            <div className="flex gap-2 border-t pt-4">
                                <Button type="submit" disabled={processing}>
                                    {processing
                                        ? 'Updating...'
                                        : 'Update Account'}
                                </Button>
                                <Button
                                    variant="outline"
                                    type="button"
                                    onClick={() => router.get(backUrl)}
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>

            {/* Deactivate Employee Confirmation Dialog */}
            <AlertDialog
                open={toggleActiveDialogOpen}
                onOpenChange={setToggleActiveDialogOpen}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle className="flex items-center gap-2">
                            <AlertTriangle className="h-5 w-5 text-yellow-500" />
                            Deactivate Employee?
                        </AlertDialogTitle>
                        <AlertDialogDescription className="space-y-2">
                            <p>
                                Are you sure you want to deactivate{' '}
                                <strong>{fullName}</strong>?
                            </p>
                            <p className="font-medium text-yellow-600 dark:text-yellow-500">
                                ⚠️ This will also deactivate all their active
                                employee schedules.
                            </p>
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={confirmToggleActive}
                            className="bg-yellow-600 hover:bg-yellow-700"
                        >
                            Deactivate
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
