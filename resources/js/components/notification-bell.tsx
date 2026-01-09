import React, { useState, useEffect } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { toast } from 'sonner';
import { NotificationDropdown } from './notification-dropdown';
import { usePermission } from '@/hooks/use-permission';

interface Notification {
    id: number;
    type: string;
    title: string;
    message: string;
    data: Record<string, unknown> | null;
    read_at: string | null;
    created_at: string;
}

// Helper to check if response is a valid JSON response (not session expired/Inertia redirect)
const isValidJsonResponse = (response: Response): boolean => {
    if (response.status === 401 || response.status === 419) {
        return false;
    }
    const contentType = response.headers.get('content-type');
    return contentType !== null && contentType.includes('application/json');
};

// Helper to get common headers for JSON requests
const getJsonHeaders = (): HeadersInit => ({
    'Accept': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
});

export function NotificationBell() {
    const [notifications, setNotifications] = useState<Notification[]>([]);
    const [unreadCount, setUnreadCount] = useState(0);
    const [isOpen, setIsOpen] = useState(false);
    const { can } = usePermission();

    // Fetch notifications when dropdown opens
    const fetchNotifications = async () => {
        try {
            const response = await fetch('/notifications/recent', {
                headers: getJsonHeaders(),
            });

            if (!isValidJsonResponse(response)) {
                return;
            }

            const data = await response.json();
            setNotifications(data.notifications);
            setUnreadCount(data.unreadCount);
        } catch (error) {
            console.error('Failed to fetch notifications:', error);
        }
    };

    // Fetch unread count on mount and periodically
    useEffect(() => {
        fetchUnreadCount();
        const interval = setInterval(fetchUnreadCount, 30000); // Poll every 30 seconds
        return () => clearInterval(interval);
    }, []);

    const fetchUnreadCount = async () => {
        try {
            const response = await fetch('/notifications/unread-count', {
                headers: getJsonHeaders(),
            });

            if (!isValidJsonResponse(response)) {
                return;
            }

            const data = await response.json();
            setUnreadCount(data.count);
        } catch (error) {
            // Silently ignore fetch errors - could be due to session expiration
            console.error('Failed to fetch unread count:', error);
        }
    };

    const handleOpenChange = (open: boolean) => {
        setIsOpen(open);
        if (open) {
            fetchNotifications();
        }
    };

    const handleMarkAsRead = async (notificationId: number) => {
        try {
            const response = await fetch(`/notifications/${notificationId}/read`, {
                method: 'POST',
                headers: getJsonHeaders(),
            });

            if (!isValidJsonResponse(response)) {
                return;
            }

            // Update local state
            setNotifications(prev =>
                prev.map(n => n.id === notificationId ? { ...n, read_at: new Date().toISOString() } : n)
            );
            setUnreadCount(prev => Math.max(0, prev - 1));
            toast.success('Marked as read');
        } catch (error) {
            console.error('Failed to mark notification as read:', error);
            toast.error('Failed to mark as read');
        }
    };

    const handleMarkAllAsRead = async () => {
        try {
            const response = await fetch('/notifications/mark-all-read', {
                method: 'POST',
                headers: getJsonHeaders(),
            });

            if (!isValidJsonResponse(response)) {
                return;
            }

            // Update local state
            setNotifications(prev =>
                prev.map(n => ({ ...n, read_at: new Date().toISOString() }))
            );
            setUnreadCount(0);
            toast.success('All notifications marked as read');
        } catch (error) {
            console.error('Failed to mark all as read:', error);
            toast.error('Failed to mark all as read');
        }
    };

    const handleDeleteAll = async () => {
        try {
            const response = await fetch('/notifications/all', {
                method: 'DELETE',
                headers: getJsonHeaders(),
            });

            if (!isValidJsonResponse(response)) {
                return;
            }

            // Update local state
            setNotifications([]);
            setUnreadCount(0);
            toast.success('All notifications cleared');
        } catch (error) {
            console.error('Failed to clear all notifications:', error);
            toast.error('Failed to clear all notifications');
        }
    };

    const handleDelete = async (notificationId: number) => {
        try {
            const response = await fetch(`/notifications/${notificationId}`, {
                method: 'DELETE',
                headers: getJsonHeaders(),
            });

            if (!isValidJsonResponse(response)) {
                return;
            }

            // Update local state
            const deletedNotification = notifications.find(n => n.id === notificationId);
            setNotifications(prev => prev.filter(n => n.id !== notificationId));
            if (deletedNotification && !deletedNotification.read_at) {
                setUnreadCount(prev => Math.max(0, prev - 1));
            }
            toast.success('Notification deleted');
        } catch (error) {
            console.error('Failed to delete notification:', error);
            toast.error('Failed to delete notification');
        }
    };

    const handleNotificationClick = (notification: Notification) => {
        if (!notification.read_at) {
            handleMarkAsRead(notification.id);
        }

        if (notification.data && typeof notification.data.link === 'string') {
            router.visit(notification.data.link);
            setIsOpen(false);
        }
    };

    const handleViewAll = () => {
        router.visit('/notifications');
        setIsOpen(false);
    };

    // Update document title with unread count
    useEffect(() => {
        const updateTitle = () => {
            const title = document.title;
            const hasNotificationPrefix = /^\(\d+\)\s/.test(title);

            if (unreadCount > 0) {
                const cleanTitle = hasNotificationPrefix ? title.replace(/^\(\d+\)\s/, '') : title;
                if (!title.startsWith(`(${unreadCount})`)) {
                    document.title = `(${unreadCount}) ${cleanTitle}`;
                }
            } else if (hasNotificationPrefix) {
                document.title = title.replace(/^\(\d+\)\s/, '');
            }
        };

        updateTitle();

        // Re-apply title update after navigation
        const removeListener = router.on('finish', () => {
            // Small delay to allow Inertia to update the title first
            setTimeout(updateTitle, 50);
        });

        return () => {
            removeListener();
        };
    }, [unreadCount]);

    return (
        <DropdownMenu open={isOpen} onOpenChange={handleOpenChange}>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="relative h-9 w-9"
                >
                    <Bell className="h-5 w-5" />
                    {unreadCount > 0 && (
                        <span className="absolute -top-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full bg-red-500 text-[10px] font-bold text-white">
                            {unreadCount > 9 ? '9+' : unreadCount}
                        </span>
                    )}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-80">
                <NotificationDropdown
                    notifications={notifications}
                    unreadCount={unreadCount}
                    canSend={can('notifications.send')}
                    onMarkAsRead={handleMarkAsRead}
                    onMarkAllAsRead={handleMarkAllAsRead}
                    onDelete={handleDelete}
                    onDeleteAll={handleDeleteAll}
                    onViewAll={handleViewAll}
                    onNotificationClick={handleNotificationClick}
                />
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
