import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { login } from '@/routes';
import { Head, router } from '@inertiajs/react';
import { LogOut, Clock, CheckCircle } from 'lucide-react';
import { useEffect, useRef } from 'react';

import { SharedData } from '@/types';

export default function PendingApproval({ userStatus }: SharedData & { userStatus: { is_approved: boolean; hired_date: string | null; deleted_at: string | null; deletion_confirmed_at: string | null } }) {
    const handleLogout = () => {
        router.post('/logout');
    };

    // Auto-refresh every 15 seconds to check if user has been approved
    const isPollingRef = useRef(false);
    useEffect(() => {
        const interval = setInterval(() => {
            if (isPollingRef.current) return;
            isPollingRef.current = true;
            router.reload({
                only: [], // Don't reload any props, just trigger the route check
                onSuccess: () => {
                    // The backend route will automatically redirect if approved
                },
                onFinish: () => {
                    isPollingRef.current = false;
                },
            });
        }, 15000); // Check every 15 seconds

        return () => clearInterval(interval);
    }, []);

    // Determine if resigned/disapproved
    const isResignedDisapproved = !!userStatus.hired_date && !userStatus.is_approved;

    return (
        <AuthLayout
            title={isResignedDisapproved ? 'Account Access Disabled' : 'Account Pending Approval'}
            description={isResignedDisapproved ? 'Your account has been disabled.' : 'Your account is waiting for administrator approval'}
        >
            <Head title={isResignedDisapproved ? 'Account Disabled' : 'Pending Approval'} />

            <div className="flex flex-col items-center gap-6 text-center">
                <div className={`rounded-full p-4 ${isResignedDisapproved ? 'bg-red-500/10' : 'bg-yellow-500/10'}`}>
                    <Clock className={`h-12 w-12 ${isResignedDisapproved ? 'text-red-500' : 'text-yellow-500'}`} />
                </div>

                <div className="space-y-3">
                    <h2 className="text-2xl font-semibold text-white">
                        {isResignedDisapproved ? 'Account Access Disabled' : 'Account Created Successfully!'}
                    </h2>
                    <p className="text-gray-300">
                        {isResignedDisapproved ? (
                            <>Your account has been <span className="font-semibold text-red-400">disabled</span> because you are no longer employed by the company and your access was disapproved by an administrator.<br />If you believe this is a mistake, please contact HR or your system administrator.</>
                        ) : (
                            <>Thank you for registering. Your account has been created but requires administrator approval before you can access the system.</>
                        )}
                    </p>
                </div>

                <div className="w-full space-y-4 rounded-lg border border-white/10 bg-white/5 p-6">
                    <div className="flex items-start gap-3 text-left">
                        <CheckCircle className="mt-0.5 h-5 w-5 shrink-0 text-green-500" />
                        <div className="space-y-1">
                            <p className="font-medium text-white">What's Next?</p>
                            <p className="text-sm text-gray-300">
                                An administrator will review your account and
                                approve it shortly. You'll receive an email
                                notification once your account is approved.
                            </p>
                        </div>
                    </div>

                    <div className="flex items-start gap-3 text-left">
                        <CheckCircle className="mt-0.5 h-5 w-5 shrink-0 text-green-500" />
                        <div className="space-y-1">
                            <p className="font-medium text-white">Need Help?</p>
                            <p className="text-sm text-gray-300">
                                If you believe this is taking too long or need
                                immediate access, please contact your system
                                administrator.
                            </p>
                        </div>
                    </div>
                </div>

                <div className="flex w-full flex-col gap-3">
                    <Button
                        onClick={handleLogout}
                        variant="outline"
                        className="w-full"
                    >
                        <LogOut className="mr-2 h-4 w-4" />
                        Log Out
                    </Button>
                </div>

                <p className="text-xs text-gray-400">
                    You can close this window and come back later, or{' '}
                    <span
                        onClick={() => router.get(login())}
                        className="cursor-pointer font-medium text-primary hover:underline"
                    >
                        try logging in again
                    </span>{' '}
                    to check if your account has been approved.
                </p>
            </div>
        </AuthLayout>
    );
}
