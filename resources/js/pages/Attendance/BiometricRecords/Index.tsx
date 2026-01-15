import React, { useState, useMemo, useEffect } from "react";
import { Head, router, usePage } from "@inertiajs/react";
import AppLayout from "@/layouts/app-layout";
import { type SharedData } from "@/types";
import { useFlashMessage, usePageLoading, usePageMeta } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { formatTime, formatDateShort } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { DatePicker } from "@/components/ui/date-picker";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue
} from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from "@/components/ui/command";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import PaginationNav, { PaginationLink } from "@/components/pagination-nav";
import { Database, Calendar, Clock, Trash2, Eye, Check, ChevronsUpDown, RefreshCw, Search, Play, Pause } from "lucide-react";
import { index as biometricRecordsIndex, show as biometricRecordsShow } from "@/routes/biometric-records";

interface User {
    id: number;
    name: string;
}

interface Site {
    id: number;
    name: string;
}

interface AttendanceUpload {
    id: number;
    shift_date: string;
}

interface BiometricRecord {
    id: number;
    user: User;
    site: Site;
    attendance_upload: AttendanceUpload;
    employee_name: string;
    datetime: string;
    record_date: string;
    record_time: string;
}

interface BiometricRecordPayload {
    data: BiometricRecord[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number;
    to: number;
}

interface Stats {
    total: number;
    today: number;
    this_week: number;
    this_month: number;
    old_records: number;
    oldest_date: string;
    newest_date: string;
    next_cleanup: string;
}

interface Filters {
    users: User[];
    sites: Site[];
    user_id?: string;
    site_id?: string;
    date_from?: string;
    date_to?: string;
    search?: string;
}

interface PageProps extends SharedData {
    records: BiometricRecordPayload;
    stats: Stats;
    filters: Filters;
    [key: string]: unknown;
}

// formatTime, formatDateShort are now imported from @/lib/utils
const formatDate = formatDateShort; // Alias for backward compatibility

export default function BiometricRecordsIndex() {
    const { records, stats, filters } = usePage<PageProps>().props;

    const { title, breadcrumbs } = usePageMeta({
        title: "Biometric Records",
        breadcrumbs: [{ title: "Biometric Records", href: biometricRecordsIndex().url }],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const [searchTerm, setSearchTerm] = useState(filters?.search || "");
    const [selectedUserId, setSelectedUserId] = useState(filters?.user_id || "");
    const [selectedSiteId, setSelectedSiteId] = useState(filters?.site_id || "");
    const [dateFrom, setDateFrom] = useState(filters?.date_from || "");
    const [dateTo, setDateTo] = useState(filters?.date_to || "");
    const [isEmployeePopoverOpen, setIsEmployeePopoverOpen] = useState(false);
    const [employeeSearchQuery, setEmployeeSearchQuery] = useState("");
    const [lastRefresh, setLastRefresh] = useState<Date>(new Date());
    const [autoRefreshEnabled, setAutoRefreshEnabled] = useState(false);

    // Filter employees based on search query
    const filteredEmployees = useMemo(() => {
        if (!filters?.users) return [];
        if (!employeeSearchQuery) return filters.users;
        return filters.users.filter(user =>
            user.name.toLowerCase().includes(employeeSearchQuery.toLowerCase())
        );
    }, [filters?.users, employeeSearchQuery]);

    // Get the selected employee's name
    const selectedEmployeeName = useMemo(() => {
        if (!selectedUserId || !filters?.users) return "";
        const employee = filters.users.find(user => String(user.id) === selectedUserId);
        return employee?.name || "";
    }, [selectedUserId, filters?.users]);

    const recordsData = {
        data: records?.data || [],
        links: records?.links || [],
        meta: {
            current_page: records?.current_page ?? 1,
            last_page: records?.last_page ?? 1,
            per_page: records?.per_page ?? 50,
            total: records?.total ?? 0,
            from: records?.from ?? 0,
            to: records?.to ?? 0
        }
    };

    const handleFilter = () => {
        router.get(
            biometricRecordsIndex().url,
            {
                search: searchTerm,
                user_id: selectedUserId,
                site_id: selectedSiteId,
                date_from: dateFrom,
                date_to: dateTo
            },
            {
                preserveState: true,
                onSuccess: () => setLastRefresh(new Date())
            }
        );
    };

    const handleManualRefresh = () => {
        handleFilter();
    };

    // Auto-refresh every 30 seconds
    useEffect(() => {
        if (!autoRefreshEnabled) return;
        const interval = setInterval(() => {
            router.get(
                biometricRecordsIndex().url,
                {
                    search: searchTerm,
                    user_id: selectedUserId,
                    site_id: selectedSiteId,
                    date_from: dateFrom,
                    date_to: dateTo
                },
                {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                    only: ['records', 'stats'],
                    onSuccess: () => setLastRefresh(new Date())
                }
            );
        }, 30000);

        return () => clearInterval(interval);
    }, [autoRefreshEnabled, searchTerm, selectedUserId, selectedSiteId, dateFrom, dateTo]);

    const handleReset = () => {
        setSearchTerm("");
        setSelectedUserId("");
        setSelectedSiteId("");
        setDateFrom("");
        setDateTo("");
        router.get(biometricRecordsIndex().url);
    };

    const viewUserRecords = (userId: number, date: string) => {
        router.get(biometricRecordsShow({ user: userId, date }).url);
    };

    const showClearFilters = searchTerm || selectedUserId || selectedSiteId || dateFrom || dateTo;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <LoadingOverlay isLoading={isPageLoading} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3">
                <PageHeader
                    title="Biometric Records"
                    description="View and manage raw biometric fingerprint scan records"
                />

                {/* Statistics Cards */}
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Total Records</CardTitle>
                            <Database className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats?.total.toLocaleString() || 0}</div>
                            <p className="text-xs text-muted-foreground">
                                Across all uploads
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">This Month</CardTitle>
                            <Calendar className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{stats?.this_month.toLocaleString() || 0}</div>
                            <p className="text-xs text-muted-foreground">
                                {stats?.today || 0} today, {stats?.this_week || 0} this week
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Date Range</CardTitle>
                            <Clock className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-sm font-medium">{stats?.oldest_date || 'N/A'}</div>
                            <p className="text-xs text-muted-foreground">
                                to {stats?.newest_date || 'N/A'}
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Auto Cleanup</CardTitle>
                            <Trash2 className="h-4 w-4 text-muted-foreground" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-sm font-medium">{stats?.old_records.toLocaleString() || 0} eligible</div>
                            <p className="text-xs text-muted-foreground">
                                Next: {stats?.next_cleanup || 'N/A'}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <div className="flex flex-col gap-3">
                    <div className="w-full">
                        <Input
                            type="search"
                            placeholder="Search employee name..."
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && handleFilter()}
                            className="w-full"
                        />
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        <Popover open={isEmployeePopoverOpen} onOpenChange={setIsEmployeePopoverOpen}>
                            <PopoverTrigger asChild>
                                <Button
                                    variant="outline"
                                    role="combobox"
                                    aria-expanded={isEmployeePopoverOpen}
                                    className="w-full justify-between font-normal"
                                >
                                    <span className="truncate">
                                        {selectedUserId ? selectedEmployeeName : "All Employees"}
                                    </span>
                                    <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                </Button>
                            </PopoverTrigger>
                            <PopoverContent className="w-full p-0" align="start">
                                <Command shouldFilter={false}>
                                    <CommandInput
                                        placeholder="Search employee..."
                                        value={employeeSearchQuery}
                                        onValueChange={setEmployeeSearchQuery}
                                    />
                                    <CommandList>
                                        <CommandEmpty>No employee found.</CommandEmpty>
                                        <CommandGroup>
                                            <CommandItem
                                                value="all"
                                                onSelect={() => {
                                                    setSelectedUserId("");
                                                    setIsEmployeePopoverOpen(false);
                                                    setEmployeeSearchQuery("");
                                                }}
                                                className="cursor-pointer"
                                            >
                                                <Check
                                                    className={`mr-2 h-4 w-4 ${!selectedUserId ? "opacity-100" : "opacity-0"
                                                        }`}
                                                />
                                                All Employees
                                            </CommandItem>
                                            {filteredEmployees.map((user) => (
                                                <CommandItem
                                                    key={user.id}
                                                    value={user.name}
                                                    onSelect={() => {
                                                        setSelectedUserId(String(user.id));
                                                        setIsEmployeePopoverOpen(false);
                                                        setEmployeeSearchQuery("");
                                                    }}
                                                    className="cursor-pointer"
                                                >
                                                    <Check
                                                        className={`mr-2 h-4 w-4 ${selectedUserId === String(user.id)
                                                            ? "opacity-100"
                                                            : "opacity-0"
                                                            }`}
                                                    />
                                                    {user.name}
                                                </CommandItem>
                                            ))}
                                        </CommandGroup>
                                    </CommandList>
                                </Command>
                            </PopoverContent>
                        </Popover>

                        <Select value={selectedSiteId || undefined} onValueChange={(value) => setSelectedSiteId(value || "")}>
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="All Sites" />
                            </SelectTrigger>
                            <SelectContent>
                                {filters?.sites.map((site) => (
                                    <SelectItem key={site.id} value={String(site.id)}>
                                        {site.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <div className="flex items-center gap-2 text-sm">
                            <span className="text-muted-foreground text-xs">From:</span>
                            <DatePicker
                                value={dateFrom}
                                onChange={(value) => setDateFrom(value)}
                                placeholder="Start date"
                                className="w-full"
                            />
                        </div>

                        <div className="flex items-center gap-2 text-sm">
                            <span className="text-muted-foreground text-xs">To:</span>
                            <DatePicker
                                value={dateTo}
                                onChange={(value) => setDateTo(value)}
                                placeholder="End date"
                                className="w-full"
                            />
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-3 sm:flex-row sm:items-center sm:justify-end">
                        <Button variant="default" onClick={handleFilter} className="flex-1 sm:flex-none">
                            <Search className="mr-2 h-4 w-4" />
                            Apply Filters
                        </Button>

                        {showClearFilters && (
                            <Button variant="outline" onClick={handleReset} className="flex-1 sm:flex-none">
                                Clear Filters
                            </Button>
                        )}

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
                    </div>
                </div>

                <div className="flex flex-col gap-2 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        Showing {recordsData.meta.from} to {recordsData.meta.to} of {recordsData.meta.total} record
                        {recordsData.meta.total === 1 ? "" : "s"}
                        {showClearFilters ? " (filtered)" : ""}
                    </div>
                    <div className="text-xs">
                        Last updated: {lastRefresh.toLocaleTimeString()}
                    </div>
                </div>

                {/* Desktop Table */}
                <div className="hidden md:block shadow rounded-md overflow-hidden">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow className="bg-muted/50">
                                    <TableHead>Date & Time</TableHead>
                                    <TableHead>Employee</TableHead>
                                    <TableHead>Device Name</TableHead>
                                    <TableHead>Site</TableHead>
                                    <TableHead>Upload Date</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {recordsData.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={6} className="h-24 text-center text-muted-foreground">
                                            No biometric records found
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    recordsData.data.map((record) => (
                                        <TableRow key={record.id}>
                                            <TableCell>
                                                <div className="flex flex-col">
                                                    <span className="font-medium">
                                                        {formatDate(record.record_date)}
                                                    </span>
                                                    <span className="text-sm text-muted-foreground">
                                                        {formatTime(record.record_time)}
                                                    </span>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-col">
                                                    <span className="font-medium">{record.user.name}</span>
                                                    {record.employee_name !== record.user.name && (
                                                        <span className="text-xs text-muted-foreground">
                                                            Device: {record.employee_name}
                                                        </span>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell>{record.employee_name}</TableCell>
                                            <TableCell>
                                                <Badge variant="outline">{record.site.name}</Badge>
                                            </TableCell>
                                            <TableCell className="text-sm text-muted-foreground">
                                                {formatDate(record.attendance_upload.shift_date)}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => viewUserRecords(record.user.id, record.record_date)}
                                                >
                                                    <Eye className="h-4 w-4 mr-1" />
                                                    View All
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </div>

                {/* Mobile Cards */}
                <div className="md:hidden space-y-4">
                    {recordsData.data.length === 0 ? (
                        <div className="py-12 text-center text-gray-500 border rounded-lg bg-card">
                            No biometric records found
                        </div>
                    ) : (
                        recordsData.data.map((record) => (
                            <div key={record.id} className="bg-card border rounded-lg p-4 shadow-sm space-y-3">
                                <div className="flex justify-between items-start">
                                    <div>
                                        <div className="text-lg font-semibold">{record.user.name}</div>
                                        <div className="text-sm text-muted-foreground">
                                            {formatDate(record.record_date)} at {formatTime(record.record_time)}
                                        </div>
                                    </div>
                                    <Badge variant="outline">{record.site.name}</Badge>
                                </div>
                                <div className="space-y-2 text-sm">
                                    <div>
                                        <span className="font-medium">Device:</span> {record.employee_name}
                                    </div>
                                    <div>
                                        <span className="font-medium">Upload:</span>{" "}
                                        <span className="text-muted-foreground">
                                            {formatDate(record.attendance_upload.shift_date)}
                                        </span>
                                    </div>
                                </div>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => viewUserRecords(record.user.id, record.record_date)}
                                    className="w-full"
                                >
                                    <Eye className="h-4 w-4 mr-2" />
                                    View All Scans
                                </Button>
                            </div>
                        ))
                    )}
                </div>

                <div className="flex justify-center mt-4">
                    {recordsData.links && recordsData.links.length > 0 && (
                        <PaginationNav links={recordsData.links} />
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
