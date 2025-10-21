import React from "react";
import { router, useForm, usePage, Head } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import AppLayout from "@/layouts/app-layout";
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from "@/components/ui/select";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { toast } from "sonner";
import type { BreadcrumbItem } from "@/types";
import { index as accountsIndex, create as accountsCreate } from "@/routes/accounts";

const breadcrumbs: BreadcrumbItem[] = [
    { title: "Accounts", href: accountsIndex().url },
    { title: "Create", href: accountsCreate().url }
];

export default function AccountCreate() {
    const { roles } = usePage<{ roles: string[] }>().props;

    const { data, setData, post, processing, errors } = useForm({
        name: "",
        email: "",
        password: "",
        password_confirmation: "",
        role: "Agent",
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post("/accounts", {
            onSuccess: () => {
                toast.success("User account created successfully");
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
            <Head title="Create User Account" />
            <div className="max-w-2xl mx-auto mt-4 p-6 rounded-lg shadow">
                <h2 className="text-2xl font-semibold mb-6">Create User Account</h2>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <div>
                        <Label htmlFor="name">Full Name</Label>
                        <Input
                            id="name"
                            type="text"
                            value={data.name}
                            onChange={e => setData("name", e.target.value)}
                            placeholder="John Doe"
                            required
                        />
                        {errors.name && <p className="text-red-600 text-sm mt-1">{errors.name}</p>}
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

                    <div>
                        <Label htmlFor="password">Password</Label>
                        <Input
                            id="password"
                            type="password"
                            value={data.password}
                            onChange={e => setData("password", e.target.value)}
                            placeholder="••••••••"
                            required
                        />
                        {errors.password && <p className="text-red-600 text-sm mt-1">{errors.password}</p>}
                    </div>

                    <div>
                        <Label htmlFor="password_confirmation">Confirm Password</Label>
                        <Input
                            id="password_confirmation"
                            type="password"
                            value={data.password_confirmation}
                            onChange={e => setData("password_confirmation", e.target.value)}
                            placeholder="••••••••"
                            required
                        />
                        {errors.password_confirmation && <p className="text-red-600 text-sm mt-1">{errors.password_confirmation}</p>}
                    </div>

                    <div className="flex gap-2 pt-4">
                        <Button type="submit" disabled={processing}>
                            {processing ? "Creating..." : "Create Account"}
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
