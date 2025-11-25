import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import AppLayout from '@/layouts/app-layout';
import { PageHeader } from '@/components/PageHeader';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Bell, Check, Trash2, RefreshCw } from 'lucide-react';
import { cn } from '@/lib/utils';
import { toast } from 'sonner';
import type { BreadcrumbItem } from '@/types';

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
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    unreadCount: number;
}

export default function NotificationsIndex({ notifications, unreadCount }: PageProps) {
    const [lastRefresh, setLastRefresh] = useState<Date>(new Date());

    const handleManualRefresh = () => {
        router.reload({
            only: ['notifications', 'unreadCount'],
            onSuccess: () => setLastRefresh(new Date()),
        });
    };

    const handleMarkAsRead = async (notificationId: number) => {
        try {
            await fetch(`/notifications/${notificationId}/read`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
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
            await fetch('/notifications/mark-all-read', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
                },
            });
            toast.success('All notifications marked as read');
            router.reload({ only: ['notifications', 'unreadCount'] });
        } catch (error) {
            console.error('Failed to mark all as read:', error);
            toast.error('Failed to mark all as read');
        }
    };

    const handleDelete = async (notificationId: number) => {
        toast.promise(
            fetch(`/notifications/${notificationId}`, {
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

    const getNotificationColor = (type: string) => {
        const colors: Record<string, string> = {
            maintenance_due: 'bg-orange-500',
            leave_request: 'bg-blue-500',
            it_concern: 'bg-red-500',
            medication_request: 'bg-purple-500',
            pc_assignment: 'bg-green-500',
            system: 'bg-gray-500',
        };
        return colors[type] || 'bg-gray-500';
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notifications" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                <PageHeader
                    title="Notifications"
                    description="View and manage your notifications"
                    actions={
                        <div className="flex gap-2">
                            <Button variant="ghost" onClick={handleManualRefresh} size="sm">
                                <RefreshCw className="h-4 w-4 mr-2" />
                                Refresh
                            </Button>
                            {unreadCount > 0 && (
                                <Button onClick={handleMarkAllAsRead} size="sm">
                                    <Check className="h-4 w-4 mr-2" />
                                    Mark all as read
                                </Button>
                            )}
                        </div>
                    }
                />

                {notifications.data.length === 0 ? (
                    <Card className="flex flex-col items-center justify-center py-16">
                        <Bell className="h-16 w-16 text-muted-foreground/50 mb-4" />
                        <p className="text-lg font-medium text-muted-foreground">No notifications</p>
                        <p className="text-sm text-muted-foreground">You're all caught up!</p>
                    </Card>
                ) : (
                    <div className="space-y-2">
                        {notifications.data.map((notification) => (
                            <Card
                                key={notification.id}
                                className={cn(
                                    'p-4 transition-colors hover:bg-muted/50',
                                    !notification.read_at && 'border-l-4 border-l-blue-500 bg-blue-50/50 dark:bg-blue-950/10'
                                )}
                            >
                                <div className="flex gap-4">
                                    <div className={cn('h-10 w-10 rounded-full flex items-center justify-center shrink-0', getNotificationColor(notification.type))}>
                                        <Bell className="h-5 w-5 text-white" />
                                    </div>
                                    <div className="flex-1 space-y-1">
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="flex-1">
                                                <div className="flex items-center gap-2">
                                                    <p className="font-semibold">{notification.title}</p>
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
                                                        onClick={() => handleMarkAsRead(notification.id)}
                                                    >
                                                        <Check className="h-4 w-4" />
                                                    </Button>
                                                )}
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => handleDelete(notification.id)}
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
                )}

                {/* Pagination if needed */}
                {notifications.last_page > 1 && (
                    <div className="flex justify-center gap-2 mt-4">
                        {Array.from({ length: notifications.last_page }, (_, i) => i + 1).map((page) => (
                            <Button
                                key={page}
                                variant={page === notifications.current_page ? 'default' : 'outline'}
                                size="sm"
                                onClick={() => router.visit(`/notifications?page=${page}`)}
                            >
                                {page}
                            </Button>
                        ))}
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
