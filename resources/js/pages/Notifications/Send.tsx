import React, { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { PageHeader } from '@/components/PageHeader';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Badge } from '@/components/ui/badge';
import { X, ChevronsUpDown, Check, Send, AlertCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import type { BreadcrumbItem } from '@/types';
import { index as notificationsIndexRoute, store as notificationsStoreRoute } from '@/routes/notifications';
import { Alert, AlertDescription } from '@/components/ui/alert';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Notifications', href: notificationsIndexRoute().url },
    { title: 'Send Notification', href: '#' }
];

interface User {
    id: number;
    name: string;
    email: string;
    role: string;
}

interface Role {
    value: string;
    label: string;
}

interface Props {
    users: User[];
    roles: Role[];
}

export default function SendNotification({ users, roles }: Props) {
    const { title } = usePageMeta({
        title: 'Send Notification',
        breadcrumbs
    });
    useFlashMessage();
    const isLoading = usePageLoading();

    const [openUserSelect, setOpenUserSelect] = useState(false);
    const [openMultiUserSelect, setOpenMultiUserSelect] = useState(false);
    const [selectedUsers, setSelectedUsers] = useState<User[]>([]);

    const { data, setData, post, processing, errors } = useForm({
        title: '',
        message: '',
        type: 'system',
        recipient_type: 'all',
        role: '',
        user_id: null as number | null,
        user_ids: [] as number[],
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(notificationsStoreRoute().url);
    };

    const handleRecipientTypeChange = (value: string) => {
        setData({
            ...data,
            recipient_type: value,
            role: '',
            user_id: null,
            user_ids: [],
        });
        setSelectedUsers([]);
    };

    const toggleUserSelection = (user: User) => {
        const isSelected = selectedUsers.some(u => u.id === user.id);
        let newSelection: User[];

        if (isSelected) {
            newSelection = selectedUsers.filter(u => u.id !== user.id);
        } else {
            newSelection = [...selectedUsers, user];
        }

        setSelectedUsers(newSelection);
        setData('user_ids', newSelection.map(u => u.id));
    };

    const removeSelectedUser = (userId: number) => {
        const newSelection = selectedUsers.filter(u => u.id !== userId);
        setSelectedUsers(newSelection);
        setData('user_ids', newSelection.map(u => u.id));
    };

    const getRecipientCount = () => {
        switch (data.recipient_type) {
            case 'all':
                return users.length;
            case 'role':
                return data.role ? users.filter(u => u.role === data.role).length : 0;
            case 'specific_users':
                return selectedUsers.length;
            case 'single_user':
                return data.user_id ? 1 : 0;
            default:
                return 0;
        }
    };

    const selectedSingleUser = users.find(u => u.id === data.user_id);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <LoadingOverlay isLoading={isLoading} />

            <div className="container mx-auto py-6 max-w-4xl">
                <PageHeader
                    title="Send Notification"
                    description="Send a notification to users in the system"
                />

                <form onSubmit={handleSubmit} className="mt-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Notification Details</CardTitle>
                            <CardDescription>
                                Fill in the notification details and select recipients
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            {/* Title */}
                            <div className="space-y-2">
                                <Label htmlFor="title">Title *</Label>
                                <Input
                                    id="title"
                                    value={data.title}
                                    onChange={(e) => setData('title', e.target.value)}
                                    placeholder="Enter notification title"
                                    className={errors.title ? 'border-destructive' : ''}
                                />
                                {errors.title && (
                                    <p className="text-sm text-destructive">{errors.title}</p>
                                )}
                            </div>

                            {/* Message */}
                            <div className="space-y-2">
                                <Label htmlFor="message">Message *</Label>
                                <Textarea
                                    id="message"
                                    value={data.message}
                                    onChange={(e) => setData('message', e.target.value)}
                                    placeholder="Enter notification message"
                                    rows={5}
                                    className={errors.message ? 'border-destructive' : ''}
                                />
                                {errors.message && (
                                    <p className="text-sm text-destructive">{errors.message}</p>
                                )}
                            </div>

                            {/* Notification Type */}
                            <div className="space-y-2">
                                <Label htmlFor="type">Notification Type</Label>
                                <Select
                                    value={data.type}
                                    onValueChange={(value) => setData('type', value)}
                                    defaultValue="system"
                                >
                                    <SelectTrigger id="type">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="system">System</SelectItem>
                                        <SelectItem value="announcement">Announcement</SelectItem>
                                        <SelectItem value="reminder">Reminder</SelectItem>
                                        <SelectItem value="alert">Alert</SelectItem>
                                        <SelectItem value="custom">Custom</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            {/* Recipient Type */}
                            <div className="space-y-3">
                                <Label>Send To *</Label>
                                <RadioGroup
                                    value={data.recipient_type}
                                    onValueChange={handleRecipientTypeChange}
                                >
                                    <div className="flex items-center space-x-2">
                                        <RadioGroupItem value="all" id="all" />
                                        <Label htmlFor="all" className="font-normal cursor-pointer">
                                            All Users ({users.length} users)
                                        </Label>
                                    </div>
                                    <div className="flex items-center space-x-2">
                                        <RadioGroupItem value="role" id="role" />
                                        <Label htmlFor="role" className="font-normal cursor-pointer">
                                            By Role
                                        </Label>
                                    </div>
                                    <div className="flex items-center space-x-2">
                                        <RadioGroupItem value="specific_users" id="specific_users" />
                                        <Label htmlFor="specific_users" className="font-normal cursor-pointer">
                                            Multiple Specific Users
                                        </Label>
                                    </div>
                                    <div className="flex items-center space-x-2">
                                        <RadioGroupItem value="single_user" id="single_user" />
                                        <Label htmlFor="single_user" className="font-normal cursor-pointer">
                                            Single User
                                        </Label>
                                    </div>
                                </RadioGroup>
                                {errors.recipient_type && (
                                    <p className="text-sm text-destructive">{errors.recipient_type}</p>
                                )}
                            </div>

                            {/* Role Selection */}
                            {data.recipient_type === 'role' && (
                                <div className="space-y-2">
                                    <Label htmlFor="role">Select Role *</Label>
                                    <Select value={data.role} onValueChange={(value) => setData('role', value)}>
                                        <SelectTrigger className={errors.role ? 'border-destructive' : ''}>
                                            <SelectValue placeholder="Select a role" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {roles.map((role) => (
                                                <SelectItem key={role.value} value={role.value}>
                                                    {role.label} ({users.filter(u => u.role === role.value).length} users)
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.role && (
                                        <p className="text-sm text-destructive">{errors.role}</p>
                                    )}
                                </div>
                            )}

                            {/* Single User Selection */}
                            {data.recipient_type === 'single_user' && (
                                <div className="space-y-2">
                                    <Label>Select User *</Label>
                                    <Popover open={openUserSelect} onOpenChange={setOpenUserSelect}>
                                        <PopoverTrigger asChild>
                                            <Button
                                                variant="outline"
                                                role="combobox"
                                                aria-expanded={openUserSelect}
                                                className={cn(
                                                    'w-full justify-between',
                                                    errors.user_id && 'border-destructive'
                                                )}
                                            >
                                                {selectedSingleUser
                                                    ? `${selectedSingleUser.name} (${selectedSingleUser.role})`
                                                    : 'Select user...'}
                                                <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                            </Button>
                                        </PopoverTrigger>
                                        <PopoverContent className="w-full p-0">
                                            <Command>
                                                <CommandInput placeholder="Search users..." />
                                                <CommandList>
                                                    <CommandEmpty>No user found.</CommandEmpty>
                                                    <CommandGroup>
                                                        {users.map((user) => (
                                                            <CommandItem
                                                                key={user.id}
                                                                value={`${user.name} ${user.email}`}
                                                                onSelect={() => {
                                                                    setData('user_id', user.id);
                                                                    setOpenUserSelect(false);
                                                                }}
                                                            >
                                                                <Check
                                                                    className={cn(
                                                                        'mr-2 h-4 w-4',
                                                                        data.user_id === user.id ? 'opacity-100' : 'opacity-0'
                                                                    )}
                                                                />
                                                                <div className="flex flex-col">
                                                                    <span>{user.name}</span>
                                                                    <span className="text-xs text-muted-foreground">
                                                                        {user.email} • {user.role}
                                                                    </span>
                                                                </div>
                                                            </CommandItem>
                                                        ))}
                                                    </CommandGroup>
                                                </CommandList>
                                            </Command>
                                        </PopoverContent>
                                    </Popover>
                                    {errors.user_id && (
                                        <p className="text-sm text-destructive">{errors.user_id}</p>
                                    )}
                                </div>
                            )}

                            {/* Multiple Users Selection */}
                            {data.recipient_type === 'specific_users' && (
                                <div className="space-y-2">
                                    <Label>Select Users *</Label>
                                    <Popover open={openMultiUserSelect} onOpenChange={setOpenMultiUserSelect}>
                                        <PopoverTrigger asChild>
                                            <Button
                                                variant="outline"
                                                role="combobox"
                                                aria-expanded={openMultiUserSelect}
                                                className={cn(
                                                    'w-full justify-between',
                                                    errors.user_ids && 'border-destructive'
                                                )}
                                            >
                                                {selectedUsers.length > 0
                                                    ? `${selectedUsers.length} user(s) selected`
                                                    : 'Select users...'}
                                                <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                            </Button>
                                        </PopoverTrigger>
                                        <PopoverContent className="w-full p-0">
                                            <Command>
                                                <CommandInput placeholder="Search users..." />
                                                <CommandList>
                                                    <CommandEmpty>No user found.</CommandEmpty>
                                                    <CommandGroup>
                                                        {users.map((user) => {
                                                            const isSelected = selectedUsers.some(u => u.id === user.id);
                                                            return (
                                                                <CommandItem
                                                                    key={user.id}
                                                                    value={`${user.name} ${user.email}`}
                                                                    onSelect={() => toggleUserSelection(user)}
                                                                >
                                                                    <Check
                                                                        className={cn(
                                                                            'mr-2 h-4 w-4',
                                                                            isSelected ? 'opacity-100' : 'opacity-0'
                                                                        )}
                                                                    />
                                                                    <div className="flex flex-col">
                                                                        <span>{user.name}</span>
                                                                        <span className="text-xs text-muted-foreground">
                                                                            {user.email} • {user.role}
                                                                        </span>
                                                                    </div>
                                                                </CommandItem>
                                                            );
                                                        })}
                                                    </CommandGroup>
                                                </CommandList>
                                            </Command>
                                        </PopoverContent>
                                    </Popover>

                                    {/* Selected Users Badges */}
                                    {selectedUsers.length > 0 && (
                                        <div className="flex flex-wrap gap-2 mt-2">
                                            {selectedUsers.map((user) => (
                                                <Badge key={user.id} variant="secondary" className="gap-1">
                                                    {user.name}
                                                    <Button
                                                        type="button"
                                                        onClick={() => removeSelectedUser(user.id)}
                                                        className="ml-1 hover:text-destructive"
                                                    >
                                                        <X className="h-3 w-3" />
                                                    </Button>
                                                </Badge>
                                            ))}
                                        </div>
                                    )}

                                    {errors.user_ids && (
                                        <p className="text-sm text-destructive">{errors.user_ids}</p>
                                    )}
                                </div>
                            )}

                            {/* Recipient Count Alert */}
                            {getRecipientCount() > 0 && (
                                <Alert>
                                    <AlertCircle className="h-4 w-4" />
                                    <AlertDescription>
                                        This notification will be sent to <strong>{getRecipientCount()}</strong> user(s).
                                    </AlertDescription>
                                </Alert>
                            )}
                        </CardContent>
                    </Card>

                    {/* Submit Buttons */}
                    <div className="flex justify-end gap-3 mt-6">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.visit(notificationsIndexRoute().url)}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing || getRecipientCount() === 0}>
                            <Send className="mr-2 h-4 w-4" />
                            {processing ? 'Sending...' : 'Send Notification'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
