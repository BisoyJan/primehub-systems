import React, { useState } from "react";
import { router, useForm, usePage, Head } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import AppLayout from "@/layouts/app-layout";
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from "@/components/ui/select";
import { Input } from "@/components/ui/input";
import { DatePicker } from "@/components/ui/date-picker";
import { Label } from "@/components/ui/label";
import { toast } from "sonner";
import { PageHeader } from "@/components/PageHeader";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { useFlashMessage, usePageLoading, usePageMeta } from "@/hooks";
import { index as accountsIndex, create as accountsCreate, store as accountsStore } from "@/routes/accounts";
import { Eye, EyeOff } from "lucide-react";

export default function AccountCreate() {
    const { roles } = usePage<{ roles: string[] }>().props;
    const [showPassword, setShowPassword] = useState(false);
    const [showPasswordConfirmation, setShowPasswordConfirmation] = useState(false);

    const { title, breadcrumbs } = usePageMeta({
        title: "Create User Account",
        breadcrumbs: [
            { title: "Accounts", href: accountsIndex().url },
            { title: "Create", href: accountsCreate().url },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const { data, setData, post, processing, errors } = useForm({
        first_name: "",
        middle_name: "",
        last_name: "",
        email: "",
        password: "",
        password_confirmation: "",
        role: "Agent",
        hired_date: "",
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(accountsStore().url, {
            onSuccess: () => {
                toast.success("User account created successfully");
                router.get(accountsIndex().url);
            },
            onError: (errors) => {
                const firstError = Object.values(errors)[0] as string;
                toast.error(firstError || "Validation error");
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <LoadingOverlay isLoading={isPageLoading || processing} message={processing ? "Saving account..." : undefined} />
            <div className="max-w-4xl mx-auto p-6 space-y-6">
                <PageHeader title="Create User Account" description="Add a new user to the system" />
                <Card>
                    <CardHeader>
                        <CardTitle>Create User Account</CardTitle>
                        <CardDescription>Add a new user to the system</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="space-y-6">
                            {/* Personal Information Section */}
                            <div className="space-y-4">
                                <h3 className="text-lg font-semibold">Personal Information</h3>
                                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div>
                                        <Label htmlFor="first_name">First Name</Label>
                                        <Input
                                            id="first_name"
                                            type="text"
                                            value={data.first_name}
                                            onChange={e => setData("first_name", e.target.value)}
                                            placeholder="John"
                                            required
                                        />
                                        {errors.first_name && <p className="text-red-600 text-sm mt-1">{errors.first_name}</p>}
                                    </div>

                                    <div>
                                        <Label htmlFor="middle_name">Middle Initial (Optional)</Label>
                                        <Input
                                            id="middle_name"
                                            type="text"
                                            value={data.middle_name}
                                            onChange={e => setData("middle_name", e.target.value.toUpperCase().charAt(0))}
                                            placeholder="M"
                                            maxLength={1}
                                        />
                                        {errors.middle_name && <p className="text-red-600 text-sm mt-1">{errors.middle_name}</p>}
                                    </div>

                                    <div>
                                        <Label htmlFor="last_name">Last Name</Label>
                                        <Input
                                            id="last_name"
                                            type="text"
                                            value={data.last_name}
                                            onChange={e => setData("last_name", e.target.value)}
                                            placeholder="Doe"
                                            required
                                        />
                                        {errors.last_name && <p className="text-red-600 text-sm mt-1">{errors.last_name}</p>}
                                    </div>
                                </div>
                            </div>

                            {/* Account Information Section */}
                            <div className="space-y-4">
                                <h3 className="text-lg font-semibold">Account Information</h3>
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <Label htmlFor="email">Email Address</Label>
                                        <Input
                                            id="email"
                                            type="email"
                                            value={data.email}
                                            onChange={e => setData("email", e.target.value)}
                                            placeholder="john.doe@primehubmail.com"
                                            required
                                        />
                                        <p className="text-xs text-muted-foreground mt-1">
                                            Only @primehubmail.com and @prmhubsolutions.com emails are accepted.
                                        </p>
                                        {errors.email && <p className="text-red-600 text-sm mt-1">{errors.email}</p>}
                                    </div>

                                    <div>
                                        <Label htmlFor="role">Role</Label>
                                        <Select value={data.role} onValueChange={(val) => setData("role", val)}>
                                            <SelectTrigger className="w-full">
                                                <SelectValue placeholder="Select Role" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {roles.map((role) => (
                                                    <SelectItem key={role} value={role}>{role}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {errors.role && <p className="text-red-600 text-sm mt-1">{errors.role}</p>}
                                    </div>

                                    <div>
                                        <Label htmlFor="hired_date">Hired Date</Label>
                                        <DatePicker
                                            value={data.hired_date}
                                            onChange={(value) => setData("hired_date", value)}
                                            placeholder="Select hired date"
                                        />
                                        {errors.hired_date && <p className="text-red-600 text-sm mt-1">{errors.hired_date}</p>}
                                    </div>
                                </div>
                            </div>

                            {/* Security Section */}
                            <div className="space-y-4">
                                <h3 className="text-lg font-semibold">Security</h3>
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <Label htmlFor="password">Password</Label>
                                        <div className="relative">
                                            <Input
                                                id="password"
                                                type={showPassword ? "text" : "password"}
                                                value={data.password}
                                                onChange={e => setData("password", e.target.value)}
                                                placeholder="••••••••"
                                                className="pr-10"
                                                required
                                            />
                                            <button
                                                type="button"
                                                onClick={() => setShowPassword(!showPassword)}
                                                className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                                aria-label={showPassword ? "Hide password" : "Show password"}
                                            >
                                                {showPassword ? (
                                                    <EyeOff className="h-4 w-4" />
                                                ) : (
                                                    <Eye className="h-4 w-4" />
                                                )}
                                            </button>
                                        </div>
                                        {errors.password && <p className="text-red-600 text-sm mt-1">{errors.password}</p>}
                                    </div>

                                    <div>
                                        <Label htmlFor="password_confirmation">Confirm Password</Label>
                                        <div className="relative">
                                            <Input
                                                id="password_confirmation"
                                                type={showPasswordConfirmation ? "text" : "password"}
                                                value={data.password_confirmation}
                                                onChange={e => setData("password_confirmation", e.target.value)}
                                                placeholder="••••••••"
                                                className="pr-10"
                                                required
                                            />
                                            <button
                                                type="button"
                                                onClick={() => setShowPasswordConfirmation(!showPasswordConfirmation)}
                                                className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                                aria-label={showPasswordConfirmation ? "Hide password confirmation" : "Show password confirmation"}
                                            >
                                                {showPasswordConfirmation ? (
                                                    <EyeOff className="h-4 w-4" />
                                                ) : (
                                                    <Eye className="h-4 w-4" />
                                                )}
                                            </button>
                                        </div>
                                        {errors.password_confirmation && <p className="text-red-600 text-sm mt-1">{errors.password_confirmation}</p>}
                                    </div>
                                </div>
                            </div>

                            <div className="flex gap-2 pt-4 border-t">
                                <Button type="submit" disabled={processing}>
                                    {processing ? "Creating..." : "Create Account"}
                                </Button>
                                <Button
                                    variant="outline"
                                    type="button"
                                    onClick={() => router.get(accountsIndex().url)}
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
