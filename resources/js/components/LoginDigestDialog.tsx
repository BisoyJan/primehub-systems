import React, { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import { Link } from '@inertiajs/react';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Calendar,
    ClipboardCheck,
    Wrench,
    AlertTriangle,
    Clock,
    Users,
    Pill,
    Package,
    Loader,
    MessageSquare,
    Bell,
    CheckCircle2,
    ArrowRight,
} from 'lucide-react';

export interface LoginDigestItem {
    key: string;
    label: string;
    count: number;
    route: string;
    icon: string;
    priority: 'critical' | 'high' | 'medium' | 'low';
}

export interface LoginDigest {
    greeting: string;
    items: LoginDigestItem[];
    total_actionable: number;
}

const ICON_MAP: Record<string, React.ComponentType<{ className?: string }>> = {
    'calendar': Calendar,
    'clipboard-check': ClipboardCheck,
    'wrench': Wrench,
    'alert-triangle': AlertTriangle,
    'clock': Clock,
    'users': Users,
    'pill': Pill,
    'package': Package,
    'loader': Loader,
    'message-square': MessageSquare,
    'bell': Bell,
};

const PRIORITY_STYLES: Record<string, { badge: string; border: string }> = {
    critical: { badge: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400', border: 'border-l-red-500' },
    high: { badge: 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400', border: 'border-l-orange-500' },
    medium: { badge: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400', border: 'border-l-yellow-500' },
    low: { badge: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400', border: 'border-l-blue-500' },
};

const ROUTE_MAP: Record<string, string> = {
    'leave-requests.index': '/form-requests/leave-requests',
    'coaching.dashboard': '/coaching/dashboard',
    'pc-maintenance.index': '/pc-maintenance',
    'it-concerns.index': '/form-requests/it-concerns',
    'attendance.index': '/attendance/records',
    'medication-requests.index': '/form-requests/medication-requests',
};

const SESSION_KEY = 'login_digest_shown';
const LOCAL_STORAGE_KEY = 'login_digest_dismissed_date';

interface LoginDigestDialogProps {
    digest: LoginDigest | null | undefined;
}

export function LoginDigestDialog({ digest }: LoginDigestDialogProps) {
    const [open, setOpen] = useState(false);
    const [dontShowToday, setDontShowToday] = useState(false);

    useEffect(() => {
        if (!digest || digest.items.length === 0) return;

        // Check if already shown this session
        if (sessionStorage.getItem(SESSION_KEY)) return;

        // Check if dismissed for today
        const dismissedDate = localStorage.getItem(LOCAL_STORAGE_KEY);
        if (dismissedDate === new Date().toISOString().slice(0, 10)) return;

        // Show the dialog
        const timer = setTimeout(() => setOpen(true), 500);
        return () => clearTimeout(timer);
    }, [digest]);

    const handleClose = useCallback(() => {
        setOpen(false);
        sessionStorage.setItem(SESSION_KEY, 'true');

        if (dontShowToday) {
            localStorage.setItem(LOCAL_STORAGE_KEY, new Date().toISOString().slice(0, 10));
        }
    }, [dontShowToday]);

    if (!digest) return null;

    const hasItems = digest.items.length > 0;

    return (
        <Dialog open={open} onOpenChange={(isOpen) => !isOpen && handleClose()}>
            <DialogContent className="max-w-[90vw] sm:max-w-lg max-h-[80vh] overflow-y-auto">
                <motion.div
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.3, type: 'spring', stiffness: 200 }}
                >
                    <DialogHeader>
                        <DialogTitle className="text-lg">{digest.greeting}</DialogTitle>
                        <DialogDescription>
                            {hasItems
                                ? `You have ${digest.total_actionable} item${digest.total_actionable !== 1 ? 's' : ''} that need${digest.total_actionable === 1 ? 's' : ''} your attention.`
                                : "You're all caught up! No pending items."}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="mt-4 space-y-2">
                        {hasItems ? (
                            digest.items.map((item, index) => (
                                <DigestItem key={item.key} item={item} index={index} onNavigate={handleClose} />
                            ))
                        ) : (
                            <div className="flex flex-col items-center justify-center py-8 text-muted-foreground">
                                <CheckCircle2 className="size-12 text-green-500 mb-3" />
                                <p className="text-sm font-medium">All caught up!</p>
                                <p className="text-xs mt-1">No pending items to address.</p>
                            </div>
                        )}
                    </div>

                    <DialogFooter className="mt-4 flex-col gap-3 sm:flex-col">
                        {hasItems && (
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="dont-show-today"
                                    checked={dontShowToday}
                                    onCheckedChange={(checked) => setDontShowToday(checked === true)}
                                />
                                <label htmlFor="dont-show-today" className="text-xs text-muted-foreground cursor-pointer">
                                    Don&apos;t show again today
                                </label>
                            </div>
                        )}
                        <Button variant="outline" onClick={handleClose} className="w-full sm:w-auto">
                            Dismiss
                        </Button>
                    </DialogFooter>
                </motion.div>
            </DialogContent>
        </Dialog>
    );
}

function DigestItem({ item, index, onNavigate }: { item: LoginDigestItem; index: number; onNavigate: () => void }) {
    const IconComponent = ICON_MAP[item.icon] || AlertTriangle;
    const styles = PRIORITY_STYLES[item.priority] || PRIORITY_STYLES.low;
    const href = ROUTE_MAP[item.route] || '/dashboard';

    return (
        <motion.div
            initial={{ opacity: 0, x: -10 }}
            animate={{ opacity: 1, x: 0 }}
            transition={{ delay: index * 0.05, duration: 0.2 }}
        >
            <Link
                href={href}
                onClick={onNavigate}
                className={`flex items-center justify-between gap-3 rounded-lg border border-l-4 ${styles.border} bg-card p-3 transition-colors hover:bg-accent/50 group`}
            >
                <div className="flex items-center gap-3 min-w-0">
                    <div className="flex-shrink-0">
                        <IconComponent className="size-4 text-muted-foreground" />
                    </div>
                    <span className="text-sm font-medium truncate">{item.label}</span>
                </div>
                <div className="flex items-center gap-2 flex-shrink-0">
                    <Badge variant="secondary" className={`text-xs font-semibold ${styles.badge}`}>
                        {item.count}
                    </Badge>
                    <ArrowRight className="size-3.5 text-muted-foreground opacity-0 group-hover:opacity-100 transition-opacity" />
                </div>
            </Link>
        </motion.div>
    );
}
