import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { Head, useForm, Link } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, RefreshCw, Lock, Eye, EyeOff } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Props {
    email: string;
}

export default function AccountDeleted({ email }: Props) {
    const [showPassword, setShowPassword] = useState(false);
    const [showPasswordConfirmation, setShowPasswordConfirmation] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        email: email,
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/account/reactivate', {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout
            title="Account Marked for Deletion"
            description="Your account is pending deletion confirmation"
        >
            <Head title="Account Deleted" />

            <div className="flex flex-col items-center gap-6 text-center">
                <div className="rounded-full bg-yellow-500/20 p-4">
                    <AlertTriangle className="h-12 w-12 text-yellow-500" />
                </div>

                <div className="space-y-3">
                    <h2 className="text-2xl font-semibold text-white">
                        Your Account is Pending Deletion
                    </h2>
                    <p className="text-gray-300">
                        Your account has been marked for deletion but has not been
                        confirmed by an administrator yet. You can reactivate your
                        account by setting a new password.
                    </p>
                </div>

                <div className="w-full space-y-4 rounded-lg border border-white/10 bg-white/5 p-6">
                    <div className="flex items-start gap-3 text-left">
                        <RefreshCw className="mt-0.5 h-5 w-5 flex-shrink-0 text-blue-400" />
                        <div className="space-y-1">
                            <p className="font-medium text-white">Want to Reactivate?</p>
                            <p className="text-sm text-gray-400">
                                You can restore your account access by entering a new
                                password below. Your account will be reactivated
                                immediately.
                            </p>
                        </div>
                    </div>
                </div>

                <form onSubmit={submit} className="w-full space-y-4">
                    <div className="space-y-2 text-left">
                        <Label htmlFor="email" className="text-gray-300">Email</Label>
                        <Input
                            id="email"
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            disabled
                            className="bg-white/10 border-white/20 text-gray-300"
                        />
                        {errors.email && (
                            <p className="text-sm text-red-400">{errors.email}</p>
                        )}
                    </div>

                    <div className="space-y-2 text-left">
                        <Label htmlFor="password" className="text-gray-300">New Password</Label>
                        <div className="relative">
                            <Lock className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                            <Input
                                id="password"
                                type={showPassword ? 'text' : 'password'}
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                placeholder="Enter new password"
                                className="pl-10 pr-10 bg-white/10 border-white/20 text-white placeholder:text-gray-500"
                                autoFocus
                            />
                            <button
                                type="button"
                                onClick={() => setShowPassword(!showPassword)}
                                className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-white"
                            >
                                {showPassword ? (
                                    <EyeOff className="h-4 w-4" />
                                ) : (
                                    <Eye className="h-4 w-4" />
                                )}
                            </button>
                        </div>
                        {errors.password && (
                            <p className="text-sm text-red-400">{errors.password}</p>
                        )}
                    </div>

                    <div className="space-y-2 text-left">
                        <Label htmlFor="password_confirmation" className="text-gray-300">Confirm New Password</Label>
                        <div className="relative">
                            <Lock className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                            <Input
                                id="password_confirmation"
                                type={showPasswordConfirmation ? 'text' : 'password'}
                                value={data.password_confirmation}
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                                placeholder="Confirm new password"
                                className="pl-10 pr-10 bg-white/10 border-white/20 text-white placeholder:text-gray-500"
                            />
                            <button
                                type="button"
                                onClick={() => setShowPasswordConfirmation(!showPasswordConfirmation)}
                                className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-white"
                            >
                                {showPasswordConfirmation ? (
                                    <EyeOff className="h-4 w-4" />
                                ) : (
                                    <Eye className="h-4 w-4" />
                                )}
                            </button>
                        </div>
                        {errors.password_confirmation && (
                            <p className="text-sm text-red-400">{errors.password_confirmation}</p>
                        )}
                    </div>

                    <div className="flex flex-col gap-3 pt-2">
                        <Button
                            type="submit"
                            className="w-full bg-blue-600 hover:bg-blue-700 text-white"
                            disabled={processing}
                        >
                            {processing ? (
                                <>
                                    <RefreshCw className="mr-2 h-4 w-4 animate-spin" />
                                    Reactivating...
                                </>
                            ) : (
                                <>
                                    <RefreshCw className="mr-2 h-4 w-4" />
                                    Reactivate Account
                                </>
                            )}
                        </Button>

                        <Link href="/login" className="w-full">
                            <Button
                                type="button"
                                variant="outline"
                                className="w-full border-white/20 text-gray-800 hover:bg-white/10 hover:text-white"
                            >
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back to Login
                            </Button>
                        </Link>
                    </div>
                </form>

                <p className="text-xs text-gray-400">
                    If you believe your account was deleted by mistake, please
                    contact your system administrator for assistance.
                </p>
            </div>
        </AuthLayout>
    );
}
