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
import { Search, X } from 'lucide-react';
import { useState, useEffect, useRef } from 'react';
import { useDebounce } from '@/hooks/use-debounce';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import activityLogs from '@/routes/activity-logs';
import PaginationNav, { PaginationLink } from '@/components/pagination-nav';

interface Activity {
    id: number;
    description: string;
    event: string;
    subject_type: string;
    subject_id: number;
    causer: string;
    properties: Record<string, unknown>;
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
    filters: {
        search: string;
        event: string;
        causer: string;
    };
}

export default function ActivityLogsIndex({ activities, filters }: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [event, setEvent] = useState(filters.event || 'all');
    const debouncedSearch = useDebounce(search, 300);
    const isUserTyping = useRef(false);

    // Update local state when filters prop changes (e.g., from pagination)
    useEffect(() => {
        if (!isUserTyping.current) {
            setSearch(filters.search || '');
            setEvent(filters.event || 'all');
        }
    }, [filters.search, filters.event]);

    useEffect(() => {
        // Only trigger search if user is actively typing (not from pagination or initial load)
        if (isUserTyping.current && debouncedSearch !== filters.search) {
            router.get(
                activityLogs.index().url,
                { search: debouncedSearch, event: event === 'all' ? '' : event },
                { preserveState: true, replace: true }
            );
            isUserTyping.current = false;
        }
    }, [debouncedSearch, filters.search, event]);

    // Auto-refresh every 30 seconds
    useEffect(() => {
        const interval = setInterval(() => {
            router.get(
                activityLogs.index().url,
                { search: debouncedSearch, event: event === 'all' ? '' : event },
                { preserveState: true, preserveScroll: true, replace: true, only: ['activities'] }
            );
        }, 30000);

        return () => clearInterval(interval);
    }, [debouncedSearch, event]);

    const handleSearchChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        isUserTyping.current = true;
        setSearch(e.target.value);
    };

    const handleEventChange = (value: string) => {
        setEvent(value);
        router.get(
            activityLogs.index().url,
            { search, event: value === 'all' ? '' : value },
            { preserveState: true, replace: true }
        );
    };

    const clearFilters = () => {
        setSearch('');
        setEvent('all');
        router.get(activityLogs.index().url);
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
                        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                            <div className="flex flex-1 items-center gap-2">
                                <div className="relative flex-1 md:max-w-sm">
                                    <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                    <Input
                                        placeholder="Search logs..."
                                        className="pl-8"
                                        value={search}
                                        onChange={handleSearchChange}
                                    />
                                </div>
                                <Select value={event} onValueChange={handleEventChange}>
                                    <SelectTrigger className="w-[180px]">
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
                                {(search || event !== 'all') && (
                                    <Button variant="ghost" size="icon" onClick={clearFilters}>
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
                                        <TableHead>Date</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {activities.data.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={5} className="h-24 text-center">
                                                No logs found.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        activities.data.map((activity) => (
                                            <TableRow key={activity.id}>
                                                <TableCell className="font-medium">{activity.causer}</TableCell>
                                                <TableCell>
                                                    <Badge variant="outline" className={getEventColor(activity.event)}>
                                                        {activity.event}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    {activity.subject_type} #{activity.subject_id}
                                                </TableCell>
                                                <TableCell className="max-w-md truncate" title={activity.description}>
                                                    {activity.description}
                                                </TableCell>
                                                <TableCell className="whitespace-nowrap text-muted-foreground">
                                                    <div className="flex flex-col">
                                                        <span>{activity.created_at}</span>
                                                        <span className="text-xs">{activity.created_at_human}</span>
                                                    </div>
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
                                    <div key={activity.id} className="border rounded-lg p-4 space-y-3">
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
        </AppLayout>
    );
}
