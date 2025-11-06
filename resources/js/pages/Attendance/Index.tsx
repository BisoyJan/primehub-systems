import React, { useEffect, useState } from "react";
import { Head, router, usePage } from "@inertiajs/react";
import AppLayout from "@/layouts/app-layout";
import { useFlashMessage, usePageLoading, usePageMeta } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { LoadingOverlay } from "@/components/LoadingOverlay";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue
} from "@/components/ui/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import PaginationNav, { PaginationLink } from "@/components/pagination-nav";
import { Calendar, Eye } from "lucide-react";

interface AttendanceRecord {
    id: number;
    employee_name: string;
    site: string;
    shift?: string | null;
    status: string;
    attended_at: string;
}

interface Meta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface AttendancePayload {
    data: AttendanceRecord[];
    links: PaginationLink[];
    meta: Meta;
}

interface FilterOption {
    id: number;
    name: string;
}

interface Filters {
    sites: FilterOption[];
    statuses: string[];
    shifts: string[];
}

interface PageProps {
    attendances?: AttendancePayload;
    filters?: Filters;
    [key: string]: unknown;
}

const DEFAULT_META: Meta = {
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 0,
};

const DEFAULT_FILTERS: Filters = {
    sites: [],
    statuses: [],
    shifts: [],
};

const formatDateTime = (value: string) => {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return `${date.toLocaleDateString()} ${date.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}`;
};

export default function AttendanceIndex() {
    const { attendances, filters } = usePage<PageProps>().props;
    const attendanceData = {
        data: attendances?.data ?? [],
        links: attendances?.links ?? [],
        meta: attendances?.meta ?? DEFAULT_META,
    };
    const filterOptions = filters ?? DEFAULT_FILTERS;

    const { title, breadcrumbs } = usePageMeta({
        title: "Attendance",
        breadcrumbs: [{ title: "Attendance", href: "/attendance" }],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();

    const urlParams = new URLSearchParams(window.location.search);
    const [loading, setLoading] = useState(false);
    const [search, setSearch] = useState(urlParams.get("search") || "");
    const [debouncedSearch, setDebouncedSearch] = useState(search);
    const [siteFilter, setSiteFilter] = useState(urlParams.get("site") || "all");
    const [statusFilter, setStatusFilter] = useState(urlParams.get("status") || "all");
    const [shiftFilter, setShiftFilter] = useState(urlParams.get("shift") || "all");
    const [dateFilter, setDateFilter] = useState(urlParams.get("date") || "");

    useEffect(() => {
        const timer = setTimeout(() => setDebouncedSearch(search), 500);
        return () => clearTimeout(timer);
    }, [search]);

    const isInitialMount = React.useRef(true);

    useEffect(() => {
        if (isInitialMount.current) {
            isInitialMount.current = false;
            return;
        }

        const params: Record<string, string> = {};
        if (debouncedSearch) params.search = debouncedSearch;
        if (siteFilter !== "all") params.site = siteFilter;
        if (statusFilter !== "all") params.status = statusFilter;
        if (shiftFilter !== "all") params.shift = shiftFilter;
        if (dateFilter) params.date = dateFilter;

        setLoading(true);
        router.get("/attendance", params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => setLoading(false),
        });
    }, [debouncedSearch, siteFilter, statusFilter, shiftFilter, dateFilter]);

    const showClearFilters =
        siteFilter !== "all" ||
        statusFilter !== "all" ||
        shiftFilter !== "all" ||
        Boolean(dateFilter) ||
        Boolean(search);

    const clearFilters = () => {
        setSearch("");
        setSiteFilter("all");
        setStatusFilter("all");
        setShiftFilter("all");
        setDateFilter("");
    };

    const handleViewRecord = (attendanceId: number) => {
        router.get(`/attendance/${attendanceId}`);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isPageLoading || loading} />

                <PageHeader
                    title="Attendance Management"
                    description="Review attendance records and manage daily logs"
                />

                <div className="flex flex-col gap-3">
                    <div className="w-full">
                        <Input
                            type="search"
                            placeholder="Search employee, site, or status..."
                            value={search}
                            onChange={event => setSearch(event.target.value)}
                            className="w-full"
                        />
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                        <Select value={siteFilter} onValueChange={setSiteFilter}>
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Filter by Site" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Sites</SelectItem>
                                {filterOptions.sites.map(site => (
                                    <SelectItem key={site.id} value={String(site.id)}>
                                        {site.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select value={statusFilter} onValueChange={setStatusFilter}>
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Filter by Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Statuses</SelectItem>
                                {filterOptions.statuses.map(status => (
                                    <SelectItem key={status} value={status}>
                                        {status}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select value={shiftFilter} onValueChange={setShiftFilter}>
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Filter by Shift" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Shifts</SelectItem>
                                {filterOptions.shifts.map(shift => (
                                    <SelectItem key={shift} value={shift}>
                                        {shift}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <div className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                            <Calendar className="h-4 w-4 text-muted-foreground" />
                            <input
                                type="date"
                                value={dateFilter}
                                onChange={event => setDateFilter(event.target.value)}
                                className="w-full bg-transparent outline-none"
                            />
                        </div>

                        {showClearFilters && (
                            <Button variant="outline" onClick={clearFilters} className="w-full">
                                Clear Filters
                            </Button>
                        )}
                    </div>

                    <div className="flex flex-col sm:flex-row sm:flex-wrap gap-3 sm:justify-between min-w-0">
                        <div className="flex flex-col sm:flex-row gap-3">
                            <Button onClick={() => router.get("/attendance/create")} className="w-full sm:w-auto">
                                Add Attendance
                            </Button>
                            <Button
                                onClick={() => router.get("/attendance/import")}
                                className="w-full sm:w-auto"
                                variant="outline"
                            >
                                Import Attendance File (.txt)
                            </Button>
                        </div>
                    </div>
                </div>

                <div className="flex justify-between items-center text-sm">
                    <div className="text-muted-foreground">
                        Showing {attendanceData.data.length} of {attendanceData.meta.total} record
                        {attendanceData.meta.total === 1 ? "" : "s"}
                        {showClearFilters ? " (filtered)" : ""}
                    </div>
                </div>

                <div className="hidden md:block shadow rounded-md overflow-hidden">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>ID</TableHead>
                                    <TableHead>Employee</TableHead>
                                    <TableHead>Site</TableHead>
                                    <TableHead className="hidden lg:table-cell">Shift</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="hidden xl:table-cell">Date</TableHead>
                                    <TableHead>Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {attendanceData.data.map(record => (
                                    <TableRow key={record.id}>
                                        <TableCell>{record.id}</TableCell>
                                        <TableCell className="font-medium">{record.employee_name}</TableCell>
                                        <TableCell>{record.site}</TableCell>
                                        <TableCell className="hidden lg:table-cell">{record.shift || "-"}</TableCell>
                                        <TableCell>
                                            <Badge variant="secondary">{record.status}</Badge>
                                        </TableCell>
                                        <TableCell className="hidden xl:table-cell">{formatDateTime(record.attended_at)}</TableCell>
                                        <TableCell>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => handleViewRecord(record.id)}
                                            >
                                                <Eye className="mr-2 h-4 w-4" />
                                                View
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {attendanceData.data.length === 0 && !loading && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="h-24 text-center text-muted-foreground">
                                            No attendance records found
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </div>

                <div className="md:hidden space-y-4">
                    {attendanceData.data.map(record => (
                        <div key={record.id} className="bg-card border rounded-lg p-4 shadow-sm space-y-3">
                            <div className="flex justify-between items-start">
                                <div>
                                    <div className="text-sm text-muted-foreground">#{record.id}</div>
                                    <div className="text-lg font-semibold">{record.employee_name}</div>
                                </div>
                                <Badge variant="secondary">{record.status}</Badge>
                            </div>

                            <div className="space-y-2 text-sm">
                                <div>
                                    <span className="font-medium">Site:</span> {record.site}
                                </div>
                                <div>
                                    <span className="font-medium">Shift:</span> {record.shift || "-"}
                                </div>
                                <div>
                                    <span className="font-medium">Date:</span> {formatDateTime(record.attended_at)}
                                </div>
                            </div>

                            <div className="flex gap-2 pt-2 border-t">
                                <Button variant="outline" size="sm" className="flex-1" onClick={() => handleViewRecord(record.id)}>
                                    <Eye className="mr-2 h-4 w-4" />
                                    View Details
                                </Button>
                            </div>
                        </div>
                    ))}

                    {attendanceData.data.length === 0 && !loading && (
                        <div className="py-12 text-center text-gray-500 border rounded-lg bg-card">
                            No attendance records found
                        </div>
                    )}
                </div>

                <div className="flex justify-center mt-4">
                    {attendanceData.links && attendanceData.links.length > 0 && (
                        <PaginationNav links={attendanceData.links} only={["attendances"]} />
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
