import React from "react";
import { router, useForm, usePage, Head } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import AppLayout from "@/layouts/app-layout";
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from "@/components/ui/select";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { toast } from "sonner";
import type { BreadcrumbItem } from "@/types";
import { index as accountsIndex } from "@/routes/accounts";

const breadcrumbs: BreadcrumbItem[] = [
    { title: "Accounts", href: accountsIndex().url },
    { title: "Edit", href: "#" }
];

interface User {
    id: number;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    email: string;
    role: string;
}

export default function AccountEdit() {
    const { user, roles } = usePage<{ user: User; roles: string[] }>().props;

    const { data, setData, patch, processing, errors } = useForm({
        first_name: user.first_name,
        middle_name: user.middle_name || "",
        last_name: user.last_name,
        email: user.email,
        password: "",
        password_confirmation: "",
        role: user.role,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        patch(`/accounts/${user.id}`, {
            onSuccess: () => {
                toast.success("User account updated successfully");
                router.get("/accounts");
            },
            onError: (errors) => {
                const firstError = Object.values(errors)[0] as string;
                toast.error(firstError || "Validation error");
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Edit User Account" />
            <div className="max-w-2xl mx-auto mt-4 p-6 rounded-lg shadow">
                <h2 className="text-2xl font-semibold mb-6">Edit User Account</h2>

                <form onSubmit={handleSubmit} className="space-y-4">
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

                    <div>
                        <Label htmlFor="email">Email Address</Label>
                        <Input
                            id="email"
                            type="email"
                            value={data.email}
                            onChange={e => setData("email", e.target.value)}
                            placeholder="john.doe@example.com"
                            required
                        />
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

                    <div className="border-t pt-4 mt-4">
                        <h3 className="text-lg font-medium mb-3">Change Password (Optional)</h3>
                        <p className="text-sm text-gray-600 mb-4">
                            Leave password fields blank if you don't want to change the password
                        </p>

                        <div className="space-y-4">
                            <div>
                                <Label htmlFor="password">New Password</Label>
                                <Input
                                    id="password"
                                    type="password"
                                    value={data.password}
                                    onChange={e => setData("password", e.target.value)}
                                    placeholder="••••••••"
                                />
                                {errors.password && <p className="text-red-600 text-sm mt-1">{errors.password}</p>}
                            </div>

                            <div>
                                <Label htmlFor="password_confirmation">Confirm New Password</Label>
                                <Input
                                    id="password_confirmation"
                                    type="password"
                                    value={data.password_confirmation}
                                    onChange={e => setData("password_confirmation", e.target.value)}
                                    placeholder="••••••••"
                                />
                                {errors.password_confirmation && <p className="text-red-600 text-sm mt-1">{errors.password_confirmation}</p>}
                            </div>
                        </div>
                    </div>

                    <div className="flex gap-2 pt-4">
                        <Button type="submit" disabled={processing}>
                            {processing ? "Updating..." : "Update Account"}
                        </Button>
                        <Button
                            variant="outline"
                            type="button"
                            onClick={() => router.get("/accounts")}
                        >
                            Cancel
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
