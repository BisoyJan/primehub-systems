import React, { useState, useEffect, useRef } from 'react';
import { Head, router } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import AppLayout from '@/layouts/app-layout';
import { PageHeader } from '@/components/PageHeader';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Bell, Check, Trash2, RefreshCw, Play, Pause, Send, X, BarChart3 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { toast } from 'sonner';
import type { BreadcrumbItem } from '@/types';
import notificationRoutes from '@/routes/notifications';
import { send as notificationsSendRoute } from '@/routes/notifications';
import { index as notificationAnalyticsRoute } from '@/routes/notification-analytics';
import PaginationNav, { PaginationLink } from '@/components/pagination-nav';
import { Can } from '@/components/authorization';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Notifications', href: '/notifications' }
];

interface Notification {
    id: number;
    type: string;
    title: string;
    message: string;
    data: Record<string, unknown> | null;
    read_at: string | null;
    created_at: string;
}

interface PageProps {
    notifications: {
        data: Notification[];
        links: PaginationLink[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    unreadCount: number;
    availableTypes: string[];
    filters: {
        type: string;
        status: string;
    };
}

export default function NotificationsIndex({ notifications, unreadCount, availableTypes, filters }: PageProps) {
    const [lastRefresh, setLastRefresh] = useState<Date>(new Date());
    const [autoRefreshEnabled, setAutoRefreshEnabled] = useState(false);

    const handleManualRefresh = () => {
        router.reload({
            only: ['notifications', 'unreadCount'],
            onSuccess: () => setLastRefresh(new Date()),
        });
    };

    // Auto-refresh every 30 seconds
    const isPollingRef = useRef(false);
    useEffect(() => {
        if (!autoRefreshEnabled) return;
        const interval = setInterval(() => {
            if (isPollingRef.current) return;
            isPollingRef.current = true;
            router.reload({
                only: ['notifications', 'unreadCount'],
                onSuccess: () => setLastRefresh(new Date()),
                onFinish: () => { isPollingRef.current = false; },
            });
        }, 30000);

        return () => clearInterval(interval);
    }, [autoRefreshEnabled]);

    const handleFilterChange = (key: 'type' | 'status', value: string) => {
        router.get('/notifications', {
            ...filters,
            [key]: value === 'all' ? '' : value,
        }, {
            preserveState: true,
            preserveScroll: true,
            only: ['notifications', 'unreadCount', 'availableTypes', 'filters'],
        });
    };

    const handleClearFilters = () => {
        router.get('/notifications', {}, {
            preserveState: true,
            preserveScroll: true,
            only: ['notifications', 'unreadCount', 'availableTypes', 'filters'],
        });
    };

    const hasActiveFilters = filters.type !== '' || filters.status !== '';

    const handleMarkAsRead = async (notificationId: number) => {
        try {
            await fetch(notificationRoutes.markAsRead.url(notificationId), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            toast.success('Notification marked as read');
            router.reload({ only: ['notifications', 'unreadCount'] });
        } catch (error) {
            console.error('Failed to mark notification as read:', error);
            toast.error('Failed to mark notification as read');
        }
    };

    const handleMarkAllAsRead = async () => {
        try {
            await fetch(notificationRoutes.markAllRead.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            toast.success('All notifications marked as read');
            router.reload({ only: ['notifications', 'unreadCount'] });
        } catch (error) {
            console.error('Failed to mark all as read:', error);
            toast.error('Failed to mark all as read');
        }
    };

    const handleDeleteAll = async () => {
        toast('Are you sure you want to clear all notifications?', {
            description: 'This action cannot be undone.',
            action: {
                label: 'Clear All',
                onClick: () => {
                    toast.promise(
                        fetch(notificationRoutes.deleteAll.url(), {
                            method: 'DELETE',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
                            },
                        }).then(() => {
                            router.reload({ only: ['notifications', 'unreadCount'] });
                        }),
                        {
                            loading: 'Clearing all notifications...',
                            success: 'All notifications cleared',
                            error: 'Failed to clear notifications',
                        }
                    );
                },
            },
            cancel: {
                label: 'Cancel',
                onClick: () => { },
            },
        });
    };

    const handleDelete = async (notificationId: number) => {
        toast.promise(
            fetch(notificationRoutes.destroy.url(notificationId), {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
                },
            }).then(() => {
                router.reload({ only: ['notifications', 'unreadCount'] });
            }),
            {
                loading: 'Deleting notification...',
                success: 'Notification deleted',
                error: 'Failed to delete notification',
            }
        );
    };

    const handleNotificationClick = (notification: Notification) => {
        if (!notification.read_at) {
            handleMarkAsRead(notification.id);
        }

        if (notification.data && typeof notification.data.link === 'string') {
            router.visit(notification.data.link);
        }
    };

    const getNotificationColor = (type: string) => {
        const colors: Record<string, string> = {
            maintenance_due: 'bg-orange-500',
            leave_request: 'bg-blue-500',
            it_concern: 'bg-red-500',
            medication_request: 'bg-purple-500',
            pc_assignment: 'bg-green-500',
            system: 'bg-gray-500',
            attendance_status: 'bg-yellow-500',
            announcement: 'bg-purple-500',
            reminder: 'bg-amber-500',
            alert: 'bg-red-500',
            custom: 'bg-indigo-500',
            coaching_session: 'bg-teal-500',
            coaching_acknowledged: 'bg-teal-500',
            coaching_reviewed: 'bg-teal-500',
            coaching_ready_for_review: 'bg-teal-500',
            coaching_pending_reminder: 'bg-amber-500',
            coaching_unacknowledged_alert: 'bg-red-500',
            break_overage: 'bg-rose-500',
            undertime_approval: 'bg-yellow-600',
            account_deletion: 'bg-red-600',
            account_reactivation: 'bg-green-600',
            account_restored: 'bg-green-500',
        };
        return colors[type] || 'bg-gray-500';
    };

    const getNotificationTypeLabel = (type: string) => {
        const labels: Record<string, string> = {
            maintenance_due: 'Maintenance',
            leave_request: 'Leave Request',
            it_concern: 'IT Concern',
            medication_request: 'Medication',
            pc_assignment: 'PC Assignment',
            system: 'System',
            attendance_status: 'Attendance',
            announcement: 'Announcement',
            reminder: 'Reminder',
            alert: 'Alert',
            custom: 'Custom',
            coaching_session: 'Coaching',
            coaching_acknowledged: 'Coaching',
            coaching_reviewed: 'Coaching',
            coaching_ready_for_review: 'Coaching',
            coaching_pending_reminder: 'Coaching',
            coaching_unacknowledged_alert: 'Coaching',
            break_overage: 'Break Overage',
            undertime_approval: 'Undertime',
            account_deletion: 'Account',
            account_reactivation: 'Account',
            account_restored: 'Account',
        };
        return labels[type] || 'Notification';
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notifications" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                <PageHeader
                    title="Notifications"
                    description="View and manage your notifications"
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Can permission="notifications.send">
                                <Button onClick={() => router.visit(notificationsSendRoute().url)}>
                                    <Send className="h-4 w-4 mr-2" />
                                    Send Notification
                                </Button>
                                <Button variant="outline" onClick={() => router.visit(notificationAnalyticsRoute().url)}>
                                    <BarChart3 className="h-4 w-4 mr-2" />
                                    Analytics
                                </Button>
                            </Can>
                            <div className="flex gap-2">
                                <Button variant="ghost" size="icon" onClick={handleManualRefresh} title="Refresh">
                                    <RefreshCw className="h-4 w-4" />
                                </Button>
                                <Button
                                    variant={autoRefreshEnabled ? "default" : "ghost"}
                                    size="icon"
                                    onClick={() => setAutoRefreshEnabled(!autoRefreshEnabled)}
                                    title={autoRefreshEnabled ? "Disable auto-refresh" : "Enable auto-refresh (30s)"}
                                >
                                    {autoRefreshEnabled ? <Pause className="h-4 w-4" /> : <Play className="h-4 w-4" />}
                                </Button>
                            </div>
                            {notifications.data.length > 0 && (
                                <Button variant="destructive" onClick={handleDeleteAll} size="sm">
                                    <Trash2 className="h-4 w-4 mr-2" />
                                    Clear All
                                </Button>
                            )}
                            {unreadCount > 0 && (
                                <Button onClick={handleMarkAllAsRead} size="sm">
                                    <Check className="h-4 w-4 mr-2" />
                                    Mark all as read
                                </Button>
                            )}
                        </div>
                    }
                />

                {/* Filter Bar */}
                <div className="flex flex-col sm:flex-row gap-2 items-start sm:items-center">
                    <div className="flex flex-wrap gap-2 flex-1">
                        <Select
                            value={filters.type || 'all'}
                            onValueChange={(value) => handleFilterChange('type', value)}
                        >
                            <SelectTrigger className="w-full sm:w-[180px]">
                                <SelectValue placeholder="All Types" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Types</SelectItem>
                                {availableTypes.map((type) => (
                                    <SelectItem key={type} value={type}>
                                        {getNotificationTypeLabel(type)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select
                            value={filters.status || 'all'}
                            onValueChange={(value) => handleFilterChange('status', value)}
                        >
                            <SelectTrigger className="w-full sm:w-[150px]">
                                <SelectValue placeholder="All Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Status</SelectItem>
                                <SelectItem value="unread">Unread</SelectItem>
                                <SelectItem value="read">Read</SelectItem>
                            </SelectContent>
                        </Select>

                        {hasActiveFilters && (
                            <Button variant="ghost" size="sm" onClick={handleClearFilters}>
                                <X className="h-4 w-4 mr-1" />
                                Clear
                            </Button>
                        )}
                    </div>
                </div>

                {notifications.data.length === 0 ? (
                    <Card className="flex flex-col items-center justify-center py-16">
                        <Bell className="h-16 w-16 text-muted-foreground/50 mb-4" />
                        <p className="text-lg font-medium text-muted-foreground">No notifications</p>
                        <p className="text-sm text-muted-foreground">You're all caught up!</p>
                    </Card>
                ) : (
                    <>
                        {/* Desktop View */}
                        <div className="hidden md:block space-y-2">
                            {notifications.data.map((notification) => (
                                <Card
                                    key={notification.id}
                                    className={cn(
                                        'p-4 transition-colors hover:bg-muted/50 cursor-pointer',
                                        !notification.read_at && 'border-l-4 border-l-blue-500 bg-blue-50/50 dark:bg-blue-950/10'
                                    )}
                                    onClick={() => handleNotificationClick(notification)}
                                >
                                    <div className="flex gap-4">
                                        <div className={cn('h-10 w-10 rounded-full flex items-center justify-center shrink-0', getNotificationColor(notification.type))}>
                                            <Bell className="h-5 w-5 text-white" />
                                        </div>
                                        <div className="flex-1 space-y-1">
                                            <div className="flex items-start justify-between gap-2">
                                                <div className="flex-1">
                                                    <div className="flex items-center gap-2 flex-wrap">
                                                        <p className="font-semibold">{notification.title}</p>
                                                        <Badge variant="outline" className="text-xs">
                                                            {getNotificationTypeLabel(notification.type)}
                                                        </Badge>
                                                        {!notification.read_at && (
                                                            <Badge variant="secondary" className="text-xs">New</Badge>
                                                        )}
                                                    </div>
                                                    <p className="text-sm text-muted-foreground mt-1">
                                                        {notification.message}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground mt-2">
                                                        {formatDistanceToNow(new Date(notification.created_at), {
                                                            addSuffix: true,
                                                        })}
                                                    </p>
                                                </div>
                                                <div className="flex gap-2 shrink-0">
                                                    {!notification.read_at && (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={(e) => {
                                                                e.stopPropagation();
                                                                handleMarkAsRead(notification.id);
                                                            }}
                                                        >
                                                            <Check className="h-4 w-4" />
                                                        </Button>
                                                    )}
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            handleDelete(notification.id);
                                                        }}
                                                    >
                                                        <Trash2 className="h-4 w-4 text-destructive" />
                                                    </Button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </Card>
                            ))}
                        </div>

                        {/* Mobile Card View */}
                        <div className="md:hidden space-y-3">
                            {notifications.data.map((notification) => (
                                <div
                                    key={notification.id}
                                    className={cn(
                                        'bg-card border rounded-lg p-4 shadow-sm space-y-3 cursor-pointer',
                                        !notification.read_at && 'border-l-4 border-l-blue-500 bg-blue-50/50 dark:bg-blue-950/10'
                                    )}
                                    onClick={() => handleNotificationClick(notification)}
                                >
                                    <div className="flex items-start gap-3">
                                        <div className={cn('h-8 w-8 rounded-full flex items-center justify-center shrink-0', getNotificationColor(notification.type))}>
                                            <Bell className="h-4 w-4 text-white" />
                                        </div>
                                        <div className="flex-1 min-w-0">
                                            <div className="flex items-center gap-2 flex-wrap">
                                                <p className="font-semibold text-sm">{notification.title}</p>
                                                {!notification.read_at && (
                                                    <Badge variant="secondary" className="text-xs">New</Badge>
                                                )}
                                            </div>
                                            <Badge variant="outline" className="text-xs mt-1">
                                                {getNotificationTypeLabel(notification.type)}
                                            </Badge>
                                        </div>
                                    </div>
                                    <p className="text-sm text-muted-foreground line-clamp-3">
                                        {notification.message}
                                    </p>
                                    <div className="flex items-center justify-between">
                                        <p className="text-xs text-muted-foreground">
                                            {formatDistanceToNow(new Date(notification.created_at), {
                                                addSuffix: true,
                                            })}
                                        </p>
                                        <div className="flex gap-1">
                                            {!notification.read_at && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-7 px-2"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        handleMarkAsRead(notification.id);
                                                    }}
                                                >
                                                    <Check className="h-3 w-3" />
                                                </Button>
                                            )}
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="h-7 px-2"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    handleDelete(notification.id);
                                                }}
                                            >
                                                <Trash2 className="h-3 w-3 text-destructive" />
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </>
                )}

                {/* Pagination */}
                {notifications.links && notifications.links.length > 0 && (
                    <div className="flex justify-center mt-4">
                        <PaginationNav links={notifications.links} />
                    </div>
                )}

                <div className="flex justify-between items-center text-sm text-muted-foreground mt-4">
                    <div>
                        Showing {notifications.data.length} of {notifications.total} notifications
                    </div>
                    <div className="text-xs">Last updated: {lastRefresh.toLocaleTimeString()}</div>
                </div>
            </div>
        </AppLayout>
    );
}
