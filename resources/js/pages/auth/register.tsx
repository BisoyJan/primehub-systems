import RegisteredUserController from '@/actions/App/Http/Controllers/Auth/RegisteredUserController';
import { login } from '@/routes';
import { Form, Head } from '@inertiajs/react';
import { Eye, EyeOff, LoaderCircle } from 'lucide-react';
import { useState } from 'react';

import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

export default function Register() {
    const [showPassword, setShowPassword] = useState(false);
    const [showPasswordConfirmation, setShowPasswordConfirmation] = useState(false);

    return (
        <AuthLayout
            title="Create an account"
            description="Enter your details below to create your account"
        >
            <Head title="Register" />
            <Form
                action={RegisteredUserController.store.url()}
                method="post"
                resetOnSuccess={['password', 'password_confirmation']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-7">
                            <section className="grid gap-4" aria-labelledby="personal-details-heading">
                                <div className="grid gap-1">
                                    <h2 id="personal-details-heading" className="text-sm font-semibold text-white">
                                        Personal details
                                    </h2>
                                    <p className="text-xs text-muted-foreground">
                                        Use your name as it appears in company records.
                                    </p>
                                </div>

                                <div className="grid gap-4 sm:grid-cols-[1fr_5rem_1fr]">
                                    <div className="grid gap-2">
                                        <Label htmlFor="first_name">First name</Label>
                                        <Input
                                            id="first_name"
                                            type="text"
                                            required
                                            autoFocus
                                            tabIndex={1}
                                            autoComplete="given-name"
                                            name="first_name"
                                            placeholder="John"
                                        />
                                        <InputError message={errors.first_name} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="middle_name">M.I.</Label>
                                        <Input
                                            id="middle_name"
                                            type="text"
                                            tabIndex={2}
                                            autoComplete="additional-name"
                                            name="middle_name"
                                            placeholder="M"
                                            maxLength={1}
                                        />
                                        <InputError message={errors.middle_name} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="last_name">Last name</Label>
                                        <Input
                                            id="last_name"
                                            type="text"
                                            required
                                            tabIndex={3}
                                            autoComplete="family-name"
                                            name="last_name"
                                            placeholder="Doe"
                                        />
                                        <InputError message={errors.last_name} />
                                    </div>
                                </div>
                            </section>

                            <section className="grid gap-4" aria-labelledby="account-security-heading">
                                <div className="grid gap-1">
                                    <h2 id="account-security-heading" className="text-sm font-semibold text-white">
                                        Account security
                                    </h2>
                                    <p className="text-xs text-muted-foreground">
                                        Use your company email and create a secure password.
                                    </p>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="email">Email address</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        required
                                        tabIndex={4}
                                        autoComplete="email"
                                        name="email"
                                        placeholder="email@primehubmail.com"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Only @primehubmail.com and @prmhubsolutions.com emails are accepted.
                                    </p>
                                    <InputError message={errors.email} />
                                </div>

                                <div className="grid gap-2 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="password">Password</Label>
                                        <div className="relative">
                                            <Input
                                                id="password"
                                                type={showPassword ? 'text' : 'password'}
                                                required
                                                tabIndex={5}
                                                autoComplete="new-password"
                                                name="password"
                                                placeholder="Password"
                                                className="pr-10"
                                            />
                                            <button
                                                type="button"
                                                onClick={() => setShowPassword(!showPassword)}
                                                aria-label={showPassword ? 'Hide password' : 'Show password'}
                                                className="absolute right-3 top-1/2 -translate-y-1/2 rounded-sm p-1 text-gray-400 outline-none transition-colors hover:text-white focus-visible:ring-2 focus-visible:ring-ring"
                                            >
                                                {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                                            </button>
                                        </div>
                                        <InputError message={errors.password} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="password_confirmation">Confirm password</Label>
                                        <div className="relative">
                                            <Input
                                                id="password_confirmation"
                                                type={showPasswordConfirmation ? 'text' : 'password'}
                                                required
                                                tabIndex={6}
                                                autoComplete="new-password"
                                                name="password_confirmation"
                                                placeholder="Confirm password"
                                                className="pr-10"
                                            />
                                            <button
                                                type="button"
                                                onClick={() => setShowPasswordConfirmation(!showPasswordConfirmation)}
                                                aria-label={showPasswordConfirmation ? 'Hide password confirmation' : 'Show password confirmation'}
                                                className="absolute right-3 top-1/2 -translate-y-1/2 rounded-sm p-1 text-gray-400 outline-none transition-colors hover:text-white focus-visible:ring-2 focus-visible:ring-ring"
                                            >
                                                {showPasswordConfirmation ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                                            </button>
                                        </div>
                                        <InputError message={errors.password_confirmation} />
                                    </div>
                                </div>
                            </section>

                            <Button
                                type="submit"
                                className="mt-2 w-full"
                                tabIndex={7}
                                data-test="register-user-button"
                            >
                                {processing && (
                                    <LoaderCircle className="h-4 w-4 animate-spin" />
                                )}
                                Create account
                            </Button>
                        </div>

                        <div className="text-center text-sm text-muted-foreground">
                            Already have an account?{' '}
                            <TextLink href={login()} tabIndex={8}>
                                Log in
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
