import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { login } from '@/routes';
import { Head, router } from '@inertiajs/react';
import { LogOut, Clock, CheckCircle } from 'lucide-react';

export default function PendingApproval() {
    const handleLogout = () => {
        router.post('/logout');
    };

    return (
        <AuthLayout
            title="Account Pending Approval"
            description="Your account is waiting for administrator approval"
        >
            <Head title="Pending Approval" />

            <div className="flex flex-col items-center gap-6 text-center">
                <div className="rounded-full bg-yellow-500/10 p-4">
                    <Clock className="h-12 w-12 text-yellow-500" />
                </div>

                <div className="space-y-3">
                    <h2 className="text-2xl font-semibold">
                        Account Created Successfully!
                    </h2>
                    <p className="text-muted-foreground">
                        Thank you for registering. Your account has been
                        created but requires administrator approval before you
                        can access the system.
                    </p>
                </div>

                <div className="w-full space-y-4 rounded-lg border border-border bg-muted/50 p-6">
                    <div className="flex items-start gap-3 text-left">
                        <CheckCircle className="mt-0.5 h-5 w-5 flex-shrink-0 text-green-500" />
                        <div className="space-y-1">
                            <p className="font-medium">What's Next?</p>
                            <p className="text-sm text-muted-foreground">
                                An administrator will review your account and
                                approve it shortly. You'll receive an email
                                notification once your account is approved.
                            </p>
                        </div>
                    </div>

                    <div className="flex items-start gap-3 text-left">
                        <CheckCircle className="mt-0.5 h-5 w-5 flex-shrink-0 text-green-500" />
                        <div className="space-y-1">
                            <p className="font-medium">Need Help?</p>
                            <p className="text-sm text-muted-foreground">
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

                <p className="text-xs text-muted-foreground">
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
