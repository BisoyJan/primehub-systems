import { Head, router } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetDescription,
} from '@/components/ui/sheet';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Separator } from '@/components/ui/separator';
import { Search, X, RefreshCw, Play, Pause, ArrowRight, Eye, Download } from 'lucide-react';
import { useState, useEffect, useRef } from 'react';
import { useDebounce } from '@/hooks/use-debounce';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import activityLogs, { exportMethod } from '@/routes/activity-logs';
import PaginationNav, { PaginationLink } from '@/components/pagination-nav';

interface Activity {
    id: number;
    description: string;
    event: string;
    subject_type: string;
    subject_id: number;
    causer: string;
    properties: {
        old?: Record<string, unknown>;
        attributes?: Record<string, unknown>;
        [key: string]: unknown;
    };
    created_at: string;
    created_at_human: string;
}

interface Props {
    activities: {
        data: Activity[];
        links: PaginationLink[];
        current_page: number;
        last_page: number;
        total: number;
    };
    causers: Record<string, string>;
    filters: {
        search: string;
        event: string;
        causer: string;
    };
}

export default function ActivityLogsIndex({ activities, causers, filters }: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [event, setEvent] = useState(filters.event || 'all');
    const [causer, setCauser] = useState(filters.causer || 'all');
    const [autoRefreshEnabled, setAutoRefreshEnabled] = useState(false);
    const [selectedActivity, setSelectedActivity] = useState<Activity | null>(null);
    const debouncedSearch = useDebounce(search, 300);
    const isUserTyping = useRef(false);

    // Update local state when filters prop changes (e.g., from pagination)
    useEffect(() => {
        if (!isUserTyping.current) {
            setSearch(filters.search || '');
            setEvent(filters.event || 'all');
            setCauser(filters.causer || 'all');
        }
    }, [filters.search, filters.event, filters.causer]);

    useEffect(() => {
        // Only trigger search if user is actively typing (not from pagination or initial load)
        if (isUserTyping.current && debouncedSearch !== filters.search) {
            router.get(
                activityLogs.index().url,
                {
                    search: debouncedSearch,
                    event: event === 'all' ? '' : event,
                    causer: causer === 'all' ? '' : causer,
                },
                { preserveState: true, replace: true },
            );
            isUserTyping.current = false;
        }
    }, [debouncedSearch, filters.search, event, causer]);

    // Auto-refresh every 30 seconds
    useEffect(() => {
        if (!autoRefreshEnabled) return;
        const interval = setInterval(() => {
            router.get(
                activityLogs.index().url,
                {
                    search: debouncedSearch,
                    event: event === 'all' ? '' : event,
                    causer: causer === 'all' ? '' : causer,
                },
                { preserveState: true, preserveScroll: true, replace: true, only: ['activities'] },
            );
        }, 30000);

        return () => clearInterval(interval);
    }, [autoRefreshEnabled, debouncedSearch, event, causer]);

    const handleSearchChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        isUserTyping.current = true;
        setSearch(e.target.value);
    };

    const handleEventChange = (value: string) => {
        setEvent(value);
        router.get(
            activityLogs.index().url,
            { search, event: value === 'all' ? '' : value, causer: causer === 'all' ? '' : causer },
            { preserveState: true, replace: true },
        );
    };

    const handleCauserChange = (value: string) => {
        setCauser(value);
        router.get(
            activityLogs.index().url,
            { search, event: event === 'all' ? '' : event, causer: value === 'all' ? '' : value },
            { preserveState: true, replace: true },
        );
    };

    const clearFilters = () => {
        setSearch('');
        setEvent('all');
        setCauser('all');
        router.get(activityLogs.index().url);
    };

    const handleExport = () => {
        const params = new URLSearchParams();
        if (search) params.set('search', search);
        if (event !== 'all') params.set('event', event);
        if (causer !== 'all') params.set('causer', causer);
        const query = params.toString();
        window.location.href = exportMethod.url() + (query ? `?${query}` : '');
    };

    const getEventColor = (event: string) => {
        switch (event) {
            case 'created': return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300';
            case 'updated': return 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300';
            case 'deleted': return 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300';
            case 'login': return 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300';
            case 'logout': return 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300';
            default: return 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300';
        }
    };

    const formatFieldName = (field: string) =>
        field.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

    const formatValue = (value: unknown): string => {
        if (value === null || value === undefined) return '—';
        if (typeof value === 'boolean') return value ? 'Yes' : 'No';
        if (typeof value === 'object') return JSON.stringify(value, null, 2);
        return String(value);
    };

    const hasChanges = (activity: Activity) => {
        const props = activity.properties;
        return props && (props.old || props.attributes);
    };

    const getChangedFields = (activity: Activity): string[] => {
        const props = activity.properties;
        if (!props) return [];
        if (props.old && props.attributes) {
            return [...new Set([...Object.keys(props.old), ...Object.keys(props.attributes)])];
        }
        if (props.attributes) return Object.keys(props.attributes);
        if (props.old) return Object.keys(props.old);
        return [];
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Activity Logs', href: activityLogs.index().url }]}>
            <Head title="Activity Logs" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-2xl font-bold tracking-tight">Activity Logs</h2>
                        <p className="text-muted-foreground">
                            Track user actions and system events.
                        </p>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <div className="flex flex-col gap-4">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div className="relative flex-1 sm:max-w-sm">
                                    <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                    <Input
                                        placeholder="Search logs..."
                                        className="pl-8"
                                        value={search}
                                        onChange={handleSearchChange}
                                    />
                                </div>
                                <div className="flex items-center gap-1">
                                    <Button variant="ghost" size="icon" onClick={() => router.reload({ only: ['activities'] })} title="Refresh">
                                        <RefreshCw className="h-4 w-4" />
                                    </Button>
                                    <Button
                                        variant={autoRefreshEnabled ? 'default' : 'ghost'}
                                        size="icon"
                                        onClick={() => setAutoRefreshEnabled(!autoRefreshEnabled)}
                                        title={autoRefreshEnabled ? 'Disable auto-refresh' : 'Enable auto-refresh (30s)'}
                                    >
                                        {autoRefreshEnabled ? <Pause className="h-4 w-4" /> : <Play className="h-4 w-4" />}
                                    </Button>
                                    <Button variant="ghost" size="icon" onClick={handleExport} title="Export CSV">
                                        <Download className="h-4 w-4" />
                                    </Button>
                                </div>
                            </div>
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                                <Select value={event} onValueChange={handleEventChange}>
                                    <SelectTrigger className="w-full sm:w-[160px]">
                                        <SelectValue placeholder="Filter by Event" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Events</SelectItem>
                                        <SelectItem value="created">Created</SelectItem>
                                        <SelectItem value="updated">Updated</SelectItem>
                                        <SelectItem value="deleted">Deleted</SelectItem>
                                        <SelectItem value="login">Login</SelectItem>
                                        <SelectItem value="logout">Logout</SelectItem>
                                    </SelectContent>
                                </Select>
                                <Select value={causer} onValueChange={handleCauserChange}>
                                    <SelectTrigger className="w-full sm:w-[200px]">
                                        <SelectValue placeholder="Filter by User" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Users</SelectItem>
                                        {Object.entries(causers).map(([id, name]) => (
                                            <SelectItem key={id} value={name}>
                                                {name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {(search || event !== 'all' || causer !== 'all') && (
                                    <Button variant="ghost" size="icon" onClick={clearFilters} title="Clear filters">
                                        <X className="h-4 w-4" />
                                    </Button>
                                )}
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {/* Desktop Table View */}
                        <div className="hidden md:block rounded-md border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>User</TableHead>
                                        <TableHead>Event</TableHead>
                                        <TableHead>Subject</TableHead>
                                        <TableHead>Description</TableHead>
                                        <TableHead>Changes</TableHead>
                                        <TableHead>Date</TableHead>
                                        <TableHead className="w-[50px]" />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {activities.data.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={7} className="h-24 text-center">
                                                No logs found.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        activities.data.map((activity) => (
                                            <TableRow
                                                key={activity.id}
                                                className="cursor-pointer hover:bg-muted/50"
                                                onClick={() => setSelectedActivity(activity)}
                                            >
                                                <TableCell className="font-medium">{activity.causer}</TableCell>
                                                <TableCell>
                                                    <Badge variant="outline" className={getEventColor(activity.event)}>
                                                        {activity.event}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    {activity.subject_type} #{activity.subject_id}
                                                </TableCell>
                                                <TableCell className="max-w-xs truncate" title={activity.description}>
                                                    {activity.description}
                                                </TableCell>
                                                <TableCell>
                                                    {hasChanges(activity) ? (
                                                        <span className="text-xs text-muted-foreground">
                                                            {getChangedFields(activity).length} field{getChangedFields(activity).length !== 1 ? 's' : ''}
                                                        </span>
                                                    ) : (
                                                        <span className="text-xs text-muted-foreground">—</span>
                                                    )}
                                                </TableCell>
                                                <TableCell className="whitespace-nowrap text-muted-foreground">
                                                    <div className="flex flex-col">
                                                        <span>{activity.created_at}</span>
                                                        <span className="text-xs">{activity.created_at_human}</span>
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <Button variant="ghost" size="icon" className="h-8 w-8" title="View details">
                                                        <Eye className="h-4 w-4" />
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </div>

                        {/* Mobile Card View */}
                        <div className="md:hidden space-y-4">
                            {activities.data.length === 0 ? (
                                <div className="py-12 text-center text-muted-foreground border rounded-lg">
                                    No logs found.
                                </div>
                            ) : (
                                activities.data.map((activity) => (
                                    <div
                                        key={activity.id}
                                        className="border rounded-lg p-4 space-y-3 cursor-pointer hover:bg-muted/50 transition-colors"
                                        onClick={() => setSelectedActivity(activity)}
                                    >
                                        <div className="flex justify-between items-start">
                                            <div>
                                                <div className="font-semibold">{activity.causer}</div>
                                                <div className="text-xs text-muted-foreground">
                                                    {activity.subject_type} #{activity.subject_id}
                                                </div>
                                            </div>
                                            <Badge variant="outline" className={getEventColor(activity.event)}>
                                                {activity.event}
                                            </Badge>
                                        </div>
                                        <div className="text-sm">
                                            <span className="font-medium">Description:</span>{' '}
                                            <span className="text-muted-foreground">{activity.description}</span>
                                        </div>
                                        {hasChanges(activity) && (
                                            <div className="text-xs text-muted-foreground">
                                                {getChangedFields(activity).length} field{getChangedFields(activity).length !== 1 ? 's' : ''} changed — tap for details
                                            </div>
                                        )}
                                        <div className="text-xs text-muted-foreground pt-2 border-t">
                                            <div>{activity.created_at}</div>
                                            <div>{activity.created_at_human}</div>
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>

                        <div className="mt-4 flex flex-col sm:flex-row items-center justify-between gap-2">
                            <div className="text-sm text-muted-foreground">
                                Showing {activities.data.length} of {activities.total} results
                            </div>
                            <PaginationNav links={activities.links} />
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* Activity Detail Sheet */}
            <Sheet open={!!selectedActivity} onOpenChange={(open) => !open && setSelectedActivity(null)}>
                <SheetContent className="w-full sm:max-w-lg">
                    <SheetHeader>
                        <SheetTitle className="flex items-center gap-2">
                            Activity Detail
                            {selectedActivity && (
                                <Badge variant="outline" className={getEventColor(selectedActivity.event)}>
                                    {selectedActivity.event}
                                </Badge>
                            )}
                        </SheetTitle>
                        <SheetDescription>
                            {selectedActivity?.description}
                        </SheetDescription>
                    </SheetHeader>

                    {selectedActivity && (
                        <ScrollArea className="h-[calc(100vh-10rem)] pr-4">
                            <div className="space-y-6 px-4 pb-6">
                                {/* Summary Info */}
                                <div className="grid grid-cols-2 gap-4 text-sm">
                                    <div>
                                        <div className="font-medium text-muted-foreground">User</div>
                                        <div className="mt-1">{selectedActivity.causer}</div>
                                    </div>
                                    <div>
                                        <div className="font-medium text-muted-foreground">Subject</div>
                                        <div className="mt-1">{selectedActivity.subject_type} #{selectedActivity.subject_id}</div>
                                    </div>
                                    <div>
                                        <div className="font-medium text-muted-foreground">Date</div>
                                        <div className="mt-1">{selectedActivity.created_at}</div>
                                    </div>
                                    <div>
                                        <div className="font-medium text-muted-foreground">Time Ago</div>
                                        <div className="mt-1">{selectedActivity.created_at_human}</div>
                                    </div>
                                </div>

                                <Separator />

                                {/* Updated Event — Diff View */}
                                {selectedActivity.event === 'updated' && selectedActivity.properties.old && selectedActivity.properties.attributes && (
                                    <div className="space-y-3">
                                        <h4 className="text-sm font-semibold">Changes Made</h4>
                                        <div className="space-y-2">
                                            {getChangedFields(selectedActivity).map((field) => {
                                                const oldVal = selectedActivity.properties.old?.[field];
                                                const newVal = selectedActivity.properties.attributes?.[field];
                                                const wasChanged = formatValue(oldVal) !== formatValue(newVal);

                                                return (
                                                    <div key={field} className="rounded-lg border p-3 space-y-1.5">
                                                        <div className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                                                            {formatFieldName(field)}
                                                        </div>
                                                        {wasChanged ? (
                                                            <div className="flex items-start gap-2 text-sm">
                                                                <div className="flex-1 rounded bg-red-50 px-2 py-1 dark:bg-red-950/30">
                                                                    <span className="text-xs font-medium text-red-600 dark:text-red-400">Old</span>
                                                                    <pre className="mt-0.5 whitespace-pre-wrap break-all text-red-800 dark:text-red-300 font-mono text-xs">
                                                                        {formatValue(oldVal)}
                                                                    </pre>
                                                                </div>
                                                                <ArrowRight className="mt-2 h-4 w-4 shrink-0 text-muted-foreground" />
                                                                <div className="flex-1 rounded bg-green-50 px-2 py-1 dark:bg-green-950/30">
                                                                    <span className="text-xs font-medium text-green-600 dark:text-green-400">New</span>
                                                                    <pre className="mt-0.5 whitespace-pre-wrap break-all text-green-800 dark:text-green-300 font-mono text-xs">
                                                                        {formatValue(newVal)}
                                                                    </pre>
                                                                </div>
                                                            </div>
                                                        ) : (
                                                            <div className="text-sm text-muted-foreground">
                                                                No change: {formatValue(oldVal)}
                                                            </div>
                                                        )}
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </div>
                                )}

                                {/* Created Event — Show All Attributes */}
                                {selectedActivity.event === 'created' && selectedActivity.properties.attributes && (
                                    <div className="space-y-3">
                                        <h4 className="text-sm font-semibold">Created With</h4>
                                        <div className="rounded-lg border overflow-hidden">
                                            <Table>
                                                <TableHeader>
                                                    <TableRow>
                                                        <TableHead className="text-xs">Field</TableHead>
                                                        <TableHead className="text-xs">Value</TableHead>
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody>
                                                    {Object.entries(selectedActivity.properties.attributes).map(([key, value]) => (
                                                        <TableRow key={key}>
                                                            <TableCell className="text-xs font-medium">{formatFieldName(key)}</TableCell>
                                                            <TableCell className="text-xs font-mono break-all">{formatValue(value)}</TableCell>
                                                        </TableRow>
                                                    ))}
                                                </TableBody>
                                            </Table>
                                        </div>
                                    </div>
                                )}

                                {/* Deleted Event — Show Deleted Data */}
                                {selectedActivity.event === 'deleted' && selectedActivity.properties.old && (
                                    <div className="space-y-3">
                                        <h4 className="text-sm font-semibold">Deleted Data</h4>
                                        <div className="rounded-lg border overflow-hidden">
                                            <Table>
                                                <TableHeader>
                                                    <TableRow>
                                                        <TableHead className="text-xs">Field</TableHead>
                                                        <TableHead className="text-xs">Value</TableHead>
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody>
                                                    {Object.entries(selectedActivity.properties.old).map(([key, value]) => (
                                                        <TableRow key={key}>
                                                            <TableCell className="text-xs font-medium">{formatFieldName(key)}</TableCell>
                                                            <TableCell className="text-xs font-mono break-all">{formatValue(value)}</TableCell>
                                                        </TableRow>
                                                    ))}
                                                </TableBody>
                                            </Table>
                                        </div>
                                    </div>
                                )}

                                {/* Login/Logout or Other Events — Raw Properties */}
                                {!hasChanges(selectedActivity) && (
                                    <div className="space-y-3">
                                        <h4 className="text-sm font-semibold">Properties</h4>
                                        {Object.keys(selectedActivity.properties).length > 0 ? (
                                            <pre className="rounded-lg border bg-muted/50 p-3 text-xs font-mono whitespace-pre-wrap break-all">
                                                {JSON.stringify(selectedActivity.properties, null, 2)}
                                            </pre>
                                        ) : (
                                            <p className="text-sm text-muted-foreground">No additional data recorded.</p>
                                        )}
                                    </div>
                                )}
                            </div>
                        </ScrollArea>
                    )}
                </SheetContent>
            </Sheet>
        </AppLayout>
    );
}
