import React, { useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { usePageMeta } from '@/hooks';
import { usePermission } from '@/hooks/useAuthorization';
import { PageHeader } from '@/components/PageHeader';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { ArrowLeft, Clock, CheckCircle, XCircle, AlertCircle, User, MapPin, Monitor, Calendar, Edit, Settings } from 'lucide-react';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import type { SharedData } from '@/types';

interface UserType {
    id: number;
    name: string;
    first_name: string;
    last_name: string;
}

interface Site {
    id: number;
    name: string;
}

interface ItConcern {
    id: number;
    user?: UserType;
    site: Site;
    station_number: string;
    category: string;
    description: string;
    status: 'pending' | 'in_progress' | 'resolved' | 'cancelled';
    priority: 'low' | 'medium' | 'high' | 'urgent';
    assigned_to?: UserType;
    resolved_by?: UserType;
    resolution_notes?: string;
    resolved_at?: string;
    created_at: string;
    updated_at: string;
}

interface PageProps extends SharedData {
    concern: ItConcern;
}

const getStatusBadge = (status: string) => {
    const config: Record<string, { label: string; className: string; icon: React.ReactNode }> = {
        pending: {
            label: 'Pending',
            className: 'bg-yellow-500',
            icon: <Clock className="mr-1 h-3 w-3" />,
        },
        in_progress: {
            label: 'In Progress',
            className: 'bg-blue-500',
            icon: <AlertCircle className="mr-1 h-3 w-3" />,
        },
        resolved: {
            label: 'Resolved',
            className: 'bg-green-500',
            icon: <CheckCircle className="mr-1 h-3 w-3" />,
        },
        cancelled: {
            label: 'Cancelled',
            className: 'bg-gray-500',
            icon: <XCircle className="mr-1 h-3 w-3" />,
        },
    };

    const { label, className, icon } = config[status] || {
        label: status,
        className: 'bg-gray-500',
        icon: null,
    };

    return (
        <Badge className={className}>
            {icon}
            {label}
        </Badge>
    );
};

const getPriorityBadge = (priority: string) => {
    const config: Record<string, { label: string; className: string }> = {
        low: { label: 'Low', className: 'bg-gray-500' },
        medium: { label: 'Medium', className: 'bg-blue-500' },
        high: { label: 'High', className: 'bg-orange-500' },
        urgent: { label: 'Urgent', className: 'bg-red-500 animate-pulse' },
    };

    const { label, className } = config[priority] || { label: priority, className: 'bg-gray-500' };
    return <Badge className={className}>{label}</Badge>;
};

const getCategoryBadge = (category: string) => {
    const config: Record<string, { label: string; className: string }> = {
        Hardware: { label: 'Hardware', className: 'bg-blue-500' },
        Software: { label: 'Software', className: 'bg-purple-500' },
        'Network/Connectivity': { label: 'Network/Connectivity', className: 'bg-orange-500' },
        Other: { label: 'Other', className: 'bg-gray-500' },
    };

    const { label, className } = config[category] || { label: category, className: 'bg-gray-500' };
    return <Badge className={className}>{label}</Badge>;
};

const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

export default function Show() {
    const { concern, auth } = usePage<PageProps>().props;
    const { can } = usePermission();

    const [updateDialogOpen, setUpdateDialogOpen] = useState(false);
    const [status, setStatus] = useState(concern.status);
    const [priority, setPriority] = useState(concern.priority);
    const [resolutionNotes, setResolutionNotes] = useState(concern.resolution_notes || '');

    const { title, breadcrumbs } = usePageMeta({
        title: `IT Concern #${concern.id}`,
        breadcrumbs: [
            { title: 'IT Concerns', href: '/form-requests/it-concerns' },
            { title: `#${concern.id}`, href: `/form-requests/it-concerns/${concern.id}` },
        ],
    });

    const handleUpdateStatus = () => {
        setUpdateDialogOpen(true);
    };

    const confirmUpdate = () => {
        router.post(`/form-requests/it-concerns/${concern.id}/resolve`, {
            resolution_notes: resolutionNotes,
            status: status,
            priority: priority,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setUpdateDialogOpen(false);
            },
        });
    };

    // Check if user can edit (global permission OR owner with pending/in_progress status)
    const canEdit = can('it_concerns.edit') ||
        (concern.user?.id === auth.user.id && ['pending', 'in_progress'].includes(concern.status));

    // Check if user can resolve/update status
    const canResolve = can('it_concerns.resolve');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                <div className="flex items-center justify-between gap-4">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => router.get('/form-requests/it-concerns')}
                    >
                        <ArrowLeft className="mr-2 h-4 w-4" />
                        Back to IT Concerns
                    </Button>

                    <div className="flex gap-2">
                        {canEdit && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => router.get(`/form-requests/it-concerns/${concern.id}/edit`)}
                            >
                                <Edit className="mr-2 h-4 w-4" />
                                Edit
                            </Button>
                        )}
                        {canResolve && concern.status !== 'resolved' && (
                            <Button
                                size="sm"
                                onClick={handleUpdateStatus}
                            >
                                <Settings className="mr-2 h-4 w-4" />
                                Update Status
                            </Button>
                        )}
                    </div>
                </div>

                <PageHeader
                    title={`IT Concern #${concern.id}`}
                    description="View IT concern details"
                />

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-lg">Concern Information</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex items-center gap-2">
                                <User className="h-4 w-4 text-muted-foreground" />
                                <span className="font-medium">Submitted By:</span>
                                <span>{concern.user?.name || 'N/A'}</span>
                            </div>
                            <div className="flex items-center gap-2">
                                <MapPin className="h-4 w-4 text-muted-foreground" />
                                <span className="font-medium">Site:</span>
                                <span>{concern.site.name}</span>
                            </div>
                            <div className="flex items-center gap-2">
                                <Monitor className="h-4 w-4 text-muted-foreground" />
                                <span className="font-medium">Station:</span>
                                <span>{concern.station_number}</span>
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="font-medium">Category:</span>
                                {getCategoryBadge(concern.category)}
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="font-medium">Priority:</span>
                                {getPriorityBadge(concern.priority)}
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="font-medium">Status:</span>
                                {getStatusBadge(concern.status)}
                            </div>
                            <div className="flex items-center gap-2">
                                <Calendar className="h-4 w-4 text-muted-foreground" />
                                <span className="font-medium">Created:</span>
                                <span>{formatDate(concern.created_at)}</span>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-lg">Description</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm text-muted-foreground whitespace-pre-wrap">
                                {concern.description}
                            </p>
                        </CardContent>
                    </Card>

                    {concern.resolution_notes && (
                        <Card className="md:col-span-2">
                            <CardHeader>
                                <CardTitle className="text-lg">Resolution</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {concern.resolved_by && (
                                    <div className="flex items-center gap-2">
                                        <User className="h-4 w-4 text-muted-foreground" />
                                        <span className="font-medium">Resolved By:</span>
                                        <span>{concern.resolved_by.name}</span>
                                    </div>
                                )}
                                {concern.resolved_at && (
                                    <div className="flex items-center gap-2">
                                        <Calendar className="h-4 w-4 text-muted-foreground" />
                                        <span className="font-medium">Resolved At:</span>
                                        <span>{formatDate(concern.resolved_at)}</span>
                                    </div>
                                )}
                                <div>
                                    <span className="font-medium">Resolution Notes:</span>
                                    <p className="mt-2 text-sm text-muted-foreground whitespace-pre-wrap">
                                        {concern.resolution_notes}
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* Update Status Dialog */}
                <AlertDialog open={updateDialogOpen} onOpenChange={setUpdateDialogOpen}>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Update IT Concern Status</AlertDialogTitle>
                            <AlertDialogDescription asChild>
                                <div className="space-y-3 mt-4">
                                    <div>
                                        <span className="font-medium text-foreground">Station:</span> {concern.station_number}
                                    </div>
                                    <div>
                                        <span className="font-medium text-foreground">Category:</span> {concern.category}
                                    </div>

                                    <div className="grid grid-cols-2 gap-3 mt-4">
                                        <div className="space-y-2">
                                            <Label htmlFor="update_status" className="text-foreground">
                                                Status <span className="text-red-500">*</span>
                                            </Label>
                                            <Select value={status} onValueChange={(value: 'pending' | 'in_progress' | 'resolved' | 'cancelled') => setStatus(value)}>
                                                <SelectTrigger id="update_status">
                                                    <SelectValue placeholder="Select status" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="pending">Pending</SelectItem>
                                                    <SelectItem value="in_progress">In Progress</SelectItem>
                                                    <SelectItem value="resolved">Resolved</SelectItem>
                                                    <SelectItem value="cancelled">Cancelled</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="update_priority" className="text-foreground">
                                                Priority <span className="text-red-500">*</span>
                                            </Label>
                                            <Select value={priority} onValueChange={(value: 'low' | 'medium' | 'high' | 'urgent') => setPriority(value)}>
                                                <SelectTrigger id="update_priority">
                                                    <SelectValue placeholder="Select priority" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="low">Low</SelectItem>
                                                    <SelectItem value="medium">Medium</SelectItem>
                                                    <SelectItem value="high">High</SelectItem>
                                                    <SelectItem value="urgent">Urgent</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    </div>

                                    <div className="mt-4">
                                        <Label htmlFor="resolution_notes" className="text-foreground">
                                            Resolution Notes {status === 'resolved' && <span className="text-red-500">*</span>}
                                        </Label>
                                        <Textarea
                                            id="resolution_notes"
                                            placeholder="Describe how this issue was resolved or any updates..."
                                            value={resolutionNotes}
                                            onChange={(e) => setResolutionNotes(e.target.value)}
                                            rows={4}
                                            className="mt-2"
                                        />
                                    </div>
                                </div>
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={confirmUpdate}
                                disabled={status === 'resolved' && !resolutionNotes.trim()}
                            >
                                Update
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </div>
        </AppLayout>
    );
}
