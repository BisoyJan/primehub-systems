import React from 'react';
import { formatDistanceToNow } from 'date-fns';
import { Bell, Check, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';

interface Notification {
    id: number;
    type: string;
    title: string;
    message: string;
    data: Record<string, unknown> | null;
    read_at: string | null;
    created_at: string;
}

interface NotificationDropdownProps {
    notifications: Notification[];
    unreadCount: number;
    onMarkAsRead: (id: number) => void;
    onMarkAllAsRead: () => void;
    onDelete: (id: number) => void;
    onDeleteAll: () => void;
    onViewAll: () => void;
    onNotificationClick: (notification: Notification) => void;
}

export function NotificationDropdown({
    notifications,
    unreadCount,
    onMarkAsRead,
    onMarkAllAsRead,
    onDelete,
    onDeleteAll,
    onViewAll,
    onNotificationClick,
}: NotificationDropdownProps) {
    const getNotificationIcon = () => {
        // Return appropriate icon based on notification type
        return <Bell className="h-4 w-4" />;
    };

    const getNotificationColor = (type: string) => {
        const colors: Record<string, string> = {
            maintenance_due: 'text-orange-500',
            leave_request: 'text-blue-500',
            it_concern: 'text-red-500',
            medication_request: 'text-purple-500',
            pc_assignment: 'text-green-500',
            system: 'text-gray-500',
            attendance_status: 'text-yellow-500',
        };
        return colors[type] || 'text-gray-500';
    };

    return (
        <div className="flex flex-col">
            {/* Header */}
            <div className="flex items-center justify-between px-4 py-3">
                <div>
                    <h3 className="font-semibold text-sm">Notifications</h3>
                    {unreadCount > 0 && (
                        <p className="text-xs text-muted-foreground">
                            {unreadCount} unread
                        </p>
                    )}
                </div>
                {unreadCount > 0 && (
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-7 text-xs"
                        onClick={onMarkAllAsRead}
                    >
                        <Check className="h-3 w-3 mr-1" />
                        Mark all read
                    </Button>
                )}
            </div>

            <Separator />

            {/* Notifications List */}
            <ScrollArea className="h-[400px]">
                {notifications.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-12 text-center">
                        <Bell className="h-12 w-12 text-muted-foreground/50 mb-3" />
                        <p className="text-sm text-muted-foreground">No notifications</p>
                    </div>
                ) : (
                    <div className="divide-y">
                        {notifications.map((notification) => (
                            <div
                                key={notification.id}
                                className={cn(
                                    'group relative px-4 py-3 hover:bg-muted/50 transition-colors cursor-pointer',
                                    !notification.read_at && 'bg-blue-50/50 dark:bg-blue-950/10'
                                )}
                                onClick={() => onNotificationClick(notification)}
                            >
                                <div className="flex gap-3">
                                    <div className={cn('mt-1', getNotificationColor(notification.type))}>
                                        {getNotificationIcon()}
                                    </div>
                                    <div className="flex-1 space-y-1">
                                        <div className="flex items-start justify-between gap-2">
                                            <p className="text-sm font-medium leading-none">
                                                {notification.title}
                                            </p>
                                            {!notification.read_at && (
                                                <span className="h-2 w-2 rounded-full bg-blue-500 shrink-0 mt-1" />
                                            )}
                                        </div>
                                        <p className="text-sm text-muted-foreground line-clamp-2">
                                            {notification.message}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {formatDistanceToNow(new Date(notification.created_at), {
                                                addSuffix: true,
                                            })}
                                        </p>
                                    </div>
                                </div>

                                {/* Action buttons on hover */}
                                <div className="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity flex gap-1">
                                    {!notification.read_at && (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="h-6 w-6"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                onMarkAsRead(notification.id);
                                            }}
                                        >
                                            <Check className="h-3 w-3" />
                                        </Button>
                                    )}
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="h-6 w-6 text-destructive"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            onDelete(notification.id);
                                        }}
                                    >
                                        <X className="h-3 w-3" />
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </ScrollArea>

            {/* Footer */}
            {notifications.length > 0 && (
                <>
                    <Separator />
                    <div className="p-2 grid grid-cols-2 gap-2">
                        <Button
                            variant="ghost"
                            size="sm"
                            className="w-full text-destructive hover:text-destructive hover:bg-destructive/10"
                            onClick={onDeleteAll}
                        >
                            Clear all
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="w-full"
                            onClick={onViewAll}
                        >
                            View all
                        </Button>
                    </div>
                </>
            )}
        </div>
    );
}
