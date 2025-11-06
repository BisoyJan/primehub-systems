import { send } from '@/routes/verification';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Transition } from '@headlessui/react';
import { Form, Head, Link, usePage } from '@inertiajs/react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit, update, destroy } from '@/routes/account';
import { useRef } from 'react';
import { CheckCircle2, XCircle, AlertTriangle } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Account Management',
        href: edit().url,
    },
];

export default function AccountManagement({
    status,
}: {
    status?: string;
}) {
    const { auth } = usePage<SharedData>().props;
    const passwordInput = useRef<HTMLInputElement>(null);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Account Management" />

            <SettingsLayout>
                <div className="space-y-8">
                    {/* Account Information Section */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Account Information</CardTitle>
                            <CardDescription>
                                Update your account details and email address
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Form
                                method="patch"
                                action={update().url}
                                options={{
                                    preserveScroll: true,
                                }}
                                className="space-y-6"
                            >
                                {({ processing, recentlySuccessful, errors }) => (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="first_name">First Name</Label>
                                            <Input
                                                id="first_name"
                                                className="mt-1 block w-full"
                                                defaultValue={auth.user.first_name}
                                                name="first_name"
                                                required
                                                autoComplete="given-name"
                                                placeholder="Enter your first name"
                                            />
                                            <InputError
                                                className="mt-2"
                                                message={errors.first_name}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="middle_name">Middle Initial (Optional)</Label>
                                            <Input
                                                id="middle_name"
                                                className="mt-1 block w-full"
                                                defaultValue={auth.user.middle_name || ''}
                                                name="middle_name"
                                                maxLength={1}
                                                autoComplete="additional-name"
                                                placeholder="M"
                                            />
                                            <InputError
                                                className="mt-2"
                                                message={errors.middle_name}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="last_name">Last Name</Label>
                                            <Input
                                                id="last_name"
                                                className="mt-1 block w-full"
                                                defaultValue={auth.user.last_name}
                                                name="last_name"
                                                required
                                                autoComplete="family-name"
                                                placeholder="Enter your last name"
                                            />
                                            <InputError
                                                className="mt-2"
                                                message={errors.last_name}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="email">
                                                Email Address
                                            </Label>
                                            <Input
                                                id="email"
                                                type="email"
                                                className="mt-1 block w-full"
                                                defaultValue={auth.user.email}
                                                name="email"
                                                required
                                                autoComplete="username"
                                                placeholder="Enter your email address"
                                            />
                                            <InputError
                                                className="mt-2"
                                                message={errors.email}
                                            />
                                        </div>

                                        <div className="flex items-center gap-4">
                                            <Button
                                                disabled={processing}
                                                data-test="update-profile-button"
                                            >
                                                {processing ? 'Saving...' : 'Save Changes'}
                                            </Button>

                                            <Transition
                                                show={recentlySuccessful}
                                                enter="transition ease-in-out duration-300"
                                                enterFrom="opacity-0"
                                                leave="transition ease-in-out duration-300"
                                                leaveTo="opacity-0"
                                            >
                                                <div className="flex items-center gap-2 text-sm text-green-600">
                                                    <CheckCircle2 className="h-4 w-4" />
                                                    <span>Changes saved successfully</span>
                                                </div>
                                            </Transition>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>

                    {/* Email Verification Section */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Email Verification</CardTitle>
                            <CardDescription>
                                Manage your email verification status
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-4">
                                <div className="flex items-center justify-between rounded-lg border p-4">
                                    <div className="flex items-center gap-3">
                                        {auth.user.email_verified_at ? (
                                            <>
                                                <CheckCircle2 className="h-5 w-5 text-green-600" />
                                                <div>
                                                    <p className="font-medium">
                                                        Email Verified
                                                    </p>
                                                    <p className="text-sm text-muted-foreground">
                                                        Your email address is verified
                                                    </p>
                                                </div>
                                            </>
                                        ) : (
                                            <>
                                                <XCircle className="h-5 w-5 text-amber-600" />
                                                <div>
                                                    <p className="font-medium">
                                                        Email Not Verified
                                                    </p>
                                                    <p className="text-sm text-muted-foreground">
                                                        Please verify your email address
                                                    </p>
                                                </div>
                                            </>
                                        )}
                                    </div>
                                    <Badge
                                        variant={
                                            auth.user.email_verified_at
                                                ? 'default'
                                                : 'secondary'
                                        }
                                    >
                                        {auth.user.email_verified_at
                                            ? 'Verified'
                                            : 'Unverified'}
                                    </Badge>
                                </div>

                                {auth.user.email_verified_at === null && (
                                    <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-200/10 dark:bg-amber-700/10">
                                        <div className="flex gap-3">
                                            <AlertTriangle className="h-5 w-5 flex-shrink-0 text-amber-600 dark:text-amber-100" />
                                            <div className="space-y-2">
                                                <p className="text-sm text-amber-900 dark:text-amber-100">
                                                    Your email address is not verified.{' '}
                                                    <Link
                                                        href={send()}
                                                        method="post"
                                                        as="button"
                                                        className="font-medium underline decoration-amber-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current dark:decoration-amber-500"
                                                    >
                                                        Click here to resend the
                                                        verification email.
                                                    </Link>
                                                </p>

                                                {status === 'verification-link-sent' && (
                                                    <div className="flex items-center gap-2 text-sm font-medium text-green-600">
                                                        <CheckCircle2 className="h-4 w-4" />
                                                        <span>
                                                            A new verification link has been
                                                            sent to your email address.
                                                        </span>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Account Deletion Section */}
                    <Card className="border-red-200 dark:border-red-200/20">
                        <CardHeader>
                            <CardTitle className="text-red-600 dark:text-red-400">
                                Delete Account
                            </CardTitle>
                            <CardDescription>
                                Permanently delete your account and all associated data
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-4">
                                <div className="rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                                    <div className="flex gap-3">
                                        <AlertTriangle className="h-5 w-5 flex-shrink-0 text-red-600 dark:text-red-100" />
                                        <div className="space-y-1">
                                            <p className="font-medium text-red-600 dark:text-red-100">
                                                Warning: This action cannot be undone
                                            </p>
                                            <p className="text-sm text-red-700 dark:text-red-200">
                                                Once your account is deleted, all of its
                                                resources and data will be permanently
                                                removed. Please proceed with caution.
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <Dialog>
                                    <DialogTrigger asChild>
                                        <Button
                                            variant="destructive"
                                            data-test="delete-user-button"
                                        >
                                            Delete Account
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogTitle>
                                            Are you sure you want to delete your
                                            account?
                                        </DialogTitle>
                                        <DialogDescription>
                                            Once your account is deleted, all of its
                                            resources and data will be permanently
                                            deleted. Please enter your password to
                                            confirm you would like to permanently delete
                                            your account.
                                        </DialogDescription>

                                        <Form
                                            method="delete"
                                            action={destroy().url}
                                            options={{
                                                preserveScroll: true,
                                            }}
                                            onError={() =>
                                                passwordInput.current?.focus()
                                            }
                                            resetOnSuccess
                                            className="space-y-6"
                                        >
                                            {({
                                                resetAndClearErrors,
                                                processing,
                                                errors,
                                            }) => (
                                                <>
                                                    <div className="grid gap-2">
                                                        <Label
                                                            htmlFor="password"
                                                            className="sr-only"
                                                        >
                                                            Password
                                                        </Label>

                                                        <Input
                                                            id="password"
                                                            type="password"
                                                            name="password"
                                                            ref={passwordInput}
                                                            placeholder="Enter your password to confirm"
                                                            autoComplete="current-password"
                                                        />

                                                        <InputError
                                                            message={errors.password}
                                                        />
                                                    </div>

                                                    <DialogFooter className="gap-2">
                                                        <DialogClose asChild>
                                                            <Button
                                                                variant="secondary"
                                                                onClick={() =>
                                                                    resetAndClearErrors()
                                                                }
                                                            >
                                                                Cancel
                                                            </Button>
                                                        </DialogClose>

                                                        <Button
                                                            variant="destructive"
                                                            disabled={processing}
                                                            asChild
                                                        >
                                                            <button
                                                                type="submit"
                                                                data-test="confirm-delete-user-button"
                                                            >
                                                                {processing
                                                                    ? 'Deleting...'
                                                                    : 'Delete Account'}
                                                            </button>
                                                        </Button>
                                                    </DialogFooter>
                                                </>
                                            )}
                                        </Form>
                                    </DialogContent>
                                </Dialog>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
