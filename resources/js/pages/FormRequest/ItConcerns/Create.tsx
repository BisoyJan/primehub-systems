import React, { useState, useMemo } from "react";
import { Head, router, useForm, usePage } from "@inertiajs/react";
import AppLayout from "@/layouts/app-layout";
import { useFlashMessage, usePageLoading, usePageMeta } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue
} from "@/components/ui/select";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Switch } from "@/components/ui/switch";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from "@/components/ui/command";
import { AlertCircle, Check, ChevronsUpDown } from "lucide-react";

interface Site {
    id: number;
    name: string;
}

interface User {
    id: number;
    first_name: string;
    middle_name: string | null;
    last_name: string;
}

interface PageProps {
    sites: Site[];
    users?: User[];
    [key: string]: unknown;
}

export default function ItConcernCreate() {
    const { sites, users } = usePage<PageProps>().props;
    const [fileForSomeoneElse, setFileForSomeoneElse] = useState(false);
    const [isUserPopoverOpen, setIsUserPopoverOpen] = useState(false);
    const [userSearchQuery, setUserSearchQuery] = useState("");

    const { title, breadcrumbs } = usePageMeta({
        title: "Submit IT Concern",
        breadcrumbs: [
            { title: "IT Concerns", href: "/form-requests/it-concerns" },
            { title: "Create", href: "" },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const { data, setData, post, processing, errors } = useForm({
        user_id: "",
        site_id: "",
        station_number: "",
        category: "Hardware",
        priority: "medium",
        description: "",
    });

    // Filter users based on search query
    const filteredUsers = useMemo(() => {
        if (!users || !userSearchQuery) return users || [];
        const query = userSearchQuery.toLowerCase();
        return users.filter(user => {
            const fullName = `${user.first_name}${user.middle_name ? ' ' + user.middle_name : ''} ${user.last_name}`.toLowerCase();
            return fullName.includes(query);
        });
    }, [users, userSearchQuery]);

    const handleToggleChange = (checked: boolean) => {
        setFileForSomeoneElse(checked);
        if (!checked) {
            setData("user_id", "");
            setUserSearchQuery("");
        }
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post("/form-requests/it-concerns", {
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isPageLoading || processing} />

                <PageHeader
                    title="Submit IT Concern"
                    description="Report an IT issue for assistance"
                />

                <div className="flex justify-center">
                    <Card className="w-full max-w-2xl">
                        <CardHeader>
                            <CardTitle>IT Concern Details</CardTitle>
                            <CardDescription>
                                Please provide detailed information about your IT issue
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-6">
                                {/* Toggle for filing on behalf of someone else */}
                                {users && (
                                    <div className="space-y-4">
                                        <div className="flex items-center justify-between rounded-lg border p-4 bg-muted/30">
                                            <div className="space-y-0.5">
                                                <Label htmlFor="file-for-someone" className="text-base font-medium">
                                                    File for someone else
                                                </Label>
                                                <p className="text-sm text-muted-foreground">
                                                    Enable this if you're reporting an issue on behalf of another employee (e.g., their PC is not working)
                                                </p>
                                            </div>
                                            <Switch
                                                id="file-for-someone"
                                                checked={fileForSomeoneElse}
                                                onCheckedChange={handleToggleChange}
                                            />
                                        </div>

                                        {/* User selection - shown when toggle is enabled */}
                                        {fileForSomeoneElse && (
                                            <div className="space-y-2 animate-in fade-in-50 duration-200">
                                                <div className="flex items-center gap-2 p-3 rounded-md bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800">
                                                    <AlertCircle className="h-4 w-4 text-amber-600 dark:text-amber-400" />
                                                    <p className="text-sm text-amber-700 dark:text-amber-300">
                                                        The IT concern will be filed under the selected employee's name
                                                    </p>
                                                </div>
                                                <Label htmlFor="user_id">
                                                    Select Employee <span className="text-red-500">*</span>
                                                </Label>
                                                <Popover open={isUserPopoverOpen} onOpenChange={setIsUserPopoverOpen}>
                                                    <PopoverTrigger asChild>
                                                        <Button
                                                            variant="outline"
                                                            role="combobox"
                                                            aria-expanded={isUserPopoverOpen}
                                                            className={`w-full justify-between font-normal ${errors.user_id ? "border-red-500" : ""}`}
                                                        >
                                                            <span className="truncate">
                                                                {data.user_id && users
                                                                    ? (() => {
                                                                        const user = users.find(u => String(u.id) === data.user_id);
                                                                        return user ? `${user.first_name}${user.middle_name ? ' ' + user.middle_name : ''} ${user.last_name}` : "Select an employee...";
                                                                    })()
                                                                    : "Select an employee..."}
                                                            </span>
                                                            <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                                        </Button>
                                                    </PopoverTrigger>
                                                    <PopoverContent className="w-full p-0" align="start">
                                                        <Command shouldFilter={false}>
                                                            <CommandInput
                                                                placeholder="Search employee..."
                                                                value={userSearchQuery}
                                                                onValueChange={setUserSearchQuery}
                                                            />
                                                            <CommandList>
                                                                <CommandEmpty>No employee found.</CommandEmpty>
                                                                <CommandGroup>
                                                                    {filteredUsers.map((user) => {
                                                                        const userFullName = `${user.first_name}${user.middle_name ? ' ' + user.middle_name : ''} ${user.last_name}`;
                                                                        return (
                                                                            <CommandItem
                                                                                key={user.id}
                                                                                value={userFullName}
                                                                                onSelect={() => {
                                                                                    setData("user_id", String(user.id));
                                                                                    setIsUserPopoverOpen(false);
                                                                                    setUserSearchQuery("");
                                                                                }}
                                                                                className="cursor-pointer"
                                                                            >
                                                                                <Check
                                                                                    className={`mr-2 h-4 w-4 ${data.user_id === String(user.id)
                                                                                        ? "opacity-100"
                                                                                        : "opacity-0"
                                                                                        }`}
                                                                                />
                                                                                {userFullName}
                                                                            </CommandItem>
                                                                        );
                                                                    })}
                                                                </CommandGroup>
                                                            </CommandList>
                                                        </Command>
                                                    </PopoverContent>
                                                </Popover>
                                                {errors.user_id && (
                                                    <p className="text-sm text-red-500">{errors.user_id}</p>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}

                                <div className="space-y-2">
                                    <Label htmlFor="site_id">
                                        Site <span className="text-red-500">*</span>
                                    </Label>
                                    <Select
                                        value={data.site_id}
                                        onValueChange={(value) => setData("site_id", value)}
                                    >
                                        <SelectTrigger className={errors.site_id ? "border-red-500" : ""}>
                                            <SelectValue placeholder="Select a site" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {sites.map((site) => (
                                                <SelectItem key={site.id} value={String(site.id)}>
                                                    {site.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.site_id && (
                                        <p className="text-sm text-red-500">{errors.site_id}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="station_number">
                                        Station # <span className="text-red-500">*</span>
                                    </Label>
                                    <Input
                                        id="station_number"
                                        type="text"
                                        placeholder="e.g., PH1-001"
                                        value={data.station_number}
                                        onChange={(e) => setData("station_number", e.target.value)}
                                        className={errors.station_number ? "border-red-500" : ""}
                                    />
                                    {errors.station_number && (
                                        <p className="text-sm text-red-500">{errors.station_number}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="category">
                                        Category <span className="text-red-500">*</span>
                                    </Label>
                                    <Select
                                        value={data.category}
                                        onValueChange={(value) => setData("category", value)}
                                    >
                                        <SelectTrigger className={errors.category ? "border-red-500" : ""}>
                                            <SelectValue placeholder="Choose a category" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="Hardware">
                                                Hardware (e.g., PC, Mouse, Keyboard, etc.)
                                            </SelectItem>
                                            <SelectItem value="Software">
                                                Software (e.g., Gensys Dialer, Krisp, VPN, etc.)
                                            </SelectItem>
                                            <SelectItem value="Network/Connectivity">
                                                Network/Connectivity (e.g., no internet, slow internet, etc.)
                                            </SelectItem>
                                            <SelectItem value="Other">Other</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    {errors.category && (
                                        <p className="text-sm text-red-500">{errors.category}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="priority">
                                        Priority <span className="text-red-500">*</span>
                                    </Label>
                                    <Select
                                        value={data.priority}
                                        onValueChange={(value) => setData("priority", value)}
                                    >
                                        <SelectTrigger className={errors.priority ? "border-red-500" : ""}>
                                            <SelectValue placeholder="Select priority level" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="low">Low - Can wait</SelectItem>
                                            <SelectItem value="medium">Medium - Normal priority</SelectItem>
                                            <SelectItem value="high">High - Needs attention</SelectItem>
                                            <SelectItem value="urgent">Urgent - Critical issue</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    {errors.priority && (
                                        <p className="text-sm text-red-500">{errors.priority}</p>
                                    )}
                                    <p className="text-sm text-muted-foreground">
                                        Select the urgency level of your issue
                                    </p>
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="description">
                                        Description <span className="text-red-500">*</span>
                                    </Label>
                                    <Textarea
                                        id="description"
                                        placeholder="Briefly describe your IT concern"
                                        value={data.description}
                                        onChange={(e) => setData("description", e.target.value)}
                                        className={errors.description ? "border-red-500" : ""}
                                        rows={5}
                                    />
                                    {errors.description && (
                                        <p className="text-sm text-red-500">{errors.description}</p>
                                    )}
                                    <p className="text-sm text-muted-foreground">
                                        {data.description.length} / 1000 characters
                                    </p>
                                </div>

                                <div className="flex gap-3 pt-4">
                                    <Button type="submit" disabled={processing}>
                                        {processing ? "Submitting..." : "Submit IT Concern"}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => router.get("/form-requests/it-concerns")}
                                        disabled={processing}
                                    >
                                        Cancel
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
